<?php

use App\Enums\DriverStatus;
use App\Models\Branch;
use App\Models\Driver;
use Clickbar\Magellan\Data\Geometries\Point;

/**
 * Farg'ona markazidan (40.3864, 71.7864) taxminan $metersNorth metr shimolda turgan bo'sh haydovchi.
 */
function driverNear(Branch $branch, int $metersNorth, array $attributes = []): Driver
{
    $driver = Driver::factory()->withVehicle()->create([
        'branch_id' => $branch->id,
        'status' => DriverStatus::Free,
        'last_location' => Point::makeGeodetic(40.3864 + $metersNorth / 111_000, 71.7864),
        'last_seen_at' => now(),
        ...$attributes,
    ]);

    return $driver->refresh();
}

/**
 * @return array<string, mixed>
 */
function orderData(Branch $branch, array $overrides = []): array
{
    return [
        'branch_id' => $branch->id,
        'phone' => '90 123 45 67',
        'pickup_text' => 'Mustaqillik maydoni',
        'pickup_lat' => 40.3864,
        'pickup_lng' => 71.7864,
        ...$overrides,
    ];
}
