<?php

namespace App\Services\Orders;

use App\Enums\DriverStatus;
use App\Enums\OfferResponse;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Landmark;
use App\Models\Order;
use App\Models\OrderOffer;
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
        private RideTrack $track,
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
     *     stops?: list<array{text: string, lat: float, lng: float}>,
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

            $client = Client::query()->firstOrCreate(['phone' => Client::normalizePhone($data['phone'])]);
            if (! empty($data['client_name']) && ! $client->name) {
                $client->name = $data['client_name'];
            }
            $client->last_branch_id = $branch->id;
            $client->save();

            $company = $client->company_id ? Company::query()->withoutGlobalScopes()->whereKey($client->company_id)->where('is_active', true)->first() : null;
            $snapshot = $this->snapshot($tariffClass, $branch, $client, $company);

            $pickup = Point::makeGeodetic($data['pickup_lat'], $data['pickup_lng']);
            $dropoff = isset($data['dropoff_lat'], $data['dropoff_lng']) ? Point::makeGeodetic($data['dropoff_lat'], $data['dropoff_lng']) : null;

            // Operator nomsiz nuqta qo'ygan bo'lsa — yaqin manzil nomi (haydovchi "Xaritadagi nuqta" ko'rmasin)
            $data['pickup_text'] = $this->placeName($data['pickup_text'], $pickup);
            if ($dropoff) {
                $data['dropoff_text'] = $this->placeName((string) ($data['dropoff_text'] ?? ''), $dropoff);
            }

            $stops = $data['stops'] ?? [];
            $distance = $this->routeDistance($pickup, $stops, $dropoff);
            $estimate = $distance === null ? null : $this->fares->calculate($snapshot, $distance, startedAt: CarbonImmutable::now($branch->timezone))->total;

            $order = Order::query()->create([
                'branch_id' => $branch->id,
                'client_id' => $client->id,
                'company_id' => $company?->id,
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
                'stops' => $stops === [] ? null : $stops,
                'comment' => $data['comment'] ?? null,
                'tariff_snapshot' => $snapshot,
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

    /**
     * Mijoz ilovasi (qaror #71): har faol tarif klassi bo'yicha taxminiy narx — buyurtma yaratishdagi hisob bilan bir xil
     * (tig'iz soat, chegirma). "Qayerga" bo'lmasa narx yo'q, faqat eng kami (boshlang'ich narx).
     *
     * @param  list<array{text: string, lat: float, lng: float}>  $stops
     * @return list<array{tariff_class_id: int, name: string, is_delivery: bool, price: int|null, min_price: int}>
     */
    public function quote(Branch $branch, Client $client, Point $pickup, array $stops, ?Point $dropoff): array
    {
        $distance = $this->routeDistance($pickup, $stops, $dropoff);
        $company = $client->company_id ? Company::query()->withoutGlobalScopes()->whereKey($client->company_id)->where('is_active', true)->first() : null;
        $now = CarbonImmutable::now($branch->timezone);

        return array_values(TariffClass::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('is_active', true)
            ->orderByDesc('is_default')->orderBy('sort')->get()
            ->map(function (TariffClass $class) use ($branch, $client, $company, $distance, $now) {
                $snapshot = $this->snapshot($class, $branch, $client, $company);

                return [
                    'tariff_class_id' => $class->id,
                    'name' => $class->name,
                    'is_delivery' => (bool) $class->is_delivery,
                    'price' => $distance === null ? null : $this->fares->calculate($snapshot, $distance, startedAt: $now)->total,
                    'min_price' => $this->fares->calculate($snapshot, 0, startedAt: $now)->total,
                ];
            })->all());
    }

    public function arrive(Order $order, Driver $driver): void
    {
        $this->transition($order, $driver, [OrderStatus::Assigned], ['status' => OrderStatus::Arrived, 'arrived_at' => now()]);
        OrderEvents::record($order, 'arrived', $driver->user);
    }

    /**
     * @param  Point|null  $at  ilova yuborgan joy; bo'lmasa — haydovchining oxirgi GPS nuqtasi
     */
    public function start(Order $order, Driver $driver, ?Point $at = null): void
    {
        $this->transition($order, $driver, [OrderStatus::Assigned, OrderStatus::Arrived], [
            'status' => OrderStatus::InProgress,
            'started_at' => now(),
            'start_location' => $this->positionOf($driver, $at),
            // Kutish: "Yetib keldim" dan "Yo'lovchi o'tirdi" gacha (qaror #33)
            'waiting_s' => $order->arrived_at ? (int) $order->arrived_at->diffInSeconds(now()) : 0,
        ]);
        $this->rememberPlaceName($order, 'start');
        OrderEvents::record($order, 'started', $driver->user);
    }

    /**
     * Taksometr natijasi bilan yakunlash. Masofani haydovchi ilovasi hisoblaydi (internet uzilsa ham),
     * server esa GPS treki bo'yicha tekshiradi: trek ancha uzun bo'lsa komissiya trek bo'yicha olinadi.
     * Ilova internet uzilganda qayta yuborishi mumkin — takroriy so'rov ikkinchi marta hisoblanmaydi.
     */
    public function complete(Order $order, Driver $driver, int $insideMeters, int $outsideMeters = 0, ?Point $at = null): Order
    {
        if ($order->status === OrderStatus::Completed && $order->driver_id === $driver->id) {
            return $order;
        }

        $branch = $order->branch()->firstOrFail();
        $startedAt = CarbonImmutable::parse($order->started_at ?? now())->setTimezone($branch->timezone);
        $fare = $this->fares->calculate($order->tariff_snapshot, $insideMeters, $outsideMeters, $order->waiting_s ?? 0, $startedAt);
        $trackMeters = $this->trackLength($driver, $order);
        $reported = $insideMeters + $outsideMeters;

        // Ilova kamaytirib yuborsa (masalan 0) — komissiya GPS treki bo'yicha (mijoz narxi o'zgarmaydi)
        $underReported = $trackMeters !== null && $trackMeters >= 500 && $trackMeters > $reported * 1.3;
        $commissionBase = $underReported
            ? max($fare->total, $this->fares->calculate($order->tariff_snapshot, max(0, $trackMeters - $outsideMeters), $outsideMeters, $order->waiting_s ?? 0, $startedAt)->total)
            : null;

        $bonus = (int) $order->price_bonus;
        $total = $fare->total + $bonus;
        $breakdown = $bonus > 0 ? [...$fare->breakdown, 'bonus' => $bonus] : $fare->breakdown;
        $commissionBase = $commissionBase !== null ? $commissionBase + $bonus : null;

        try {
            DB::transaction(function () use ($order, $driver, $total, $breakdown, $reported, $commissionBase, $at) {
                $this->transition($order, $driver, [OrderStatus::InProgress], [
                    'status' => OrderStatus::Completed,
                    'completed_at' => now(),
                    'finish_location' => $this->positionOf($driver, $at),
                    'price_final' => $total,
                    'price_breakdown' => $breakdown,
                    'distance_m' => $reported,
                    'duration_s' => $order->started_at ? (int) $order->started_at->diffInSeconds(now()) : null,
                ]);

                $this->balance->chargeCommission($order, $commissionBase);
                // Korporativ: mijoz naqd to'lamaydi — firma haydovchiga balans orqali to'laydi, kompaniyaga hisob-faktura
                if ($order->company_id) {
                    $this->balance->corporatePayout($order);
                }
                $driver->forceFill(['status' => DriverStatus::Free])->save();
                $order->client()->increment('orders_count');
            });
        } catch (InvalidArgumentException $e) {
            // Parallel takroriy so'rov (ilova qayta yubordi) birinchisidan keyin keldi — xato emas (qaror #51)
            $order->refresh();
            if ($order->status === OrderStatus::Completed && $order->driver_id === $driver->id) {
                return $order;
            }

            throw $e;
        }

        $this->rememberPlaceName($order, 'finish');

        $this->discipline->completed($driver);

        OrderEvents::record($order, 'completed', $driver->user, [
            'price' => $total,
            'reported_m' => $reported,
            'track_m' => $trackMeters,
            'commission_base' => $commissionBase,
            'suspicious' => $trackMeters !== null && max($reported, $trackMeters) >= 500
                && abs($reported - $trackMeters) / max($reported, $trackMeters) > 0.25,
        ]);

        return $order;
    }

    /**
     * Operator buyurtmani tuzatadi (mijoz qayta qo'ng'iroq qildi). Manzil va tarif faqat haydovchi topilmaguncha;
     * izoh, qayerga va ustama — haydovchi yo'lga chiqquncha ham. Haydovchiga OrderUpdated orqali boradi.
     *
     * @param  array{comment?: string|null, dropoff_text?: string|null, dropoff_lat?: float|null, dropoff_lng?: float|null, tariff_class_id?: int|null, price_bonus?: int|null, pickup_text?: string|null, pickup_lat?: float|null, pickup_lng?: float|null, stops?: list<array{text: string, lat: float, lng: float}>}  $data
     */
    public function update(Order $order, array $data, User $operator): Order
    {
        [$tariffChanged, $dirty] = DB::transaction(fn () => $this->applyEdit($order, $data));
        OrderEvents::record($order, 'edited', $operator, ['fields' => $dirty]);

        // Yangi tarif yoki joy — boshqa haydovchilar mos keladi, ustama — ko'proq haydovchi qiziqadi
        if ($order->status === OrderStatus::Searching && ($tariffChanged || in_array('pickup_location', $dirty, true) || in_array('price_bonus', $dirty, true))) {
            $this->redispatch($order, $operator);
        }

        return $order;
    }

    /**
     * Qator qulflanib holat qayta o'qiladi: operator ekrani eskirgan bo'lsa ham, shu soniyada haydovchi qabul qilgan
     * buyurtmaning tarifi va olish joyi o'zgarmaydi (qabul qilish UPDATE'i qulf bo'shaguncha kutadi).
     *
     * @param  array{comment?: string|null, dropoff_text?: string|null, dropoff_lat?: float|null, dropoff_lng?: float|null, tariff_class_id?: int|null, price_bonus?: int|null, pickup_text?: string|null, pickup_lat?: float|null, pickup_lng?: float|null, stops?: list<array{text: string, lat: float, lng: float}>}  $data
     * @return array{bool, list<string>} tarif o'zgardimi va o'zgargan ustunlar
     */
    private function applyEdit(Order $order, array $data): array
    {
        $locked = Order::query()->withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        $order->setRawAttributes($locked->getAttributes(), true);

        $editable = [OrderStatus::New, OrderStatus::Searching, OrderStatus::Assigned, OrderStatus::Arrived];
        if (! in_array($order->status, $editable, true)) {
            throw new InvalidArgumentException(__('The order can no longer be changed.'));
        }

        $waiting = in_array($order->status, [OrderStatus::New, OrderStatus::Searching], true);
        $changes = [];

        if (array_key_exists('comment', $data)) {
            $changes['comment'] = $data['comment'];
        }
        if (array_key_exists('price_bonus', $data)) {
            $changes['price_bonus'] = $data['price_bonus'] ?: null;
        }
        if (array_key_exists('dropoff_text', $data)) {
            $changes['dropoff_text'] = $data['dropoff_text'];
            $changes['dropoff_location'] = isset($data['dropoff_lat'], $data['dropoff_lng'])
                ? Point::makeGeodetic($data['dropoff_lat'], $data['dropoff_lng'])
                : null;
        }
        if (array_key_exists('stops', $data)) {
            $changes['stops'] = $data['stops'] === [] ? null : $data['stops'];
        }
        if ($waiting && ! empty($data['pickup_text']) && isset($data['pickup_lat'], $data['pickup_lng'])) {
            $changes['pickup_text'] = $data['pickup_text'];
            $changes['pickup_location'] = Point::makeGeodetic($data['pickup_lat'], $data['pickup_lng']);
        }
        $tariffChanged = $waiting && ! empty($data['tariff_class_id']) && $data['tariff_class_id'] !== $order->tariff_class_id;
        if ($tariffChanged) {
            $branch = $order->branch()->firstOrFail();
            $tariffClass = $this->tariffClass($branch, $data['tariff_class_id']);
            $changes['tariff_class_id'] = $tariffClass->id;
            $client = $order->client()->firstOrFail();
            $changes['tariff_snapshot'] = $this->snapshot($tariffClass, $branch, $client, $order->company()->withoutGlobalScopes()->first());
        }

        $order->forceFill($changes);

        // Taxminiy narx qayta hisoblanadi (manzil yoki tarif o'zgargan bo'lsa)
        if ($order->isDirty(['dropoff_location', 'pickup_location', 'tariff_snapshot', 'stops'])) {
            $branch ??= $order->branch()->firstOrFail();
            $order->distance_m = $this->routeDistance($order->pickup_location, $order->stops ?? [], $order->dropoff_location);
            $order->price_estimate = $order->distance_m === null ? null
                : $this->fares->calculate($order->tariff_snapshot, $order->distance_m, startedAt: CarbonImmutable::now($branch->timezone))->total;
        }

        $dirty = array_map('strval', array_keys($order->getDirty()));
        $order->save();

        return [$tariffChanged, $dirty];
    }

    /**
     * Mijoz bahosi (1–5): operator kiritadi yoki mijoz safardan keyingi qo'ng'iroqda tugma bosadi.
     * Birinchi baho haydovchi reytingiga ta'sir qiladi: 5 → +1, 4 → 0, 3 → −2, 2 → −5, 1 → −10.
     */
    public function rateByClient(Order $order, int $rating): void
    {
        $rating = max(1, min(5, $rating));
        // Shartli UPDATE: operator va mijoz qo'ng'irog'i bir vaqtda baholasa ham reytingga faqat bittasi ta'sir qiladi
        $first = Order::query()->withoutGlobalScopes()->whereKey($order->id)->whereNull('client_rating')
            ->update(['client_rating' => $rating]) === 1;
        if (! $first) {
            Order::query()->withoutGlobalScopes()->whereKey($order->id)->update(['client_rating' => $rating]);
        }
        $order->forceFill(['client_rating' => $rating])->syncOriginalAttribute('client_rating');

        if ($first && $order->driver_id) {
            $delta = [1 => -10, 2 => -5, 3 => -2, 4 => 0, 5 => 1][$rating];
            Driver::query()->withoutGlobalScopes()->whereKey($order->driver_id)
                ->update(['rating' => DB::raw('LEAST(100, GREATEST(0, rating + '.$delta.'))')]);
        }

        OrderEvents::record($order, 'rated', payload: ['rating' => $rating], broadcast: false);
    }

    /**
     * Qayta efir: eng kichik radiusdan, yangi raund bilan — oldin javob bermaganlar ham yana ko'radi.
     */
    public function redispatch(Order $order, User $operator): void
    {
        if (! in_array($order->status, [OrderStatus::New, OrderStatus::Searching], true)) {
            throw new InvalidArgumentException(__('The order is no longer waiting for a driver.'));
        }

        $this->dispatcher->withdrawOtherOffers($order, null, 'redispatch');
        OrderEvents::record($order, 'redispatched', $operator, broadcast: false);
        $this->dispatcher->startSearch($order);
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
                // Ro'yxat oldindan o'qilgan: shu orada bekor qilingan buyurtma efirga chiqmaydi (faqat "yangi" dan)
                if ($this->dispatcher->startSearch($order, [OrderStatus::New])) {
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Uzoq vaqt hech kim olmagan buyurtma cheksiz efirda turmaydi: "haydovchi topilmadi" (mijozga xabar odatdagidek ketadi).
     */
    public function expireUnanswered(): int
    {
        $count = 0;
        Order::query()->withoutGlobalScopes()
            ->where('status', OrderStatus::Searching->value)
            ->whereNotNull('search_exhausted_at')
            ->get()
            ->filter(function (Order $order): bool {
                $minutes = (int) app(Settings::class)->get('dispatch.rebroadcast_max_minutes', $order->branch_id);

                return $minutes > 0 && $order->searching_at !== null && $order->searching_at->lte(now()->subMinutes($minutes));
            })
            ->each(function (Order $order) use (&$count) {
                try {
                    $this->cancel($order, OrderStatus::NoDriverFound, __('No driver found'), null, [OrderStatus::Searching]);
                    $count++;
                } catch (InvalidArgumentException) {
                    // Shu orada olingan yoki bekor qilingan
                }
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
     * Shu buyurtma bu haydovchiga qayta taklif qilinmaydi.
     */
    public function cancelByDriver(Order $order, Driver $driver, string $reason, bool $excused = false): void
    {
        DB::transaction(function () use ($order, $driver) {
            // Holat shu UPDATE'da "qidirilmoqda" ga o'tadi: keyin operator bekor qilsa, efir uni qayta tiriltirmaydi
            $this->transition($order, $driver, [OrderStatus::Assigned, OrderStatus::Arrived], [
                'status' => OrderStatus::Searching,
                'driver_id' => null,
                'vehicle_id' => null,
                'assigned_at' => null,
                'arrived_at' => null,
                'searching_at' => now(),
            ]);
            $driver->forceFill(['status' => DriverStatus::Free])->save();
            OrderOffer::query()->where('order_id', $order->id)->where('driver_id', $driver->id)
                ->update(['response' => OfferResponse::Cancelled->value, 'responded_at' => now()]);
        });

        $this->discipline->cancelled($driver, $order, $reason, $excused);
        OrderEvents::record($order, 'driver_cancelled', $driver->user, ['reason' => $reason, 'driver_id' => $driver->id], broadcast: false);

        $this->dispatcher->restartSearch($order);
    }

    /**
     * Qator qulflanadi: shu paytda haydovchi qabul qilsa yoki yakunlasa ham holat va haydovchi to'g'ri o'qiladi
     * (aks holda haydovchi "band" bo'lib qolib ketishi yoki yakunlangan buyurtma bekor bo'lib qolishi mumkin).
     */
    /**
     * @param  list<OrderStatus>|null  $onlyFrom  faqat shu holatlardan (masalan avtomatik "topilmadi" — faqat qidirilayotgan)
     */
    private function cancel(Order $order, OrderStatus $status, string $reason, ?User $actor, ?array $onlyFrom = null): void
    {
        DB::transaction(function () use ($order, $status, $reason, $onlyFrom) {
            $locked = Order::query()->withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isFinished() || ($onlyFrom !== null && ! in_array($locked->status, $onlyFrom, true))) {
                throw new InvalidArgumentException(__('The order is already finished.'));
            }

            if ($locked->driver_id) {
                // Model orqali — holat hodisasi operator xaritasiga "bo'sh" deb yuboradi
                Driver::query()->withoutGlobalScopes()->find($locked->driver_id)
                    ?->forceFill(['status' => DriverStatus::Free])->save();
            }

            $locked->forceFill(['status' => $status, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            $order->setRawAttributes($locked->getAttributes(), true);
        });

        $this->dispatcher->withdrawOtherOffers($order, null, 'cancelled');
        OrderEvents::record($order, $status->value, $actor, ['reason' => $reason]);
    }

    /**
     * Shartli UPDATE: holat va haydovchi kutilgandek bo'lsagina o'zgaradi. Ikki parallel so'rovdan
     * (ikki marta "yakunlash", yoki operator bekor qilishi bilan bir vaqtda) faqat bittasi o'tadi.
     *
     * @param  list<OrderStatus>  $allowed
     * @param  array<string, mixed>  $attributes
     */
    private function transition(Order $order, Driver $driver, array $allowed, array $attributes): void
    {
        if (! OrderTransition::apply($order, $allowed, $attributes, fn ($q) => $q->where('driver_id', $driver->id))) {
            throw new InvalidArgumentException(__('This action is not possible now.'));
        }
    }

    /**
     * Haydovchining hozirgi joyi (EWKT/EWKB — geography ustuni o'zi o'qiydi): ilova yuborgan nuqta,
     * bo'lmasa drivers.last_location — so'rovda koordinata kelmasa ham tarixda joy qoladi.
     */
    private function positionOf(Driver $driver, ?Point $at): ?string
    {
        if ($at === null) {
            $raw = Driver::query()->withoutGlobalScopes()->whereKey($driver->id)->toBase()->value('last_location');

            return is_string($raw) ? $raw : null;
        }

        return sprintf('SRID=4326;POINT(%F %F)', $at->getLongitude(), $at->getLatitude());
    }

    /**
     * Safar boshlangan/tugagan joy nomi buyurtmaga bir marta yoziladi (tarix ro'yxatida har qator uchun qidirilmasin).
     *
     * @param  'start'|'finish'  $which
     */
    private function rememberPlaceName(Order $order, string $which): void
    {
        $point = $which === 'start' ? $order->start_location : $order->finish_location;
        if ($point === null) {
            return;
        }

        $column = $which.'_name';
        $name = Landmark::nameAt($point) ?? '';
        Order::query()->withoutGlobalScopes()->whereKey($order->id)->update([$column => $name]);
        $order->forceFill([$column => $name])->syncOriginalAttribute($column);
    }

    private function placeName(string $text, Point $point): string
    {
        $generic = trim($text) === '' || in_array(trim($text), ['Point on the map', 'Xaritadagi nuqta', 'Точка на карте'], true);

        return $generic ? (Landmark::nameAt($point) ?? (trim($text) ?: __('Point on the map'))) : $text;
    }

    /**
     * Qayerdan → to'xtashlar → qayerga, oraliqlar yig'indisi (metr). Hech qayer ko'rsatilmagan bo'lsa — null.
     *
     * @param  list<array{text: string, lat: float, lng: float}>  $stops
     */
    private function routeDistance(Point $pickup, array $stops, ?Point $dropoff): ?int
    {
        $points = [$pickup, ...array_map(fn (array $s) => Point::makeGeodetic($s['lat'], $s['lng']), $stops)];
        if ($dropoff) {
            $points[] = $dropoff;
        }
        if (count($points) < 2) {
            return null;
        }

        $total = 0;
        for ($i = 1; $i < count($points); $i++) {
            $total += $this->router->route($points[$i - 1], $points[$i])->distanceMeters;
        }

        return $total;
    }

    /**
     * Buyurtma narx sharti: tarif + yaratilgan paytdagi tig'iz soat koeffitsienti va mijoz chegirmasi.
     * Keyin tarif yoki sozlama o'zgarsa ham buyurtma o'z shartida qoladi (haydovchi ilovasi ham shuni oladi).
     *
     * @return array<string, mixed>
     */
    private function snapshot(TariffClass $tariffClass, Branch $branch, Client $client, ?Company $company): array
    {
        $snapshot = $tariffClass->tariff()->withoutGlobalScopes()->firstOrFail()->snapshot();

        $settings = app(Settings::class);
        $coeff = (float) $settings->get('pricing.surge_coeff', $branch->id);
        if ($coeff > 1) {
            $from = (int) $settings->get('pricing.surge_from_hour', $branch->id);
            $to = (int) $settings->get('pricing.surge_to_hour', $branch->id);
            $hour = CarbonImmutable::now($branch->timezone)->hour;
            $active = $from === $to || ($from < $to ? $hour >= $from && $hour < $to : $hour >= $from || $hour < $to);
            if ($active) {
                $snapshot['surge_coeff'] = $coeff;
            }
        }

        $discount = max((int) $client->discount_percent, (int) $company?->discount_percent);
        if ($discount > 0) {
            $snapshot['discount_percent'] = $discount;
        }

        return $snapshot;
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
        return $order->started_at ? $this->track->length($driver->id, $order->started_at, now()) : null;
    }
}
