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
     * Lokal ishlab chiqish uchun boshlang'ich ma'lumotlar. Production'da superadmin `dtaxi:init` bilan yaratiladi (FirstRunSeeder).
     */
    public function run(): void
    {
        $this->call(RolesSeeder::class);

        $branch = Branch::query()->firstOrCreate(['name' => "Farg'ona"], ['service_name' => 'Dtaxi']);

        // Hududlar import qilingan bo'lsa — filial hududi Farg'ona shahridan (qaror #31)
        $city = Region::query()->where('name_uz', 'like', 'Farg%shahri')->first();
        if ($city && ! $branch->region_id) {
            $branch->fillAreaFromRegion($city);
            $branch->save();
        }

        // Sinov kirishlari: login — admin, branch, operator, cashier; parol hammasida bir xil
        $users = [
            ['Superadmin', 'admin', Role::Superadmin],
            ['Filial admini', 'branch', Role::Admin],
            ['Operator', 'operator', Role::Operator],
            ['Kassir', 'cashier', Role::Cashier],
        ];

        foreach ($users as [$name, $username, $role]) {
            User::query()->firstOrCreate(['username' => $username], [
                'name' => $name,
                'password' => FirstRunSeeder::DEV_PASSWORD,
                'branch_id' => $role === Role::Superadmin ? null : $branch->id,
                'email_verified_at' => now(),
            ])->syncRoles($role);
        }
    }
}
