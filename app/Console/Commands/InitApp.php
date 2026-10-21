<?php

namespace App\Console\Commands;

use Database\Seeders\FirstRunSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Birinchi ishga tushirish (qaror #58): filial va superadmin; parol faqat bir marta ko'rsatiladi.
 * install.sh oxirida chaqiriladi, qayta chaqirish xavfsiz.
 */
class InitApp extends Command
{
    protected $signature = 'ibtaxi:init
        {--company= : Firma (xizmat) nomi, standart — Dtaxi}
        {--username=admin : Superadmin logini}
        {--email= : Superadmin e-mail\'i (ixtiyoriy, parolni tiklash uchun)}';

    protected $description = 'Birinchi filial va superadmin yaratish (parol tasodifiy; lokal muhitda — sinov paroli)';

    public function handle(): int
    {
        $seeder = new FirstRunSeeder;
        $company = $this->option('company');
        if (is_string($company) && trim($company) !== '') {
            $seeder->company = trim($company);
        }
        $option = $this->option('username');
        $username = is_string($option) ? strtolower(trim($option)) : '';
        if (preg_match('/^[a-z0-9._-]{3,50}$/', $username) !== 1) {
            $this->error("Noto'g'ri login: {$username}");

            return self::FAILURE;
        }
        $seeder->username = $username;
        $email = $this->option('email');
        if (is_string($email) && $email !== '') {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->error("Noto'g'ri e-mail: {$email}");

                return self::FAILURE;
            }
            $seeder->email = $email;
        }

        $seeder->run();

        if ($seeder->credentials === null) {
            $this->info('Superadmin allaqachon mavjud — hech narsa o\'zgartirilmadi.');
            $this->line('Parolni tiklash: php artisan ibtaxi:admin-password');

            return self::SUCCESS;
        }

        $url = rtrim((string) config('app.url'), '/').'/login';
        $text = "Dtaxi — birinchi kirish\n"
            ."Manzil: {$url}\n"
            ."Login:  {$seeder->credentials['login']}\n"
            ."Parol:  {$seeder->credentials['password']}\n";

        $file = self::credentialsFile();
        File::ensureDirectoryExists(dirname($file));
        File::put($file, $text."\nBirinchi kirishdan keyin parolni almashtiring va bu faylni o'chiring.\n");
        @chmod($file, 0600);

        $this->newLine();
        foreach (explode("\n", trim($text)) as $line) {
            $this->line($line);
        }
        $this->newLine();
        $this->warn("Bu ma'lumot qayta ko'rsatilmaydi. Nusxasi: {$file} — birinchi kirishdan keyin o'chiring.");

        return self::SUCCESS;
    }

    public static function credentialsFile(): string
    {
        return storage_path('app/private/first-login.txt');
    }
}
