<?php

namespace Database\Factories;

use App\Models\Driver;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'brand' => 'Chevrolet',
            'model' => fake()->randomElement(['Cobalt', 'Nexia', 'Gentra', 'Spark', 'Malibu']),
            'color' => fake()->randomElement(['oq', 'qora', 'kumush', 'kulrang']),
            'plate_number' => sprintf('%02d%s%03d%s', fake()->numberBetween(30, 49), fake()->randomLetter(), fake()->unique()->numberBetween(1, 999), fake()->lexify('??')),
            'year' => fake()->numberBetween(2012, 2025),
            'is_current' => true,
            'is_approved' => true,
        ];
    }
}
