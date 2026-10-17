<?php

namespace App\Events;

use App\Models\Call;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Jonli monitoring (§5.5): navbatda kutayotganlar, suhbatlar, tugaganlar.
 */
class CallUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Call $call) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return $this->call->branch_id ? [new PrivateChannel("branch.{$this->call->branch_id}")] : [new PrivateChannel('telephony')];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['call' => [
            'id' => $this->call->id,
            'branch_id' => $this->call->branch_id,
            'direction' => $this->call->direction,
            'kind' => $this->call->kind,
            'caller' => $this->call->caller,
            'callee' => $this->call->callee,
            'status' => $this->call->status,
            'operator_id' => $this->call->operator_id,
            'started_at' => $this->call->started_at->toIso8601String(),
            'answered_at' => $this->call->answered_at?->toIso8601String(),
        ]];
    }
}
