<?php

namespace Database\Seeders;

use App\Enums\DriverStatus;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Driver;
use App\Models\Region;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Telephony\Extensions;
use App\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Serverdagi birinchi ishga tushirish (qaror #58): rollar, bitta filial va superadmin tasodifiy parol bilan.
 * Ochiq sozlash ustasi o'rniga — birinchi kirgan begona odam superadmin bo'lib qolmasin.
 * Qayta ishga tushirish xavfsiz: superadmin bor bo'lsa hech narsa o'zgarmaydi.
 */
class FirstRunSeeder extends Seeder
{
    public string $company = 'Dtaxi';

    /** Sinov (lokal) muhitida barcha demo foydalanuvchilar paroli — firma serverida ishlatilmaydi */
    public const DEV_PASSWORD = '11221122';

    public string $username = 'admin';

    public ?string $email = null;

    /** Demo server (install.sh --demo): har rolga bittadan foydalanuvchi, hammasida DEV_PASSWORD */
    public bool $demo = false;

    /** Demo foydalanuvchilar: login => rol (haydovchi telefon raqami bilan kiradi) */
    public const DEMO_STAFF = ['branch' => Role::Admin, 'operator' => Role::Operator, 'cashier' => Role::Cashier];

    public const DEMO_DRIVER_PHONE = '+998900000001';

    /**
     * Shu ishga tushirishda yaratilgan superadmin kirish ma'lumotlari.
     *
     * @var array{login: string, password: string}|null
     */
    public ?array $credentials = null;

    public function run(): void
    {
        app(RolesSeeder::class)->run();

        if (! self::superadminExists()) {
            $this->createSuperadmin();
        }

        if ($this->demo) {
            $this->seedDemoAccounts();
        }
    }

    private function createSuperadmin(): void
    {
        $password = app()->isLocal() || $this->demo ? self::DEV_PASSWORD : Str::password(16, symbols: false);

        DB::transaction(function () use ($password) {
            $settings = app(Settings::class);
            $settings->set('company.name', $this->company);
            $settings->set('company.default_locale', 'uz');

            // Dev seeder yoki oldingi urinish filial yaratgan bo'lishi mumkin
            if (! Branch::query()->withoutGlobalScopes()->exists()) {
                Branch::query()->create(['name' => 'Asosiy filial', 'service_name' => $this->company]);
            }

            $user = User::query()->firstOrNew(['username' => $this->username]);
            $user->fill(['name' => 'Superadmin', 'email' => $this->email, 'password' => $password, 'locale' => 'uz', 'branch_id' => null]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles(Role::Superadmin);
        });

        $this->credentials = ['login' => $this->username, 'password' => $password];
    }

    /**
     * Qayta chaqirish xavfsiz: mavjud foydalanuvchilar va parollarga tegilmaydi. Hududlar keyinroq import qilinsa
     * (install.sh xaritadan keyin yana chaqiradi) filial shunda shaharga bog'lanadi.
     */
    private function seedDemoAccounts(): void
    {
        $branch = Branch::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $city = Region::query()->where('name_uz', 'like', 'Farg%shahri')->first();
        if ($city && ! $branch->region_id) {
            $branch->fillAreaFromRegion($city);
            $branch->save();
        }

        $names = ['branch' => 'Filial admini', 'operator' => 'Operator', 'cashier' => 'Kassir'];
        foreach (self::DEMO_STAFF as $username => $role) {
            $user = User::query()->firstOrNew(['username' => $username]);
            if (! $user->exists) {
                $user->fill(['name' => $names[$username], 'password' => self::DEV_PASSWORD, 'branch_id' => $branch->id]);
                $user->forceFill(['email_verified_at' => now()])->save();
            }
            $user->syncRoles($role);
            app(Extensions::class)->syncFor($user);
        }

        DB::transaction(function () use ($branch) {
            $user = User::query()->firstOrNew(['phone' => self::DEMO_DRIVER_PHONE]);
            if (! $user->exists) {
                $user->fill([
                    'name' => 'Haydovchi',
                    'email' => 'driver-'.self::DEMO_DRIVER_PHONE.'@drivers.local',
                    'password' => self::DEV_PASSWORD,
                    'branch_id' => $branch->id,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            }
            $user->syncRoles(Role::Driver);

            $driver = Driver::query()->withoutGlobalScopes()->firstOrCreate(['user_id' => $user->id], [
                'branch_id' => $branch->id,
                'callsign' => '101',
                'status' => DriverStatus::Offline,
                'balance' => 50000,
                'is_approved' => true,
                'approved_at' => now(),
            ]);

            if (! $driver->vehicle()->exists()) {
                $vehicle = Vehicle::query()->create([
                    'driver_id' => $driver->id,
                    'brand' => 'Chevrolet',
                    'model' => 'Cobalt',
                    'color' => 'oq',
                    'plate_number' => Vehicle::normalizePlate('40 A 001 AA'),
                    'is_current' => true,
                    'is_approved' => true,
                    'approved_at' => now(),
                ]);
                $vehicle->tariffClasses()->sync($branch->tariffClasses()->withoutGlobalScopes()->where('name', '!=', 'Yuk')->pluck('id'));
            }

            app(Extensions::class)->syncFor($user);
        });
    }

    public static function superadminExists(): bool
    {
        return User::query()->whereHas('roles', fn ($q) => $q->where('name', Role::Superadmin->value))->exists();
    }
}
