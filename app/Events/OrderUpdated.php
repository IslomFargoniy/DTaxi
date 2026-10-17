<?php

namespace App\Events;

use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Buyurtma holati o'zgardi — filial operatorlari paneli va biriktirilgan haydovchi ilovasi yangilanadi.
 */
class OrderUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Order $order) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel("branch.{$this->order->branch_id}")];

        if ($this->order->driver_id) {
            $channels[] = new PrivateChannel("driver.{$this->order->driver_id}");
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['order' => (new OrderResource($this->order->loadMissing(['client', 'driver.user', 'vehicle', 'tariffClass'])))->resolve()];
    }
}
