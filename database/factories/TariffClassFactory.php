<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\TariffClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TariffClass>
 */
class TariffClassFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->unique()->word(),
            'sort' => 10,
        ];
    }
}
