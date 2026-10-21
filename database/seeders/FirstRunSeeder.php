<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
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

    public ?string $email = null;

    /**
     * Shu ishga tushirishda yaratilgan superadmin kirish ma'lumotlari.
     *
     * @var array{email: string, password: string}|null
     */
    public ?array $credentials = null;

    public function run(): void
    {
        app(RolesSeeder::class)->run();

        if (self::superadminExists()) {
            return;
        }

        $email = $this->email ?: self::defaultEmail();
        $password = Str::password(16, symbols: false);

        DB::transaction(function () use ($email, $password) {
            $settings = app(Settings::class);
            $settings->set('company.name', $this->company);
            $settings->set('company.default_locale', 'uz');

            // Dev seeder yoki oldingi urinish filial yaratgan bo'lishi mumkin
            if (! Branch::query()->withoutGlobalScopes()->exists()) {
                Branch::query()->create(['name' => 'Asosiy filial', 'service_name' => $this->company]);
            }

            $user = User::query()->firstOrNew(['email' => $email]);
            $user->fill(['name' => 'Superadmin', 'password' => $password, 'locale' => 'uz', 'branch_id' => null]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles(Role::Superadmin);
        });

        $this->credentials = ['email' => $email, 'password' => $password];
    }

    public static function superadminExists(): bool
    {
        return User::query()->whereHas('roles', fn ($q) => $q->where('name', Role::Superadmin->value))->exists();
    }

    /**
     * admin@<domen> — server domeni APP_URL dan.
     */
    public static function defaultEmail(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return 'admin@'.(is_string($host) && $host !== '' ? $host : 'dtaxi.local');
    }
}
