<?php

namespace App\Services\Routing;

final readonly class Route
{
    /**
     * @param  list<array{float, float}>|null  $geometry  [lon, lat] nuqtalar (marshrut chizig'i uchun)
     */
    public function __construct(
        public int $distanceMeters,
        public int $durationSeconds,
        public ?array $geometry = null,
        public bool $estimated = false,
    ) {}
}
