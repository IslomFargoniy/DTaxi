<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Haydovchiga: jiringlayotgan qo'ng'iroq endi yo'q (operator qo'ydi, rad etildi yoki vaqti o'tdi) — ilova ekranini yopadi.
 */
class IncomingCallEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $driverId, public int $callId, public string $status) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("driver.{$this->driverId}")];
    }

    /**
     * @return array{type: string, call_id: int, status: string}
     */
    public function broadcastWith(): array
    {
        return ['type' => 'incoming_call_ended', 'call_id' => $this->callId, 'status' => $this->status];
    }
}
