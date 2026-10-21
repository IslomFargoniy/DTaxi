<?php

namespace App\Support;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Baza zaxirasi (pg_dump custom format). Kunlik schedule va har yangilanishdan oldin (Bosqich 3b).
 * Fayllar arxivi kunlik, suhbat yozuvlari esa alohida — har kuni faqat kechagi kun yozuvlari zaxira serveriga.
 */
class Backup
{
    /** Suhbat yozuvlari (ProcessRecording): recordings/{filial}/{Y}/{m}/{d}/{id}.mp3 */
    public const RECORDINGS = 'storage/app/private/recordings';

    public function directory(): string
    {
        return storage_path('app/private/backups');
    }

    /**
     * Oxirgi yangilanish holati (ibtaxi:update): commit va yangilanishdan oldingi zaxira.
     */
    public static function updateStateFile(): string
    {
        return storage_path('app/private/update-state.json');
    }

    public function create(string $label = 'daily'): string
    {
        File::ensureDirectoryExists($this->directory());
        $file = $this->directory().'/'.now()->format('Y-m-d_His')."_{$label}.dump";

        $result = Process::timeout(3600)->env($this->env())->run([
            'pg_dump', '--format=custom', '--no-owner', '--exclude-table-data=public.spatial_ref_sys', '--file='.$file, ...$this->connectionArgs(),
        ]);
        if ($result->failed()) {
            throw new RuntimeException('pg_dump: '.$result->errorOutput());
        }
        @chmod($file, 0600);

        return $file;
    }

    /**
     * Fayllar zaxirasi: haydovchi hujjatlari, ovozli bo'laklar, APK va `.env`.
     * `.env` dagi APP_KEY siz bazadagi shifrlangan kalitlarni (Click, Payme, SIP) tiklab bo'lmaydi.
     * Suhbat yozuvlari kiritilmaydi — ular oyiga GB'lab o'sadi va har kuni to'liq nusxalanmasligi kerak (createRecordings).
     */
    public function createFiles(string $label = 'daily'): string
    {
        File::ensureDirectoryExists($this->directory());
        $file = $this->directory().'/'.now()->format('Y-m-d_His')."_{$label}.files.tar.gz";

        $result = Process::timeout(3600)->path(base_path())->run([
            'tar', '--create', '--gzip', '--file='.$file,
            '--exclude=storage/app/private/backups',
            '--exclude='.self::RECORDINGS,
            '.env', 'storage/app/private',
        ]);
        if ($result->failed()) {
            throw new RuntimeException('tar: '.$result->errorOutput());
        }
        @chmod($file, 0600);

        return $file;
    }

    /**
     * Bir kunlik suhbat yozuvlari arxivi (MP3 allaqachon siqilgan — gzip'siz). Shu kun yozuvi bo'lmasa — null.
     * Kun tugagach yozuvlar o'zgarmaydi, shuning uchun har kuni faqat kechagi kun yuboriladi (o'sib boruvchi zaxira).
     */
    public function createRecordings(CarbonInterface $day, string $label = 'recordings'): ?string
    {
        $dirs = collect(File::glob(base_path(self::RECORDINGS).'/*/'.$day->format('Y/m/d'), GLOB_ONLYDIR))
            ->map(fn (string $dir) => ltrim(substr($dir, strlen(base_path())), '/'))
            ->all();
        if ($dirs === []) {
            return null;
        }

        File::ensureDirectoryExists($this->directory());
        $file = $this->directory().'/'.$day->format('Y-m-d')."_{$label}.tar";
        $result = Process::timeout(3600)->path(base_path())->run(['tar', '--create', '--file='.$file, ...$dirs]);
        if ($result->failed()) {
            throw new RuntimeException('tar: '.$result->errorOutput());
        }
        @chmod($file, 0600);

        return $file;
    }

    /**
     * Fayllar arxivini tiklash. `.env` ustidan yozilmaydi (yangi serverning baza paroli va domeni boshqa) —
     * faqat APP_KEY olinadi, aks holda bazadagi shifrlangan kalitlar o'qilmaydi; eski `.env` yoniga `.env.restored` bo'lib tushadi.
     *
     * @return bool APP_KEY almashtirildimi
     */
    public function restoreFiles(string $archive, ?string $root = null): bool
    {
        $root ??= base_path();
        $tmp = $root.'/storage/app/restore-tmp';
        File::deleteDirectory($tmp);
        File::ensureDirectoryExists($tmp);

        try {
            $result = Process::timeout(3600)->path($tmp)->run(['tar', '--extract', '--file='.$archive]);
            if ($result->failed()) {
                throw new RuntimeException('tar: '.$result->errorOutput());
            }

            if (File::isDirectory($tmp.'/storage/app/private')) {
                File::copyDirectory($tmp.'/storage/app/private', $root.'/storage/app/private');
            }

            if (! File::exists($tmp.'/.env')) {
                return false;
            }
            File::copy($tmp.'/.env', $root.'/.env.restored');
            @chmod($root.'/.env.restored', 0600);

            $key = $this->envValue((string) File::get($tmp.'/.env'), 'APP_KEY');
            $envFile = $root.'/.env';
            $env = File::exists($envFile) ? (string) File::get($envFile) : '';
            if ($key === null || $key === $this->envValue($env, 'APP_KEY')) {
                return false;
            }
            $line = 'APP_KEY='.$key;
            File::put($envFile, preg_match('/^APP_KEY=.*$/m', $env)
                ? (string) preg_replace('/^APP_KEY=.*$/m', addcslashes($line, '\\$'), $env)
                : rtrim($env)."\n".$line."\n");

            return true;
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    /**
     * Zaxira nusxasini firmaning boshqa serveriga (SFTP) yuborish; sozlanmagan bo'lsa — false.
     */
    public function upload(string $file): bool
    {
        $host = Integration::credential(IntegrationProvider::BackupServer, 'host');
        if (! $host) {
            return false;
        }

        $disk = Storage::build([
            'driver' => 'sftp',
            'host' => $host,
            'port' => (int) (Integration::credential(IntegrationProvider::BackupServer, 'port') ?: 22),
            'username' => (string) Integration::credential(IntegrationProvider::BackupServer, 'username'),
            'password' => (string) Integration::credential(IntegrationProvider::BackupServer, 'password'),
            'root' => (string) (Integration::credential(IntegrationProvider::BackupServer, 'path') ?: '/'),
            'timeout' => 30,
            'throw' => true,
        ]);

        $stream = fopen($file, 'r');
        if ($stream === false) {
            throw new RuntimeException("Faylni o'qib bo'lmadi: {$file}");
        }
        try {
            return $disk->writeStream(basename($file), $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function restore(string $file): void
    {
        // Kengaytmalar (PostGIS, pg_trgm) va ularning jadvali (spatial_ref_sys) superuser'niki — ularni o'chirib-qayta yaratishga ruxsat yo'q, ro'yxatdan chiqariladi
        $list = Process::run(['pg_restore', '--list', $file]);
        if ($list->failed()) {
            throw new RuntimeException('pg_restore --list: '.$list->errorOutput());
        }
        $listFile = $file.'.list';
        File::put($listFile, collect(explode("\n", $list->output()))
            ->reject(fn (string $line) => str_contains($line, ' EXTENSION ') || str_contains($line, 'spatial_ref_sys'))
            ->implode("\n"));

        $result = Process::timeout(3600)->env($this->env())->run([
            'pg_restore', '--clean', '--if-exists', '--no-owner', '--single-transaction', '--use-list='.$listFile,
            ...$this->connectionArgs(dbFlag: true), $file,
        ]);
        File::delete($listFile);
        if ($result->failed()) {
            throw new RuntimeException('pg_restore: '.$result->errorOutput());
        }
    }

    /**
     * Muddati o'tgan zaxiralarni o'chirish. Oxirgi yangilanishdan oldingi zaxira (--rollback uchun) keyingi yangilanishgacha saqlanadi.
     */
    public function prune(int $keepDays = 14): int
    {
        $deleted = 0;
        $keep = $this->lastUpdateBackup();
        $files = [...File::glob($this->directory().'/*.dump'), ...File::glob($this->directory().'/*.files.tar.gz'), ...File::glob($this->directory().'/*_recordings.tar')];
        foreach ($files as $file) {
            if ($file === $keep) {
                continue;
            }
            if (filemtime($file) < now()->subDays($keepDays)->getTimestamp()) {
                File::delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function lastUpdateBackup(): ?string
    {
        if (! File::exists(self::updateStateFile())) {
            return null;
        }
        $state = json_decode((string) File::get(self::updateStateFile()), true);

        return is_array($state) && is_string($state['backup'] ?? null) ? $state['backup'] : null;
    }

    private function envValue(string $env, string $key): ?string
    {
        return preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $env, $m) && trim($m[1]) !== '' ? trim($m[1]) : null;
    }

    /**
     * @return list<string>
     */
    private function connectionArgs(bool $dbFlag = false): array
    {
        /** @var array{host: string, port: string|int, username: string, database: string} $c */
        $c = config('database.connections.'.config('database.default'));

        return [
            '--host='.$c['host'],
            '--port='.$c['port'],
            '--username='.$c['username'],
            ($dbFlag ? '--dbname=' : '').$c['database'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function env(): array
    {
        return ['PGPASSWORD' => (string) config('database.connections.'.config('database.default').'.password')];
    }
}
