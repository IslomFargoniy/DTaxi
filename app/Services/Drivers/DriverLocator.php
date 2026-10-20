<?php

namespace App\Services\Drivers;

use App\Enums\DriverStatus;
use App\Events\DriverLocationUpdated;
use App\Models\Driver;
use App\Models\DriverLocation;
use App\Models\Order;
use App\Support\Settings;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Haydovchilar joylashuvi PostGIS'da (drivers.last_location + GIST indeks).
 * Tig'iz soatda ~18 yangilanish/soniya — PostgreSQL uchun yengil; holat va joylashuv bitta tranzaksiyada.
 */
class DriverLocator
{
    public function __construct(private Settings $settings) {}

    public function update(Driver $driver, float $lat, float $lng, ?int $speed = null, ?int $heading = null, ?int $accuracy = null): void
    {
        $this->record($driver, [['lat' => $lat, 'lng' => $lng, 'speed' => $speed, 'heading' => $heading, 'accuracy' => $accuracy]]);
    }

    /**
     * Nuqtalar to'plami (internet uzilganda yig'ilganlari ham). Har nuqta o'z vaqti bilan yoziladi —
     * aks holda trek tartibi buziladi va eski nuqta "hozirgi joy" bo'lib qoladi.
     *
     * @param  list<array{lat: float, lng: float, speed?: int|null, heading?: int|null, accuracy?: int|null, t?: int|null}>  $points
     */
    public function record(Driver $driver, array $points): void
    {
        if ($points === []) {
            return;
        }

        $now = now();
        $rows = [];
        $latest = null;
        foreach ($points as $point) {
            $at = isset($point['t']) ? Carbon::createFromTimestampMs($point['t']) : $now;
            // Telefon soati noto'g'ri bo'lsa — server vaqti
            if ($at->isAfter($now->copy()->addMinute()) || $at->isBefore($now->copy()->subHours(3))) {
                $at = $now;
            }

            $rows[] = [
                'driver_id' => $driver->id,
                'location' => Point::makeGeodetic($point['lat'], $point['lng']),
                'speed' => $point['speed'] ?? null,
                'heading' => $point['heading'] ?? null,
                'accuracy' => $point['accuracy'] ?? null,
                'recorded_at' => $at,
            ];

            if ($latest === null || $at->gte($latest['recorded_at'])) {
                $latest = end($rows);
            }
        }

        foreach ($rows as $row) {
            DriverLocation::query()->create($row);
        }

        // Kechikib kelgan eski nuqtalar hozirgi joyni orqaga surmaydi (telefon soati bir daqiqagacha farq qilishi mumkin)
        if (($driver->last_seen_at === null || $latest['recorded_at']->gte($driver->last_seen_at->copy()->subMinute()))) {
            $live = $latest['recorded_at']->gte($now->copy()->subMinute());
            $driver->forceFill(['last_location' => $latest['location'], 'last_seen_at' => $live ? $now : $latest['recorded_at']])->save();
            DriverLocationUpdated::dispatch($driver);
            app(StandQueue::class)->refresh($driver);
        }
    }

    /**
     * Buyurtmani olishi mumkin bo'lgan bo'sh haydovchilar, yaqinidan uzog'iga (qaror #28, reja §6).
     *
     * @param  list<int>  $excludeIds
     * @return Collection<int, array{driver: Driver, distance_m: int}>
     */
    public function candidatesFor(Order $order, int $radiusMeters, array $excludeIds = []): Collection
    {
        $pickup = sprintf('SRID=4326;POINT(%F %F)', $order->pickup_location->getLongitude(), $order->pickup_location->getLatitude());
        $ttl = (int) $this->settings->get('dispatch.location_ttl_seconds', $order->branch_id);
        $minBalance = (int) $this->settings->get('drivers.min_balance', $order->branch_id);

        $rows = DB::table('drivers')
            ->join('vehicles', fn ($join) => $join->on('vehicles.driver_id', '=', 'drivers.id')
                ->where('vehicles.is_current', true)->where('vehicles.is_approved', true))
            ->join('vehicle_tariff_class', fn ($join) => $join->on('vehicle_tariff_class.vehicle_id', '=', 'vehicles.id')
                ->where('vehicle_tariff_class.tariff_class_id', $order->tariff_class_id))
            ->where('drivers.branch_id', $order->branch_id)
            ->where('drivers.status', DriverStatus::Free->value)
            ->where('drivers.is_approved', true)
            ->where(fn ($q) => $q->whereNull('drivers.blocked_until')->orWhere('drivers.blocked_until', '<', now()))
            ->where('drivers.balance', '>=', $minBalance)
            ->where('drivers.last_seen_at', '>=', now()->subSeconds($ttl))
            ->whereRaw('ST_DWithin(drivers.last_location, ST_GeogFromText(?), ?)', [$pickup, $radiusMeters])
            ->when($excludeIds !== [], fn ($q) => $q->whereNotIn('drivers.id', $excludeIds))
            ->selectRaw('drivers.id, ST_Distance(drivers.last_location, ST_GeogFromText(?)) AS distance_m', [$pickup])
            ->orderBy('distance_m')
            ->get();

        $drivers = Driver::query()->withoutGlobalScopes()->findMany($rows->pluck('id'))->keyBy('id');

        // So'rovlar orasida o'chirilgan haydovchi bo'lsa — tashlab ketiladi
        $candidates = collect();
        foreach ($rows as $row) {
            $driver = $drivers->get($row->id);
            if ($driver instanceof Driver) {
                $candidates->push(['driver' => $driver, 'distance_m' => (int) round((float) $row->distance_m)]);
            }
        }

        return $candidates;
    }
}
