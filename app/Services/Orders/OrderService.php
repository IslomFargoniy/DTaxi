<?php

namespace App\Services\Orders;

use App\Enums\DriverStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\Driver;
use App\Models\Landmark;
use App\Models\Order;
use App\Models\TariffClass;
use App\Models\User;
use App\Services\Dispatch\Dispatcher;
use App\Services\Dispatch\DriverDiscipline;
use App\Services\Drivers\DriverBalance;
use App\Services\Pricing\FareCalculator;
use App\Services\Routing\Router;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Buyurtma hayot sikli (reja §3): yaratish → efir → tayinlandi → yetib keldi → yo'lda → yakunlandi / bekor.
 */
class OrderService
{
    public function __construct(
        private Dispatcher $dispatcher,
        private FareCalculator $fares,
        private Router $router,
        private DriverBalance $balance,
        private DriverDiscipline $discipline,
    ) {}

    /**
     * @param  array{
     *     branch_id: int,
     *     phone: string,
     *     client_name?: string|null,
     *     tariff_class_id?: int|null,
     *     pickup_text: string,
     *     pickup_lat: float,
     *     pickup_lng: float,
     *     pickup_landmark_id?: int|null,
     *     dropoff_text?: string|null,
     *     dropoff_lat?: float|null,
     *     dropoff_lng?: float|null,
     *     comment?: string|null,
     *     delivery?: array<string, string|null>|null,
     *     source?: OrderSource|null,
     *     phone_number_id?: int|null,
     *     scheduled_at?: \DateTimeInterface|null,
     * }  $data
     */
    public function create(array $data, ?User $operator = null, bool $dispatch = true): Order
    {
        $order = DB::transaction(function () use ($data, $operator) {
            $branch = Branch::query()->findOrFail($data['branch_id']);
            $tariffClass = $this->tariffClass($branch, $data['tariff_class_id'] ?? null);
            $tariff = $tariffClass->tariff()->withoutGlobalScopes()->firstOrFail();

            $client = Client::query()->firstOrCreate(['phone' => Client::normalizePhone($data['phone'])]);
            if (! empty($data['client_name']) && ! $client->name) {
                $client->name = $data['client_name'];
            }
            $client->last_branch_id = $branch->id;
            $client->save();

            $pickup = Point::makeGeodetic($data['pickup_lat'], $data['pickup_lng']);
            $dropoff = isset($data['dropoff_lat'], $data['dropoff_lng']) ? Point::makeGeodetic($data['dropoff_lat'], $data['dropoff_lng']) : null;

            $estimate = null;
            $distance = null;
            if ($dropoff) {
                $route = $this->router->route($pickup, $dropoff);
                $distance = $route->distanceMeters;
                $estimate = $this->fares->calculate($tariff->snapshot(), $distance, startedAt: CarbonImmutable::now($branch->timezone))->total;
            }

            $order = Order::query()->create([
                'branch_id' => $branch->id,
                'client_id' => $client->id,
                'operator_id' => $operator?->id,
                'tariff_class_id' => $tariffClass->id,
                'status' => OrderStatus::New,
                'source' => $data['source'] ?? OrderSource::Phone,
                'phone_number_id' => $data['phone_number_id'] ?? null,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'pickup_text' => $data['pickup_text'],
                'pickup_location' => $pickup,
                'pickup_landmark_id' => $data['pickup_landmark_id'] ?? null,
                'dropoff_text' => $data['dropoff_text'] ?? null,
                'dropoff_location' => $dropoff,
                'comment' => $data['comment'] ?? null,
                'tariff_snapshot' => $tariff->snapshot(),
                'price_estimate' => $estimate,
                'distance_m' => $distance,
            ]);

            if ($tariffClass->is_delivery && ! empty($data['delivery'])) {
                $order->delivery()->create($data['delivery']);
            }

            $this->rememberAddress($client, $data['pickup_text'], $pickup, $data['pickup_landmark_id'] ?? null);
            if (! empty($data['pickup_landmark_id'])) {
                Landmark::query()->whereKey($data['pickup_landmark_id'])->increment('usage_count');
            }

            OrderEvents::record($order, 'created', $operator, broadcast: false);

            return $order;
        });

        if ($order->scheduled_at && $order->scheduled_at->isAfter(now()->addMinutes($this->preorderLead($order)))) {
            // Oldindan buyurtma: vaqti yaqinlashganda `orders:dispatch-scheduled` efirga chiqaradi
            OrderEvents::record($order, 'scheduled', $operator, ['at' => $order->scheduled_at->toIso8601String()]);
        } elseif ($dispatch) {
            $this->dispatcher->startSearch($order);
        }

        return $order;
    }

    public function arrive(Order $order, Driver $driver): void
    {
        $this->ensureDriverOrder($order, $driver, [OrderStatus::Assigned]);

        $order->forceFill(['status' => OrderStatus::Arrived, 'arrived_at' => now()])->save();
        OrderEvents::record($order, 'arrived', $driver->user);
    }

    public function start(Order $order, Driver $driver): void
    {
        $this->ensureDriverOrder($order, $driver, [OrderStatus::Assigned, OrderStatus::Arrived]);

        $order->forceFill([
            'status' => OrderStatus::InProgress,
            'started_at' => now(),
            // Kutish: "Yetib keldim" dan "Yo'lovchi o'tirdi" gacha (qaror #33)
            'waiting_s' => $order->arrived_at ? (int) $order->arrived_at->diffInSeconds(now()) : 0,
        ])->save();
        OrderEvents::record($order, 'started', $driver->user);
    }

    /**
     * Taksometr natijasi bilan yakunlash. Masofani haydovchi ilovasi hisoblaydi (internet uzilsa ham),
     * server esa GPS treki bo'yicha tekshiradi — katta farq bo'lsa tarixda belgi qoladi.
     */
    public function complete(Order $order, Driver $driver, int $insideMeters, int $outsideMeters = 0): Order
    {
        $this->ensureDriverOrder($order, $driver, [OrderStatus::InProgress]);

        $branch = $order->branch()->firstOrFail();
        $startedAt = CarbonImmutable::parse($order->started_at ?? now())->setTimezone($branch->timezone);
        $fare = $this->fares->calculate($order->tariff_snapshot, $insideMeters, $outsideMeters, $order->waiting_s ?? 0, $startedAt);
        $trackMeters = $this->trackLength($driver, $order);

        DB::transaction(function () use ($order, $driver, $fare, $insideMeters, $outsideMeters) {
            $order->forceFill([
                'status' => OrderStatus::Completed,
                'completed_at' => now(),
                'price_final' => $fare->total,
                'price_breakdown' => $fare->breakdown,
                'distance_m' => $insideMeters + $outsideMeters,
                'duration_s' => $order->started_at ? (int) $order->started_at->diffInSeconds(now()) : null,
            ])->save();

            $this->balance->chargeCommission($order);
            $driver->forceFill(['status' => DriverStatus::Free])->save();
            $order->client()->increment('orders_count');
        });

        $this->discipline->completed($driver);

        $reported = $insideMeters + $outsideMeters;
        OrderEvents::record($order, 'completed', $driver->user, [
            'price' => $fare->total,
            'reported_m' => $reported,
            'track_m' => $trackMeters,
            'suspicious' => $trackMeters !== null && $reported > 0 && abs($reported - $trackMeters) / $reported > 0.25,
        ]);

        return $order;
    }

    /**
     * Vaqti yaqinlashgan oldindan buyurtmalarni efirga chiqarish (har daqiqa).
     */
    public function dispatchScheduled(): int
    {
        $count = 0;
        Order::query()->withoutGlobalScopes()
            ->where('status', OrderStatus::New)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now()->addMinutes(120))
            ->get()
            ->filter(fn (Order $order): bool => $order->scheduled_at !== null && $order->scheduled_at->lte(now()->addMinutes($this->preorderLead($order))))
            ->each(function (Order $order) use (&$count) {
                $this->dispatcher->startSearch($order);
                $count++;
            });

        return $count;
    }

    private function preorderLead(Order $order): int
    {
        return (int) app(Settings::class)->get('dispatch.preorder_lead_minutes', $order->branch_id);
    }

    public function cancelByOperator(Order $order, User $operator, string $reason, bool $noDriverFound = false): void
    {
        $this->cancel($order, $noDriverFound ? OrderStatus::NoDriverFound : OrderStatus::CancelledByOperator, $reason, $operator);
    }

    public function cancelByClient(Order $order, ?User $operator, string $reason): void
    {
        $this->cancel($order, OrderStatus::CancelledByClient, $reason, $operator);
    }

    /**
     * Haydovchi bekor qildi: jarima (uzrsiz bo'lsa) va buyurtma qayta efirga chiqadi — mijoz mashinasiz qolmaydi.
     */
    public function cancelByDriver(Order $order, Driver $driver, string $reason, bool $excused = false): void
    {
        $this->ensureDriverOrder($order, $driver, [OrderStatus::Assigned, OrderStatus::Arrived]);

        $driver->forceFill(['status' => DriverStatus::Free])->save();
        $this->discipline->cancelled($driver, $order, $reason, $excused);
        OrderEvents::record($order, 'driver_cancelled', $driver->user, ['reason' => $reason], broadcast: false);

        $this->dispatcher->restartSearch($order);
    }

    private function cancel(Order $order, OrderStatus $status, string $reason, ?User $actor): void
    {
        if ($order->status->isFinished()) {
            throw new InvalidArgumentException('Buyurtma allaqachon yakunlangan.');
        }

        DB::transaction(function () use ($order, $status, $reason) {
            if ($order->driver_id) {
                Driver::query()->withoutGlobalScopes()->whereKey($order->driver_id)
                    ->update(['status' => DriverStatus::Free->value]);
            }

            $order->forceFill(['status' => $status, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
        });

        $this->dispatcher->withdrawOtherOffers($order, null, 'cancelled');
        OrderEvents::record($order, $status->value, $actor, ['reason' => $reason]);
    }

    /**
     * @param  list<OrderStatus>  $allowed
     */
    private function ensureDriverOrder(Order $order, Driver $driver, array $allowed): void
    {
        if ($order->driver_id !== $driver->id || ! in_array($order->status, $allowed, true)) {
            throw new InvalidArgumentException('Bu amal hozir mumkin emas.');
        }
    }

    private function tariffClass(Branch $branch, ?int $tariffClassId): TariffClass
    {
        $query = TariffClass::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('is_active', true);

        return $tariffClassId
            ? $query->findOrFail($tariffClassId)
            : $query->orderByDesc('is_default')->orderBy('sort')->firstOrFail();
    }

    private function rememberAddress(Client $client, string $text, Point $location, ?int $landmarkId): void
    {
        $address = ClientAddress::query()->firstOrNew(['client_id' => $client->id, 'text' => $text]);
        $address->fill([
            'location' => $location,
            'landmark_id' => $landmarkId,
            'usage_count' => $address->usage_count + 1,
            'last_used_at' => now(),
        ])->save();
    }

    /**
     * Server tomonidagi tekshiruv: yo'lda (started_at dan hozirgacha) GPS nuqtalari bo'yicha masofa.
     */
    private function trackLength(Driver $driver, Order $order): ?int
    {
        if (! $order->started_at) {
            return null;
        }

        $length = DB::scalar(<<<'SQL'
            SELECT ST_Length(ST_MakeLine(location::geometry ORDER BY recorded_at)::geography)
            FROM driver_locations
            WHERE driver_id = ? AND recorded_at BETWEEN ? AND ?
            SQL, [$driver->id, $order->started_at, now()]);

        return $length === null ? null : (int) round((float) $length);
    }
}
