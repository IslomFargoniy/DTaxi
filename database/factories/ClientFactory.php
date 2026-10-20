<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'phone' => '+99890'.fake()->unique()->numerify('#######'),
            'name' => fake()->boolean() ? UzbekNames::firstName() : null,
        ];
    }
}
