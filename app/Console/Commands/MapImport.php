<?php

namespace App\Console\Commands;

use App\Support\Backup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Tuzatilgan xaritani yangi firma serveriga ko'chirish (qaror #65): hududlar, mo'ljallar va ko'cha nomlari
 * xarita to'plamidan (ibtaxi:map-export) — buyurtma, mijoz va pul ma'lumotlarisiz. map-restore.sh chaqiradi.
 */
class MapImport extends Command
{
    protected $signature = 'ibtaxi:map-import
        {file : Xarita to\'plami (*_map.tar) yoki undagi map.dump}
        {--force : Filial yoki mijoz manzillari mavjud xaritaga bog\'langan bo\'lsa ham almashtirish}';

    protected $description = 'Xarita to\'plamidagi hududlar, mo\'ljallar va ko\'cha nomlarini bazaga yuklash (mavjudlari o\'chiriladi)';

    public function handle(Backup $backup): int
    {
        $file = $this->argument('file');
        $file = is_string($file) ? $file : '';
        if (! File::isFile($file)) {
            $this->error("Fayl topilmadi: {$file}");

            return self::FAILURE;
        }

        if (! $this->confirm("Bazadagi hududlar, mo'ljallar va ko'cha nomlari {$file} dagisiga almashtiriladi. Davom etilsinmi?", true)) {
            return self::FAILURE;
        }

        $tmp = null;
        try {
            $dump = $file;
            if (str_ends_with($file, '.tar')) {
                $tmp = storage_path('app/map-import-tmp');
                File::deleteDirectory($tmp);
                File::ensureDirectoryExists($tmp);
                $result = Process::timeout(600)->run(['tar', '--extract', '--file='.$file, '-C', $tmp, Backup::MAP_DUMP]);
                if ($result->failed()) {
                    $this->error("To'plamda bazadagi xarita qismi (map.dump) yo'q — eski to'plam: regions:import va landmarks:import ishlating.");

                    return self::FAILURE;
                }
                $dump = $tmp.'/'.Backup::MAP_DUMP;
            }

            $counts = $backup->importMapDump($dump, (bool) $this->option('force'));
        } catch (\Throwable $e) {
            $this->error('Xarita yuklanmadi (baza o\'zgarmadi): '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if ($tmp !== null) {
                File::deleteDirectory($tmp);
            }
        }

        $this->info("Tayyor: {$counts['regions']} hudud, {$counts['landmarks']} mo'ljal, {$counts['street_renames']} ko'cha nomi.");

        return self::SUCCESS;
    }
}
