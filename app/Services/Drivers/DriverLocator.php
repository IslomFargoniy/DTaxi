<?php

namespace App\Services\Drivers;

use App\Enums\DriverStatus;
use App\Events\DriverLocationUpdated;
use App\Models\Driver;
use App\Models\DriverLocation;
use App\Models\Order;
use App\Support\Settings;
use Clickbar\Magellan\Data\Geometries\Point;
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
        $point = Point::makeGeodetic($lat, $lng);

        $driver->forceFill(['last_location' => $point, 'last_seen_at' => now()])->save();

        DriverLocation::query()->create([
            'driver_id' => $driver->id,
            'location' => $point,
            'speed' => $speed,
            'heading' => $heading,
            'accuracy' => $accuracy,
            'recorded_at' => now(),
        ]);

        DriverLocationUpdated::dispatch($driver);
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
