<?php

namespace App\Services\Pricing;

final readonly class Fare
{
    /**
     * @param  array{base: int, distance: int, outside: int, waiting: int, night: int, rounding: int}  $breakdown
     */
    public function __construct(
        public int $total,
        public array $breakdown,
    ) {}
}
