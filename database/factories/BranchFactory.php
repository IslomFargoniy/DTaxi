<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city(),
            'service_name' => 'ibTaxi',
            'timezone' => 'Asia/Tashkent',
            'is_active' => true,
        ];
    }
}
