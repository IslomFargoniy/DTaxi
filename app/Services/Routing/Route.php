<?php

namespace App\Services\Routing;

final readonly class Route
{
    /**
     * @param  list<array{float, float}>|null  $geometry  [lon, lat] nuqtalar (marshrut chizig'i uchun)
     * @param  list<array{type: string, modifier: string|null, name: string, distance: int, lat: float, lng: float, exit: int|null}>|null  $steps  manevrlar (ovozli navigatsiya)
     */
    public function __construct(
        public int $distanceMeters,
        public int $durationSeconds,
        public ?array $geometry = null,
        public bool $estimated = false,
        public ?array $steps = null,
    ) {}
}
