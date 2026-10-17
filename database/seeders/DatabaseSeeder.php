<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Region;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Lokal ishlab chiqish uchun boshlang'ich ma'lumotlar. Production'da superadmin sozlash ustasida yaratiladi.
     */
    public function run(): void
    {
        $this->call(RolesSeeder::class);

        $branch = Branch::query()->firstOrCreate(['name' => "Farg'ona"], ['service_name' => 'ibTaxi']);

        // Hududlar import qilingan bo'lsa — filial hududi Farg'ona shahridan (qaror #31)
        $city = Region::query()->where('name_uz', 'like', 'Farg%shahri')->first();
        if ($city && ! $branch->region_id) {
            $branch->fillAreaFromRegion($city);
            $branch->save();
        }

        $users = [
            ['Superadmin', 'admin@ibtaxi.test', Role::Superadmin],
            ['Filial admini', 'branch@ibtaxi.test', Role::Admin],
            ['Operator', 'operator@ibtaxi.test', Role::Operator],
            ['Kassir', 'cashier@ibtaxi.test', Role::Cashier],
        ];

        foreach ($users as [$name, $email, $role]) {
            User::query()->firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => 'password',
                'branch_id' => $role === Role::Superadmin ? null : $branch->id,
                'email_verified_at' => now(),
            ])->syncRoles($role);
        }
    }
}
