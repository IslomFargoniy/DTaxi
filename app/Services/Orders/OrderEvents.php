<?php

namespace App\Services\Orders;

use App\Events\OrderUpdated;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use App\Services\Telephony\ClientNotifier;

/**
 * Buyurtma tarixiga yozish va panel/ilovaga xabar berish — har bir o'zgarishda birga.
 */
class OrderEvents
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(Order $order, string $type, ?User $actor = null, array $payload = [], bool $broadcast = true): void
    {
        OrderEvent::query()->create([
            'order_id' => $order->id,
            'type' => $type,
            'actor_id' => $actor?->id,
            'payload' => $payload ?: null,
            'created_at' => now(),
        ]);

        if ($broadcast) {
            OrderUpdated::dispatch($order);
        }

        // Mijozga ovozli xabar (§5.4)
        app(ClientNotifier::class)->onOrderEvent($order, $type);
    }
}
