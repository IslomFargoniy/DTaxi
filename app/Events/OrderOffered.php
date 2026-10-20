<?php

namespace App\Events;

use App\Models\Order;
use App\Models\OrderOffer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Haydovchiga efirdan taklif (qaror #28).
 */
class OrderOffered implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Order $order, public OrderOffer $offer, public int $expiresInSeconds) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("driver.{$this->offer->driver_id}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return self::payload($this->order, $this->offer, $this->expiresInSeconds);
    }

    /**
     * Reverb va FCM uchun bir xil ma'lumot.
     *
     * @return array<string, mixed>
     */
    public static function payload(Order $order, OrderOffer $offer, int $expiresInSeconds): array
    {
        return [
            'type' => 'order.offered',
            'offer_id' => $offer->id,
            'order_id' => $order->id,
            'is_direct' => $offer->is_direct,
            'distance_m' => $offer->distance_m,
            'pickup_text' => $order->pickup_text,
            'pickup_lat' => $order->pickup_location->getLatitude(),
            'pickup_lng' => $order->pickup_location->getLongitude(),
            'dropoff_text' => $order->dropoff_text,
            'tariff_class' => $order->tariffClass->name,
            'is_delivery' => $order->tariffClass->is_delivery,
            'price_estimate' => $order->price_estimate,
            'price_bonus' => $order->price_bonus,
            'is_corporate' => $order->company_id !== null,
            'comment' => $order->comment,
            'expires_in' => $expiresInSeconds,
        ];
    }
}
