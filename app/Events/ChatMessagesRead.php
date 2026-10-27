<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Chat xabarlari o'qildi (Telegram kabi ✓✓): yozgan tomonda belgi darhol yangilansin.
 * byDriver — haydovchi dispetcher xabarlarini o'qidi; aks holda dispetcher haydovchinikini.
 */
class ChatMessagesRead implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $driverId, public int $branchId, public bool $byDriver, public int $upToId) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("branch.{$this->branchId}"),
            new PrivateChannel("driver.{$this->driverId}"),
        ];
    }

    /**
     * @return array{driver_id: int, by_driver: bool, up_to_id: int}
     */
    public function broadcastWith(): array
    {
        return ['driver_id' => $this->driverId, 'by_driver' => $this->byDriver, 'up_to_id' => $this->upToId];
    }
}
