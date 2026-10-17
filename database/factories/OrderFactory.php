<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Order;
use App\Models\TariffClass;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'client_id' => Client::factory(),
            'tariff_class_id' => fn (array $attributes) => TariffClass::query()->withoutGlobalScopes()
                ->where('branch_id', $attributes['branch_id'])->where('is_default', true)->value('id'),
            'tariff_snapshot' => fn (array $attributes) => TariffClass::query()->withoutGlobalScopes()
                ->whereKey($attributes['tariff_class_id'])->firstOrFail()->tariff()->withoutGlobalScopes()->firstOrFail()->snapshot(),
            'status' => OrderStatus::New,
            'pickup_text' => 'Mustaqillik maydoni',
            'pickup_location' => Point::makeGeodetic(40.3864, 71.7864),
        ];
    }
}
