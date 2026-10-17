<?php

namespace App\Support;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Baza zaxirasi (pg_dump custom format). Kunlik schedule va har yangilanishdan oldin (Bosqich 3b).
 */
class Backup
{
    public function directory(): string
    {
        return storage_path('app/private/backups');
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

        return $file;
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
     * Muddati o'tgan zaxiralarni o'chirish.
     */
    public function prune(int $keepDays = 14): int
    {
        $deleted = 0;
        foreach (File::glob($this->directory().'/*.dump') as $file) {
            if (filemtime($file) < now()->subDays($keepDays)->getTimestamp()) {
                File::delete($file);
                $deleted++;
            }
        }

        return $deleted;
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
