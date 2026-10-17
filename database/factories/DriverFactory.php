<?php

namespace Database\Factories;

use App\Enums\DriverStatus;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Driver>
 */
class DriverFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'branch_id' => Branch::factory(),
            'callsign' => (string) fake()->unique()->numberBetween(100, 99999),
            'status' => DriverStatus::Offline,
            'balance' => 50000,
            'rating' => 100,
            'is_approved' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Driver $driver) {
            // Haydovchining foydalanuvchisi ham o'z filialiga tegishli (filial chegarasi uchun)
            $driver->user->forceFill(['branch_id' => $driver->branch_id])->save();

            if (class_exists(\Spatie\Permission\Models\Role::class) && \Spatie\Permission\Models\Role::query()->where('name', Role::Driver->value)->exists()) {
                $driver->user->assignRole(Role::Driver);
            }
        });
    }

    public function free(): static
    {
        return $this->state(['status' => DriverStatus::Free]);
    }

    /**
     * Joriy mashina bilan; mashina filialning barcha tarif klasslariga ruxsatli.
     */
    public function withVehicle(): static
    {
        return $this->afterCreating(function (Driver $driver) {
            $vehicle = Vehicle::factory()->create(['driver_id' => $driver->id]);
            $vehicle->tariffClasses()->sync($driver->branch->tariffClasses()->withoutGlobalScopes()->pluck('id'));
        });
    }
}
