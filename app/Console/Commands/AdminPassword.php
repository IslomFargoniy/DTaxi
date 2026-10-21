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
    protected $signature = 'ibtaxi:admin-password {email? : Superadmin e-mail\'i (standart — birinchi superadmin)}';

    protected $description = 'Superadmin uchun yangi tasodifiy parol';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', Role::Superadmin->value))
            ->when(is_string($email) && $email !== '', fn ($q) => $q->where('email', $email))
            ->orderBy('id')
            ->first();

        if (! $user) {
            $this->error('Superadmin topilmadi. Birinchi ishga tushirish: php artisan ibtaxi:init');

            return self::FAILURE;
        }

        $password = Str::password(16, symbols: false);
        $user->forceFill(['password' => $password, 'is_active' => true])->save();

        $this->line("Login: {$user->email}");
        $this->line("Parol: {$password}");
        $this->warn('Kirgandan keyin parolni almashtiring.');

        return self::SUCCESS;
    }
}
