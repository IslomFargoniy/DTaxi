<?php

namespace App\Services\Dispatch;

use App\Enums\DriverStatus;
use App\Enums\OfferResponse;
use App\Enums\OrderStatus;
use App\Events\DriverLocationUpdated;
use App\Events\OfferWithdrawn;
use App\Events\OrderOffered;
use App\Jobs\ExpandOrderSearch;
use App\Jobs\ExpireDirectOffer;
use App\Jobs\SendPushNotification;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderOffer;
use App\Models\User;
use App\Services\Drivers\DriverLocator;
use App\Services\Drivers\StandQueue;
use App\Services\Orders\OrderEvents;
use App\Services\Orders\OrderTransition;
use App\Support\Settings;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Efir (qaror #28): buyurtma radius ichidagi barcha mos bo'sh haydovchilarga bir vaqtda ko'rsatiladi,
 * birinchi qabul qilgan oladi. Hech kim olmasa radius bosqichma-bosqich kengayadi.
 */
class Dispatcher
{
    public function __construct(
        private DriverLocator $locator,
        private Settings $settings,
        private StandQueue $standQueue,
    ) {}

    /**
     * Yangi raund bilan efir. Holat shartli o'zgaradi: shu orada bekor qilingan yoki olingan buyurtma qayta efirga chiqmaydi.
     *
     * @param  list<OrderStatus>  $from  qaysi holatlardan efirga chiqarish mumkin
     * @param  list<int>  $skipDriverIds  shu raundda taklif olmaydiganlar (tayinlangan taklifni rad etgan haydovchi)
     */
    public function startSearch(Order $order, array $from = [OrderStatus::New, OrderStatus::Searching], array $skipDriverIds = []): bool
    {
        $steps = $this->radiusSteps($order);

        $moved = OrderTransition::apply($order, $from, [
            'status' => OrderStatus::Searching,
            'searching_at' => $order->searching_at ?? now(),
            'search_radius_m' => $steps[0],
            'search_round' => DB::raw('search_round + 1'),
            'search_exhausted_at' => null,
        ]);
        if (! $moved) {
            return false;
        }

        if ($skipDriverIds !== []) {
            OrderOffer::query()->where('order_id', $order->id)->whereIn('driver_id', $skipDriverIds)
                ->update(['round' => $order->search_round]);
        }

        OrderEvents::record($order, 'searching', payload: ['radius_m' => $steps[0]]);

        $this->broadcast($order);
        $this->scheduleExpansion($order, 0);

        return true;
    }

    /**
     * Joriy radiusdagi, shu raundda hali taklif olmagan haydovchilarga taklif yuboradi.
     * Oldingi raunddagi takliflar hisobga olinmaydi — qayta efirda hamma yana ko'radi,
     * faqat shu buyurtmani bekor qilgan haydovchidan tashqari.
     */
    public function broadcast(Order $order): int
    {
        // Eskirgan model bilan (job navbatda kutgan paytda olingan/bekor qilingan) taklif yuborilmasin
        $order->refresh();
        if ($order->status !== OrderStatus::Searching) {
            return 0;
        }

        $excluded = array_values(OrderOffer::query()->where('order_id', $order->id)
            ->where(fn ($q) => $q->where('round', $order->search_round)
                ->orWhere('response', OfferResponse::Cancelled->value))
            ->pluck('driver_id')->all());
        $radius = $order->search_radius_m ?? $this->radiusSteps($order)[0];
        $candidates = $this->locator->candidatesFor($order, $radius, $excluded);
        $expiresIn = (int) $this->settings->get('dispatch.step_seconds', $order->branch_id);

        $firstStep = $radius === $this->radiusSteps($order)[0] && $order->search_exhausted_at === null;
        $fromQueue = false;

        // Navbat joyi hududidan chiqqan buyurtma — birinchi bosqichda faqat navbat boshidagiga
        if ($firstStep && ($queue = $this->standQueue->queueFor($order)) !== []) {
            $byId = $candidates->keyBy(fn (array $c) => $c['driver']->id);
            $head = collect($queue)->first(fn (int $id) => $byId->has($id));
            if ($head !== null) {
                $candidates = collect([$byId->get($head)]);
                $fromQueue = true;
            }
        }

        // Reytingi past haydovchi eng yaqin radiusda ko'rmaydi — keyingi bosqichda (reja §6, qaror #29)
        $threshold = (int) $this->settings->get('dispatch.low_rating_threshold', $order->branch_id);
        if ($threshold > 0 && $firstStep && ! $fromQueue) {
            $candidates = $candidates->filter(fn (array $c) => $c['driver']->rating >= $threshold)->values();
        }

        /** @var list<array{OrderOffer, Driver}> $offers */
        $offers = $this->whileSearching($order, function () use ($order, $candidates) {
            $offers = [];
            foreach ($candidates as ['driver' => $driver, 'distance_m' => $distance]) {
                $offers[] = [OrderOffer::query()->updateOrCreate(
                    ['order_id' => $order->id, 'driver_id' => $driver->id],
                    [
                        'round' => $order->search_round,
                        'distance_m' => $distance,
                        'is_direct' => false,
                        'response' => null,
                        'responded_at' => null,
                        'offered_at' => now(),
                    ],
                ), $driver];
            }

            return $offers;
        }) ?? [];

        foreach ($offers as [$offer, $driver]) {
            $this->notify($order, $offer, $driver, $expiresIn);
        }

        return count($offers);
    }

    /**
     * Takliflar faqat buyurtma hali "qidirilmoqda" bo'lsa yoziladi: qator qulflanib holat qayta tekshiriladi.
     * Aks holda bekor qilish (OrderService::cancel) shu orada withdrawOtherOffers'dan o'tib ketsa,
     * bekor qilingan buyurtmada ochiq takliflar qolardi — haydovchilar ekranida osilib turardi.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T|null null — buyurtma endi qidirilmayapti
     */
    private function whileSearching(Order $order, Closure $write): mixed
    {
        return DB::transaction(function () use ($order, $write) {
            $status = Order::query()->withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->value('status');

            return $status === OrderStatus::Searching ? $write() : null;
        });
    }

    /**
     * Radiusni keyingi bosqichga kengaytirish (ExpandOrderSearch job chaqiradi).
     * Eng katta radiusdan keyin buyurtma tashlab qo'yilmaydi: operator panelida belgilanadi va
     * `orders:rebroadcast` har daqiqada uni yangi raund bilan qayta efirga chiqaradi.
     */
    public function expand(Order $order, int $fromStep, ?int $round = null): void
    {
        $steps = $this->radiusSteps($order);
        $round ??= $order->search_round;

        $next = $fromStep + 1;
        if (! isset($steps[$next])) {
            $exhausted = OrderTransition::apply($order, [OrderStatus::Searching], ['search_exhausted_at' => now()], fn ($q) => $q
                ->where('search_round', $round)
                ->where('search_radius_m', end($steps))
                ->whereNull('search_exhausted_at'));

            if ($exhausted) {
                OrderEvents::record($order, 'search_exhausted', payload: ['radius_m' => $order->search_radius_m]);
            }

            return;
        }

        // Shart bazada: shu orada qabul qilingan, bekor qilingan yoki yangi raund boshlangan bo'lsa — hech narsa qilinmaydi
        $expanded = OrderTransition::apply($order, [OrderStatus::Searching], ['search_radius_m' => $steps[$next]], fn ($q) => $q
            ->where('search_round', $round)
            ->where('search_radius_m', $steps[$fromStep]));
        if (! $expanded) {
            return;
        }

        OrderEvents::record($order, 'radius_expanded', payload: ['radius_m' => $steps[$next]]);

        $this->broadcast($order);
        $this->scheduleExpansion($order, $next);
    }

    /**
     * Hech kim olmagan buyurtmalar: yangi raund — oldin javob bermaganlar va keyin bo'shaganlar ham ko'radi.
     * Job o'zini qayta chaqirmaydi (sinxron navbatda cheksiz aylanardi) — rejalashtiruvchi har daqiqada ishga tushiradi.
     */
    public function rebroadcastExhausted(): int
    {
        $count = 0;
        Order::query()->withoutGlobalScopes()
            ->where('status', OrderStatus::Searching->value)
            ->whereNotNull('search_exhausted_at')
            ->each(function (Order $order) use (&$count) {
                $next = OrderTransition::apply($order, [OrderStatus::Searching], ['search_round' => DB::raw('search_round + 1')], fn ($q) => $q
                    ->where('search_round', $order->search_round)
                    ->whereNotNull('search_exhausted_at'));
                if ($next) {
                    $count += $this->broadcast($order);
                }
            });

        return $count;
    }

    /**
     * Haydovchi "Qabul qildim" bosdi. Atomik: faqat bitta haydovchi yutadi, haydovchi ham faqat bitta buyurtma oladi.
     */
    public function accept(Order $order, Driver $driver): bool
    {
        try {
            DB::transaction(function () use ($order, $driver) {
                // Shartli UPDATE: parallel so'rovlardan faqat bittasi 1 qatorni o'zgartiradi
                $orderUpdated = Order::query()->withoutGlobalScopes()
                    ->whereKey($order->id)
                    ->where('status', OrderStatus::Searching->value)
                    ->whereExists(fn ($q) => $q->from('order_offers')
                        ->whereColumn('order_offers.order_id', 'orders.id')
                        ->where('order_offers.driver_id', $driver->id)
                        ->whereNull('order_offers.response'))
                    ->update([
                        'status' => OrderStatus::Assigned->value,
                        'driver_id' => $driver->id,
                        'vehicle_id' => $driver->vehicle?->id,
                        'assigned_at' => now(),
                    ]);

                // Haydovchi bir vaqtda ikkita efirdan ikkita buyurtma ola olmaydi
                $driverUpdated = $orderUpdated === 1
                    ? Driver::query()->withoutGlobalScopes()->whereKey($driver->id)
                        ->where('status', DriverStatus::Free->value)
                        ->update(['status' => DriverStatus::Busy->value])
                    : 0;

                if ($driverUpdated !== 1) {
                    throw new OfferUnavailable;
                }

                OrderOffer::query()->where('order_id', $order->id)->where('driver_id', $driver->id)
                    ->update(['response' => OfferResponse::Accepted->value, 'responded_at' => now()]);
            });
        } catch (OfferUnavailable) {
            OrderOffer::query()->where('order_id', $order->id)->where('driver_id', $driver->id)->whereNull('response')
                ->update(['response' => OfferResponse::TooLate->value, 'responded_at' => now()]);

            return false;
        }

        $order->refresh();
        $driver->refresh();
        $this->standQueue->leave($driver);
        // Holat so'rov bilan o'zgardi (model hodisasi yo'q) — xaritaga "band"
        DriverLocationUpdated::dispatch($driver);

        $this->withdrawOtherOffers($order, $driver->id, 'taken');
        OrderEvents::record($order, 'assigned', $driver->user, ['driver_id' => $driver->id]);

        return true;
    }

    /**
     * Operator haydovchini qo'lda tayinlaydi: unga to'g'ridan-to'g'ri taklif ketadi,
     * rad etsa yoki javob bermasa — jarima (qaror #29).
     */
    public function offerDirect(Order $order, Driver $driver, User $operator): OrderOffer
    {
        // Eskirgan ekrandan: allaqachon olingan yoki yakunlangan buyurtma taklif qilinmaydi
        if (! in_array($order->status, [OrderStatus::New, OrderStatus::Searching], true)) {
            throw new InvalidArgumentException(__('The order is no longer waiting for a driver.'));
        }

        // Liniyada bo'lmagan, band yoki bloklangan haydovchiga taklif yubormaymiz — javob bera olmaydi, keyin nohaq jarima oladi
        $driver->refresh();
        $ttl = (int) $this->settings->get('dispatch.location_ttl_seconds', $order->branch_id);
        if ($driver->status !== DriverStatus::Free || ! $driver->is_approved || $driver->isBlocked()
            || $driver->last_seen_at === null || $driver->last_seen_at->lt(now()->subSeconds($ttl))) {
            throw new InvalidArgumentException(__('The driver is not available for orders right now.'));
        }

        $expiresIn = (int) $this->settings->get('dispatch.direct_offer_seconds', $order->branch_id);

        if ($order->status === OrderStatus::New) {
            OrderTransition::apply($order, [OrderStatus::New], [
                'status' => OrderStatus::Searching,
                'searching_at' => now(),
                'search_radius_m' => $order->search_radius_m ?? $this->radiusSteps($order)[0],
                'search_round' => DB::raw('search_round + 1'),
            ]);
        }
        if ($order->status !== OrderStatus::Searching) {
            throw new InvalidArgumentException(__('The order is no longer waiting for a driver.'));
        }

        $offer = $this->whileSearching($order, fn () => OrderOffer::query()->updateOrCreate(
            ['order_id' => $order->id, 'driver_id' => $driver->id],
            ['is_direct' => true, 'round' => $order->search_round, 'response' => null, 'responded_at' => null, 'offered_at' => now(), 'distance_m' => null],
        ));
        if ($offer === null) {
            throw new InvalidArgumentException(__('The order is no longer waiting for a driver.'));
        }

        OrderEvents::record($order, 'direct_offer', $operator, ['driver_id' => $driver->id]);
        $this->notify($order, $offer, $driver, $expiresIn);

        ExpireDirectOffer::dispatch($offer->id)->delay(now()->addSeconds($expiresIn));

        return $offer;
    }

    public function reject(Order $order, Driver $driver): void
    {
        $offer = OrderOffer::query()->where('order_id', $order->id)->where('driver_id', $driver->id)->first();
        // Shartli UPDATE: rad etish va muddat tugashi (ExpireDirectOffer) bir vaqtda kelsa, faqat bittasi o'tadi
        $changed = $offer ? OrderOffer::query()->whereKey($offer->id)->whereNull('response')
            ->update(['response' => OfferResponse::Rejected->value, 'responded_at' => now()]) : 0;

        if ($offer && $changed === 1 && $offer->is_direct) {
            $this->directOfferFailed($order, $driver, 'rejected');
        }
    }

    /**
     * Tayinlangan taklif rad etildi yoki javobsiz qoldi: jarima va buyurtma oddiy efirga qaytadi
     * (aks holda oldindan buyurtma "qidirilmoqda" da kengayishsiz qotib qolardi).
     */
    public function directOfferFailed(Order $order, Driver $driver, string $kind): void
    {
        $order->refresh();
        // Shu orada boshqasi olgan yoki bekor qilingan — haydovchining aybi yo'q
        if ($order->status !== OrderStatus::Searching) {
            return;
        }

        // Boshqa buyurtmada band haydovchi javob bera olmasdi
        if ($driver->refresh()->status !== DriverStatus::Busy) {
            app(DriverDiscipline::class)->directOfferMissed($driver, $order, $kind);
        }

        $this->startSearch($order, [OrderStatus::Searching], [$driver->id]);
    }

    public function withdrawOtherOffers(Order $order, ?int $exceptDriverId, string $reason): void
    {
        $offers = OrderOffer::query()->where('order_id', $order->id)->whereNull('response')
            ->when($exceptDriverId, fn ($q) => $q->where('driver_id', '!=', $exceptDriverId))
            ->get();

        foreach ($offers as $offer) {
            $offer->forceFill(['response' => OfferResponse::Ignored, 'responded_at' => now()])->save();
            OfferWithdrawn::dispatch($offer->driver_id, $order->id, $reason);
        }
    }

    /**
     * Haydovchi bekor qilgan buyurtmani qayta efirga chiqarish. Holatni "qidirilmoqda" ga
     * OrderService::cancelByDriver shartli o'zgartiradi — bu yerda faqat hali qidirilayotgan bo'lsa efir boshlanadi.
     */
    public function restartSearch(Order $order): bool
    {
        return $this->startSearch($order, [OrderStatus::Searching]);
    }

    private function notify(Order $order, OrderOffer $offer, Driver $driver, int $expiresIn): void
    {
        OrderOffered::dispatch($order, $offer, $expiresIn);
        // Ilova fonda yoki yopiq bo'lsa — FCM uyg'otadi; ilova offer_id + offered_at bo'yicha takrorni ajratadi
        SendPushNotification::dispatch($driver->user_id, array_filter(
            OrderOffered::payload($order->loadMissing('tariffClass'), $offer, $expiresIn),
            fn ($value) => is_scalar($value),
        ));
    }

    private function scheduleExpansion(Order $order, int $step): void
    {
        $seconds = (int) $this->settings->get('dispatch.step_seconds', $order->branch_id);
        ExpandOrderSearch::dispatch($order->id, $step, $order->search_round)->delay(now()->addSeconds($seconds));
    }

    /**
     * @return non-empty-list<int>
     */
    private function radiusSteps(Order $order): array
    {
        $steps = array_values(array_map('intval', (array) $this->settings->get('dispatch.radius_steps_m', $order->branch_id)));

        return $steps === [] ? [3000] : $steps;
    }
}
