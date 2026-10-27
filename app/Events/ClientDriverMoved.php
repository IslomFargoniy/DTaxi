<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Mijoz ilovasi (qaror #71): biriktirilgan haydovchi xaritada harakatlanadi — faqat buyurtma faol paytida.
 */
class ClientDriverMoved implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $clientId, public int $orderId, public float $lat, public float $lng, public ?int $heading) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("client.{$this->clientId}")];
    }

    /**
     * @return array{order_id: int, lat: float, lng: float, heading: int|null}
     */
    public function broadcastWith(): array
    {
        return ['order_id' => $this->orderId, 'lat' => $this->lat, 'lng' => $this->lng, 'heading' => $this->heading];
    }
}
