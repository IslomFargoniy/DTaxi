<?php

namespace Database\Seeders;

use App\Enums\DriverStatus;
use App\Models\Branch;
use App\Models\Driver;
use App\Models\Landmark;
use App\Models\User;
use App\Models\Vehicle;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Seeder;

/**
 * Lokal sinov uchun: Farg'ona shahrida haydovchilar va bir nechta mo'ljal.
 * `php artisan db:seed --class=DemoSeeder` — production'da ishlatilmaydi.
 */
class DemoSeeder extends Seeder
{
    private const LANDMARKS = [
        ['Mustaqillik maydoni', ['Мустакиллик'], 40.3864, 71.7864],
        ['Farg\'ona dehqon bozori', ['Katta bozor', 'Фергана базар'], 40.3790, 71.7700],
        ['Avtovokzal', ['Автовокзал'], 40.3720, 71.7605],
        ['Farg\'ona davlat universiteti', ['FarDU', 'ФерГУ'], 40.3895, 71.7830],
        ['Viloyat ko\'p tarmoqli shifoxonasi', ['Oblastnoy bolnitsa'], 40.3950, 71.7930],
        ['Korzinka Farg\'ona', ['Корзинка'], 40.3840, 71.7980],
    ];

    public function run(): void
    {
        $branch = Branch::query()->where('name', "Farg'ona")->firstOrFail();

        foreach (self::LANDMARKS as [$name, $aliases, $lat, $lng]) {
            Landmark::query()->firstOrCreate(['name' => $name], ['aliases' => $aliases, 'location' => Point::makeGeodetic($lat, $lng)]);
        }

        $models = ['Cobalt', 'Nexia', 'Gentra', 'Spark', 'Malibu', 'Damas'];
        foreach (range(1, 12) as $i) {
            $user = User::query()->firstOrCreate(['phone' => sprintf('+99890000%04d', $i)], [
                'name' => "Haydovchi {$i}",
                'username' => "driver{$i}",
                'password' => FirstRunSeeder::DEV_PASSWORD,
                'branch_id' => $branch->id,
            ]);

            $driver = Driver::query()->firstOrCreate(['user_id' => $user->id], [
                'branch_id' => $branch->id,
                'callsign' => (string) (100 + $i),
                'status' => $i % 4 === 0 ? DriverStatus::Busy : DriverStatus::Free,
                'balance' => 50000,
                'is_approved' => true,
                'last_location' => Point::makeGeodetic(40.3864 + mt_rand(-200, 200) / 10000, 71.7864 + mt_rand(-300, 300) / 10000),
                'last_seen_at' => now(),
            ]);
            $user->syncRoles('driver');

            $model = $models[$i % count($models)];
            $vehicle = Vehicle::query()->firstOrCreate(['plate_number' => sprintf('40A%03dAA', $i)], [
                'driver_id' => $driver->id,
                'brand' => 'Chevrolet',
                'model' => $model,
                'color' => ['oq', 'qora', 'kumush'][$i % 3],
                'is_approved' => true,
            ]);
            $classes = $branch->tariffClasses()->withoutGlobalScopes()->get();
            $vehicle->tariffClasses()->sync($classes->filter(fn ($c) => $model === 'Damas' ? in_array($c->name, ['Yuk', 'Dostavka']) : $c->name !== 'Yuk')->pluck('id'));
        }
    }
}
