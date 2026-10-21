<?php

namespace App\Console\Commands;

use App\Models\Call;
use App\Support\Backup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

/**
 * Xavfsiz yangilanish (Bosqich 3b): zaxira → kod → bog'liqliklar → frontend → migratsiya → fon xizmatlarini qayta ishga tushirish.
 * Muammo bo'lsa: `php artisan ibtaxi:update --rollback` — oldingi kod va yangilanishdan oldingi baza.
 */
class UpdateApp extends Command
{
    protected $signature = 'ibtaxi:update
        {--ref= : Git teg yoki tarmoq (standart — asosiy tarmoqning oxirgi holati)}
        {--rollback : Oxirgi yangilanishni bekor qilish}
        {--skip-build : Frontend build qilinmasin (faqat backend o\'zgargan bo\'lsa)}
        {--wait-calls=600 : ARI daemon qayta ishga tushishidan oldin faol qo\'ng\'iroqlar tugashini necha soniya kutish}';

    protected $description = 'Dtaxi\'ni yangilash (zaxira va qaytarish imkoni bilan)';

    public function handle(Backup $backup): int
    {
        // root nomidan git "dubious ownership" beradi, yangi fayllar esa root'ga o'tib php-fpm o'qiy olmaydi
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->error('root sifatida ishlatmang: sudo -u ibtaxi php artisan '.$this->getName().($this->option('rollback') ? ' --rollback' : ''));

            return self::FAILURE;
        }

        return $this->option('rollback') ? $this->rollback($backup) : $this->update($backup);
    }

    private function update(Backup $backup): int
    {
        $previous = $this->git(['rev-parse', 'HEAD']);
        $this->info("Joriy versiya: {$this->version()} ({$previous})");

        $this->step('Zaxira');
        $dump = $backup->create('before-update');
        File::put(Backup::updateStateFile(), (string) json_encode(['commit' => $previous, 'backup' => $dump, 'at' => now()->toIso8601String()]));

        $this->call('down', ['--retry' => 15]);
        $codeChanged = false;
        try {
            $this->step('Kod');
            $this->git(['fetch', '--tags', '--quiet']);
            $ref = $this->option('ref');
            if (is_string($ref) && $ref !== '') {
                $this->git(['checkout', '--quiet', $ref]);
            } else {
                // Oldingi `--ref=<teg>` dan keyin HEAD tarmoqda emas — pull ishlamaydi, avval asosiy tarmoqqa qaytiladi
                if (! $this->onBranch()) {
                    $this->git(['checkout', '--quiet', $this->mainBranch()]);
                }
                $this->git(['pull', '--ff-only', '--quiet']);
            }
            $codeChanged = $this->git(['rev-parse', 'HEAD']) !== $previous;

            $this->install();
            if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
                throw new \RuntimeException('migrate');
            }
        } catch (\Throwable $e) {
            $this->error('Yangilanish to\'xtadi: '.$e->getMessage());

            return $this->revert($previous, $codeChanged);
        }

        $this->finish();
        $this->warnIfAsteriskChanged($previous);
        $this->info("Tayyor: {$this->version()} ({$this->git(['rev-parse', '--short', 'HEAD'])})");

        return self::SUCCESS;
    }

    /**
     * Yangilanish yarim yo'lda to'xtasa: yangi kod eski bog'liqliklar bilan qolmasin — oldingi commit va uning bog'liqliklari.
     */
    private function revert(string $previous, bool $codeChanged): int
    {
        try {
            if ($codeChanged || $this->git(['rev-parse', 'HEAD']) !== $previous) {
                $this->step('Oldingi kodga qaytish');
                $this->git(['checkout', '--quiet', $previous]);
                $this->install();
                $this->call('optimize');
            }
        } catch (\Throwable $e) {
            // Kod va bog'liqliklar mos emas — sayt ochilmaydi, texnik rejimda qoladi
            $this->error('Oldingi kodga qaytib bo\'lmadi: '.$e->getMessage());
            $this->warn('Sayt texnik rejimda qoldi. Muammoni tuzatib: sudo -u ibtaxi php artisan ibtaxi:update --rollback');

            return self::FAILURE;
        }

        $this->call('up');
        $this->warn('Oldingi kod tiklandi. Migratsiya qisman bajarilgan bo\'lishi mumkin; baza ham kerak bo\'lsa: sudo -u ibtaxi php artisan ibtaxi:update --rollback');

        return self::FAILURE;
    }

    private function rollback(Backup $backup): int
    {
        if (! File::exists(Backup::updateStateFile())) {
            $this->error('Oldingi yangilanish haqida ma\'lumot yo\'q.');

            return self::FAILURE;
        }
        /** @var array{commit: string, backup: string} $state */
        $state = json_decode((string) File::get(Backup::updateStateFile()), true);

        if (! $this->confirm("Kod {$state['commit']} ga va baza {$state['backup']} holatiga qaytarilsinmi? Yangilanishdan keyingi ma'lumotlar yo'qoladi.", true)) {
            return self::FAILURE;
        }

        $current = $this->git(['rev-parse', 'HEAD']);
        $this->call('down', ['--retry' => 15]);
        try {
            $this->step('Kod');
            $this->git(['checkout', '--quiet', $state['commit']]);
            $this->install();
            $this->step('Baza');
            $backup->restore($state['backup']);
        } catch (\Throwable $e) {
            $this->error('Qaytarish to\'xtadi: '.$e->getMessage());
            $this->warn('Sayt texnik rejimda qoldi. Tuzatib, qayta ishga tushiring: sudo -u ibtaxi php artisan ibtaxi:update --rollback');

            return self::FAILURE;
        }
        $this->finish();
        File::delete(Backup::updateStateFile());
        $this->warnIfAsteriskChanged($current);

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
        $this->call('telephony:sync');
        // Sayt qo'ng'iroqlar tugashini kutmasdan ochiladi — operatorlar buyurtma qabul qilishda davom etadi
        $this->call('up');

        $this->waitForCalls();
        $this->call('ari:restart');
    }

    /**
     * ARI daemon qayta ishga tushsa davom etayotgan qo'ng'iroqlar yopilgan deb belgilanadi — avval ular tugashi kutiladi.
     */
    private function waitForCalls(): void
    {
        $timeout = (int) $this->option('wait-calls');
        $deadline = now()->addSeconds($timeout);
        $notified = false;

        while (($active = $this->activeCalls()) > 0) {
            if ($timeout <= 0 || now()->greaterThanOrEqualTo($deadline)) {
                $this->warn("{$active} ta qo'ng'iroq hali davom etmoqda — ARI daemon baribir qayta ishga tushirilmoqda.");

                return;
            }
            if (! $notified) {
                $this->line("Faol qo'ng'iroqlar ({$active}) tugashi kutilmoqda, ko'pi bilan {$timeout} soniya...");
                $notified = true;
            }
            Sleep::for(5)->seconds();
        }
    }

    private function activeCalls(): int
    {
        // Osilib qolgan eski yozuvlar kutishni cho'zmasin
        return Call::query()->withoutGlobalScopes()
            ->whereIn('status', Call::ACTIVE)
            ->where('started_at', '>=', now()->subHours(3))
            ->count();
    }

    /**
     * Asterisk shablonlari root huquqi bilan qayta yoziladi — buyruq foydalanuvchiga ko'rsatiladi.
     */
    private function warnIfAsteriskChanged(string $from): void
    {
        $changed = Process::path(base_path())->run(['git', 'diff', '--quiet', $from, 'HEAD', '--', 'deploy/asterisk', 'deploy/asterisk-config.sh'])->exitCode() === 1;
        if ($changed) {
            $this->warn('Asterisk sozlamalari o\'zgardi. Qo\'llash uchun: sudo '.base_path('deploy/asterisk-config.sh'));
        }
    }

    private function onBranch(): bool
    {
        return Process::path(base_path())->run(['git', 'symbolic-ref', '--quiet', 'HEAD'])->successful();
    }

    private function mainBranch(): string
    {
        $remoteHead = Process::path(base_path())->run(['git', 'rev-parse', '--abbrev-ref', 'origin/HEAD']);
        $branch = trim($remoteHead->output());

        return $remoteHead->successful() && str_starts_with($branch, 'origin/') ? substr($branch, 7) : 'main';
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
}
