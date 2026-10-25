<?php

namespace App\Services\Orders;

use App\Enums\OrderSource;
use App\Events\ClientOrderUpdated;
use App\Events\OrderUpdated;
use App\Jobs\SendClientPush;
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
            // Ilovadan berilgan buyurtma — mijoz ilovasi ham yangilanadi (qaror #71)
            if ($order->source === OrderSource::App) {
                ClientOrderUpdated::dispatch($order);
            }
        }

        // Mijozga ovozli xabar (§5.4); ilova buyurtmasi — ovozli emas, push (qaror #71)
        app(ClientNotifier::class)->onOrderEvent($order, $type);
        if ($order->source === OrderSource::App && in_array($type, SendClientPush::EVENTS, true)) {
            SendClientPush::dispatch($order->id, $type);
        }
    }
}
