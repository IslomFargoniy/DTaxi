<?php

namespace App\Events;

use App\Http\Resources\ClientOrderResource;
use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Mijoz ilovasi (qaror #71): buyurtma holati — faqat ilovadan berilgan buyurtmalar, mijozning shaxsiy kanaliga.
 */
class ClientOrderUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Order $order) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("client.{$this->order->client_id}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['order' => (new ClientOrderResource($this->order->loadMissing(['driver.user', 'vehicle', 'tariffClass'])))->resolve()];
    }
}
