<?php

namespace Database\Factories;

use App\Models\Landmark;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Landmark>
 */
class LandmarkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => UzbekNames::landmark(),
            'aliases' => [],
            // Farg'ona shahri atrofi
            'location' => Point::makeGeodetic(40.38 + fake()->randomFloat(4, -0.02, 0.02), 71.78 + fake()->randomFloat(4, -0.02, 0.02)),
        ];
    }
}
