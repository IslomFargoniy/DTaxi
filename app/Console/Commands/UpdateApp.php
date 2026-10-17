<?php

namespace App\Console\Commands;

use App\Support\Backup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Xavfsiz yangilanish (Bosqich 3b): zaxira → kod → bog'liqliklar → frontend → migratsiya → fon xizmatlarini qayta ishga tushirish.
 * Muammo bo'lsa: `php artisan ibtaxi:update --rollback` — oldingi kod va yangilanishdan oldingi baza.
 */
class UpdateApp extends Command
{
    protected $signature = 'ibtaxi:update
        {--ref= : Git teg yoki tarmoq (standart — joriy tarmoqning oxirgi holati)}
        {--rollback : Oxirgi yangilanishni bekor qilish}
        {--skip-build : Frontend build qilinmasin (faqat backend o\'zgargan bo\'lsa)}';

    protected $description = 'ibTaxi\'ni yangilash (zaxira va qaytarish imkoni bilan)';

    public function handle(Backup $backup): int
    {
        return $this->option('rollback') ? $this->rollback($backup) : $this->update($backup);
    }

    private function update(Backup $backup): int
    {
        $previous = $this->git(['rev-parse', 'HEAD']);
        $this->info("Joriy versiya: {$this->version()} ({$previous})");

        $this->step('Zaxira');
        $dump = $backup->create('before-update');
        File::put($this->stateFile(), (string) json_encode(['commit' => $previous, 'backup' => $dump, 'at' => now()->toIso8601String()]));

        $this->call('down', ['--retry' => 15]);
        try {
            $this->step('Kod');
            $this->git(['fetch', '--tags', '--quiet']);
            $ref = $this->option('ref');
            if (is_string($ref) && $ref !== '') {
                $this->git(['checkout', '--quiet', $ref]);
            } else {
                $this->git(['pull', '--ff-only', '--quiet']);
            }

            $this->install();
            $this->call('migrate', ['--force' => true]);
            $this->finish();
        } catch (\Throwable $e) {
            $this->call('up');
            $this->error('Yangilanish to\'xtadi: '.$e->getMessage());
            $this->warn('Qaytarish: php artisan ibtaxi:update --rollback');

            return self::FAILURE;
        }

        $this->info("Tayyor: {$this->version()} ({$this->git(['rev-parse', '--short', 'HEAD'])})");

        return self::SUCCESS;
    }

    private function rollback(Backup $backup): int
    {
        if (! File::exists($this->stateFile())) {
            $this->error('Oldingi yangilanish haqida ma\'lumot yo\'q.');

            return self::FAILURE;
        }
        /** @var array{commit: string, backup: string} $state */
        $state = json_decode((string) File::get($this->stateFile()), true);

        if (! $this->confirm("Kod {$state['commit']} ga va baza {$state['backup']} holatiga qaytarilsinmi? Yangilanishdan keyingi ma'lumotlar yo'qoladi.", true)) {
            return self::FAILURE;
        }

        $this->call('down', ['--retry' => 15]);
        $this->step('Kod');
        $this->git(['checkout', '--quiet', $state['commit']]);
        $this->install();
        $this->step('Baza');
        $backup->restore($state['backup']);
        $this->finish();
        File::delete($this->stateFile());

        $this->info("Qaytarildi: {$this->version()} ({$state['commit']})");

        return self::SUCCESS;
    }

    private function install(): void
    {
        $this->step('Bog\'liqliklar');
        $this->exec(['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction']);

        if (! $this->option('skip-build')) {
            $this->step('Frontend');
            $this->exec(['npm', 'ci', '--no-audit', '--no-fund']);
            $this->exec(['npm', 'run', 'build']);
        }
    }

    private function finish(): void
    {
        $this->step('Kesh va fon xizmatlari');
        $this->call('optimize');
        $this->call('horizon:terminate');
        $this->call('reverb:restart');
        $this->call('ari:restart');
        $this->call('telephony:sync');
        $this->call('up');
    }

    /**
     * @param  list<string>  $command
     */
    private function exec(array $command): string
    {
        $result = Process::path(base_path())->timeout(1800)->run($command);
        if ($result->failed()) {
            throw new \RuntimeException(implode(' ', $command).': '.trim($result->errorOutput() ?: $result->output()));
        }

        return trim($result->output());
    }

    /**
     * @param  list<string>  $args
     */
    private function git(array $args): string
    {
        return $this->exec(['git', ...$args]);
    }

    private function step(string $title): void
    {
        $this->line("<fg=green>==></> {$title}");
    }

    private function version(): string
    {
        return (string) config('app.version');
    }

    private function stateFile(): string
    {
        return storage_path('app/private/update-state.json');
    }
}
