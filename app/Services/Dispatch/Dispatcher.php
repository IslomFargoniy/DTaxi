<?php

namespace App\Services\Dispatch;

use App\Enums\DriverStatus;
use App\Enums\OfferResponse;
use App\Enums\OrderStatus;
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
use App\Services\Orders\OrderEvents;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Efir (qaror #28): buyurtma radius ichidagi barcha mos bo'sh haydovchilarga bir vaqtda ko'rsatiladi,
 * birinchi qabul qilgan oladi. Hech kim olmasa radius bosqichma-bosqich kengayadi.
 */
class Dispatcher
{
    public function __construct(
        private DriverLocator $locator,
        private Settings $settings,
    ) {}

    public function startSearch(Order $order): void
    {
        $steps = $this->radiusSteps($order);

        $order->forceFill([
            'status' => OrderStatus::Searching,
            'searching_at' => $order->searching_at ?? now(),
            'search_radius_m' => $steps[0],
        ])->save();

        OrderEvents::record($order, 'searching', payload: ['radius_m' => $steps[0]]);

        $this->broadcast($order);
        $this->scheduleExpansion($order, 0);
    }

    /**
     * Joriy radiusdagi, hali taklif olmagan haydovchilarga taklif yuboradi.
     */
    public function broadcast(Order $order): int
    {
        if ($order->status !== OrderStatus::Searching) {
            return 0;
        }

        $alreadyOffered = array_values(OrderOffer::query()->where('order_id', $order->id)->pluck('driver_id')->all());
        $candidates = $this->locator->candidatesFor($order, $order->search_radius_m, $alreadyOffered);
        $expiresIn = (int) $this->settings->get('dispatch.step_seconds', $order->branch_id);

        foreach ($candidates as ['driver' => $driver, 'distance_m' => $distance]) {
            $offer = OrderOffer::query()->create([
                'order_id' => $order->id,
                'driver_id' => $driver->id,
                'distance_m' => $distance,
                'offered_at' => now(),
            ]);

            $this->notify($order, $offer, $driver, $expiresIn);
        }

        return $candidates->count();
    }

    /**
     * Radiusni keyingi bosqichga kengaytirish (ExpandOrderSearch job chaqiradi).
     */
    public function expand(Order $order, int $fromStep): void
    {
        $steps = $this->radiusSteps($order);

        if ($order->status !== OrderStatus::Searching || $order->search_radius_m !== $steps[$fromStep]) {
            return;
        }

        $next = $fromStep + 1;
        if (! isset($steps[$next])) {
            // Eng katta radiusda ham hech kim olmadi — operator hal qiladi (qo'lda tayinlash yoki bekor qilish)
            OrderEvents::record($order, 'search_exhausted', payload: ['radius_m' => $order->search_radius_m]);

            return;
        }

        $order->forceFill(['search_radius_m' => $steps[$next]])->save();
        OrderEvents::record($order, 'radius_expanded', payload: ['radius_m' => $steps[$next]]);

        $this->broadcast($order);
        $this->scheduleExpansion($order, $next);
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
        $expiresIn = (int) $this->settings->get('dispatch.direct_offer_seconds', $order->branch_id);

        if ($order->status === OrderStatus::New) {
            $order->forceFill(['status' => OrderStatus::Searching, 'searching_at' => now()])->save();
        }

        $offer = OrderOffer::query()->updateOrCreate(
            ['order_id' => $order->id, 'driver_id' => $driver->id],
            ['is_direct' => true, 'response' => null, 'responded_at' => null, 'offered_at' => now(), 'distance_m' => null],
        );

        OrderEvents::record($order, 'direct_offer', $operator, ['driver_id' => $driver->id]);
        $this->notify($order, $offer, $driver, $expiresIn);

        ExpireDirectOffer::dispatch($offer->id)->delay(now()->addSeconds($expiresIn));

        return $offer;
    }

    public function reject(Order $order, Driver $driver): void
    {
        $offer = OrderOffer::query()->where('order_id', $order->id)->where('driver_id', $driver->id)->whereNull('response')->first();
        if (! $offer) {
            return;
        }

        $offer->forceFill(['response' => OfferResponse::Rejected, 'responded_at' => now()])->save();

        if ($offer->is_direct) {
            app(DriverDiscipline::class)->directOfferMissed($driver, $order, 'rejected');
        }
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
     * Haydovchi bekor qilgan yoki direct taklif o'tmagan buyurtmani qayta efirga chiqarish.
     */
    public function restartSearch(Order $order): void
    {
        $order->forceFill([
            'status' => OrderStatus::Searching,
            'driver_id' => null,
            'vehicle_id' => null,
            'assigned_at' => null,
            'arrived_at' => null,
        ])->save();

        $this->startSearch($order);
    }

    private function notify(Order $order, OrderOffer $offer, Driver $driver, int $expiresIn): void
    {
        OrderOffered::dispatch($order, $offer, $expiresIn);
        // Ilova fonda yoki yopiq bo'lsa — FCM uyg'otadi; ilova offer_id bo'yicha takrorni e'tiborsiz qoldiradi
        SendPushNotification::dispatch($driver->user_id, array_filter(
            OrderOffered::payload($order->loadMissing('tariffClass'), $offer, $expiresIn),
            fn ($value) => $value !== null && ! is_array($value),
        ));
    }

    private function scheduleExpansion(Order $order, int $step): void
    {
        $seconds = (int) $this->settings->get('dispatch.step_seconds', $order->branch_id);
        ExpandOrderSearch::dispatch($order->id, $step)->delay(now()->addSeconds($seconds));
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
