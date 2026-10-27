<?php

namespace App\Events;

use App\Models\Driver;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Operator paneli xaritasi uchun haydovchi joylashuvi va holati.
 */
class DriverLocationUpdated implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Driver $driver) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("branch.{$this->driver->branch_id}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->driver->id,
            'callsign' => $this->driver->callsign,
            'status' => $this->driver->status->value,
            'lat' => $this->driver->last_location?->getLatitude(),
            'lng' => $this->driver->last_location?->getLongitude(),
            'heading' => $this->driver->last_heading,
            'last_seen_at' => $this->driver->last_seen_at?->toIso8601String(),
        ];
    }
}
