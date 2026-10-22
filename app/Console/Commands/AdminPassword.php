<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Superadmin parolini serverdan tiklash — e-mail orqali tiklash sozlanmagan bo'lishi mumkin.
 */
class AdminPassword extends Command
{
    protected $signature = 'dtaxi:admin-password {login? : Superadmin logini yoki e-mail\'i (standart — birinchi superadmin)}';

    protected $description = 'Superadmin uchun yangi tasodifiy parol';

    public function handle(): int
    {
        $argument = $this->argument('login');
        $login = is_string($argument) ? trim($argument) : '';
        $user = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', Role::Superadmin->value))
            ->when($login !== '', fn ($q) => $q->where(fn ($q) => $q->where('username', strtolower($login))->orWhere('email', $login)))
            ->orderBy('id')
            ->first();

        if (! $user) {
            $this->error('Superadmin topilmadi. Birinchi ishga tushirish: php artisan dtaxi:init');

            return self::FAILURE;
        }

        $password = Str::password(16, symbols: false);
        $user->forceFill(['password' => $password, 'is_active' => true])->save();

        $this->line('Login: '.($user->username ?? $user->email));
        $this->line("Parol: {$password}");
        $this->warn('Kirgandan keyin parolni almashtiring.');

        return self::SUCCESS;
    }
}
