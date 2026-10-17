<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
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
            'pg_dump', '--format=custom', '--no-owner', '--file='.$file, ...$this->connectionArgs(),
        ]);
        if ($result->failed()) {
            throw new RuntimeException('pg_dump: '.$result->errorOutput());
        }

        return $file;
    }

    public function restore(string $file): void
    {
        $result = Process::timeout(3600)->env($this->env())->run([
            'pg_restore', '--clean', '--if-exists', '--no-owner', '--single-transaction', ...$this->connectionArgs(dbFlag: true), $file,
        ]);
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
