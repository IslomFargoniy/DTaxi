<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Haydovchiga: operator qo'ng'iroq qilyapti. Ilova ro'yxatdan o'tmagan (SIP) — javob berilsa o'zi answer-{callId} ga qo'ng'iroq qiladi.
 */
class IncomingCall implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $driverId, public int $callId, public string $operator, public int $expiresIn) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("driver.{$this->driverId}")];
    }

    /**
     * @return array{type: string, call_id: int, operator: string, expires_in: int}
     */
    public function broadcastWith(): array
    {
        return ['type' => 'incoming_call', 'call_id' => $this->callId, 'operator' => $this->operator, 'expires_in' => $this->expiresIn];
    }
}
