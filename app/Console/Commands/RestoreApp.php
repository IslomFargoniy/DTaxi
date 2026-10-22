<?php

namespace App\Console\Commands;

use App\Support\Backup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Zaxiradan tiklash (yangi serverga ko'chish yoki halokatdan keyin): baza, fayllar, suhbat yozuvlari.
 */
class RestoreApp extends Command
{
    protected $signature = 'dtaxi:restore
        {dump : Baza zaxirasi (*.dump)}
        {--files=* : Fayllar arxivi (*.files.tar.gz) va/yoki suhbat yozuvlari (*_recordings.tar), bir nechta bo\'lishi mumkin}';

    protected $description = 'Baza va fayllarni zaxiradan tiklash';

    public function handle(Backup $backup): int
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->error('root sifatida ishlatmang: sudo -u dtaxi php artisan dtaxi:restore ...');

            return self::FAILURE;
        }

        $dump = $this->argument('dump');
        $dump = is_string($dump) ? $dump : '';
        $archives = array_values(array_filter((array) $this->option('files'), 'is_string'));
        foreach ([$dump, ...$archives] as $file) {
            if (! File::isFile($file)) {
                $this->error("Fayl topilmadi: {$file}");

                return self::FAILURE;
            }
        }

        if (! $this->confirm("Joriy baza {$dump} bilan almashtiriladi, hozirgi ma'lumotlar yo'qoladi. Davom etilsinmi?")) {
            return self::FAILURE;
        }

        $this->call('down', ['--retry' => 15]);
        try {
            $this->line('<fg=green>==></> Baza');
            $backup->restore($dump);

            $keyChanged = false;
            foreach ($archives as $archive) {
                $this->line("<fg=green>==></> Fayllar: {$archive}");
                $keyChanged = $backup->restoreFiles($archive) || $keyChanged;
            }
            if ($keyChanged) {
                $this->warn('APP_KEY zaxiradagisiga almashtirildi (shifrlangan kalitlar uchun). Eski .env nusxasi: .env.restored');
            }

            // Yangi APP_KEY va sozlamalar bilan — alohida jarayonda
            $this->line('<fg=green>==></> Kesh, telefoniya va fon xizmatlari');
            foreach (['optimize', 'telephony:sync', 'horizon:terminate', 'reverb:restart', 'ari:restart'] as $command) {
                $result = Process::path(base_path())->timeout(600)->run([PHP_BINARY, 'artisan', $command]);
                if ($result->failed()) {
                    throw new \RuntimeException("{$command}: ".trim($result->errorOutput() ?: $result->output()));
                }
            }
        } catch (\Throwable $e) {
            $this->error('Tiklash to\'xtadi: '.$e->getMessage());
            $this->warn('Sayt texnik rejimda qoldi. Tuzatib, qayta ishga tushiring yoki: sudo -u dtaxi php artisan up');

            return self::FAILURE;
        }

        $this->call('up');
        $this->info('Tiklandi.');

        return self::SUCCESS;
    }
}
