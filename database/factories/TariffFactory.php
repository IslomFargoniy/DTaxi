<?php

namespace Database\Factories;

use App\Models\Tariff;
use App\Models\TariffClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tariff>
 */
class TariffFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tariff_class_id' => TariffClass::factory(),
            'branch_id' => fn (array $attributes) => TariffClass::query()->withoutGlobalScopes()->whereKey($attributes['tariff_class_id'])->value('branch_id'),
            'base_fare' => 10000,
            'base_km' => 3,
            'per_km' => 2000,
        ];
    }
}
