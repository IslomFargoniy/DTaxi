<?php

use App\Models\Branch;
use App\Models\Call;
use App\Support\Backup;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

// Haqiqiy `optimize` bootstrap/cache ga yozadi va yangi ilova nusxasini yaratadi (test tranzaksiyasini ko'rmaydi)
beforeEach(fn () => app(Kernel::class)->registerCommand(new ClosureCommand('optimize', fn () => 0)));

afterEach(function () {
    File::delete(Backup::updateStateFile());
    if (app()->isDownForMaintenance()) {
        Artisan::call('up');
    }
});

/**
 * Soxta git/composer/npm: HEAD holati saqlanadi, $failComposerOn — shu commit'da composer xato beradi.
 *
 * @param  list<string>  $log
 * @return array{head: string}
 */
function fakeUpdateProcesses(array &$log, string $failComposerOn = '', bool $detached = false, bool $asteriskChanged = false): array
{
    $state = ['head' => 'old'];
    Process::fake(function (PendingProcess $process) use (&$log, &$state, $failComposerOn, $detached, $asteriskChanged) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $log[] = $command;

        return match (true) {
            $command === 'git rev-parse HEAD' => Process::result($state['head']),
            $command === 'git symbolic-ref --quiet HEAD' => Process::result(exitCode: $detached ? 1 : 0),
            $command === 'git rev-parse --abbrev-ref origin/HEAD' => Process::result(exitCode: 128),
            str_starts_with($command, 'git checkout --quiet ') => tap(Process::result(), function () use (&$state, $command) {
                $state['head'] = substr($command, strlen('git checkout --quiet '));
            }),
            str_starts_with($command, 'git pull') => tap(Process::result(), function () use (&$state) {
                $state['head'] = 'new';
            }),
            str_starts_with($command, 'git diff --quiet') => Process::result(exitCode: $asteriskChanged ? 1 : 0),
            str_starts_with($command, 'composer') && $state['head'] === $failComposerOn => Process::result(errorOutput: 'yangi paket topilmadi', exitCode: 1),
            default => Process::result(),
        };
    });

    return $state;
}

test('failed update goes back to the previous commit with its dependencies and opens the site', function () {
    $log = [];
    fakeUpdateProcesses($log, failComposerOn: 'new');

    $this->artisan('dtaxi:update', ['--skip-build' => true])
        ->expectsOutputToContain('Oldingi kod tiklandi')
        ->assertFailed();

    $composer = array_values(array_filter($log, fn ($c) => str_starts_with($c, 'composer')));
    expect($log)->toContain('git checkout --quiet old')
        ->and($composer)->toHaveCount(2)
        ->and(app()->isDownForMaintenance())->toBeFalse()
        ->and(json_decode(File::get(Backup::updateStateFile()), true)['commit'])->toBe('old');
});

test('plain update after --ref first returns to the main branch', function () {
    $log = [];
    fakeUpdateProcesses($log, failComposerOn: 'new', detached: true);

    $this->artisan('dtaxi:update', ['--skip-build' => true])->assertFailed();

    expect(array_search('git checkout --quiet main', $log))->toBeLessThan(array_search('git pull --ff-only --quiet', $log));
});

test('successful update waits for active calls and tells how to apply changed Asterisk configs', function () {
    Sleep::fake(syncWithCarbon: true);
    $log = [];
    fakeUpdateProcesses($log, asteriskChanged: true);
    Call::query()->create(['branch_id' => Branch::factory()->create()->id, 'direction' => 'inbound', 'kind' => 'client',
        'caller' => '+998901111111', 'status' => 'answered', 'started_at' => now()]);

    $this->artisan('dtaxi:update', ['--skip-build' => true, '--wait-calls' => 10])
        ->expectsOutputToContain("Faol qo'ng'iroqlar (1)")
        ->expectsOutputToContain('qayta ishga tushirilmoqda')
        ->expectsOutputToContain('deploy/asterisk-config.sh')
        ->assertSuccessful();

    Sleep::assertSleptTimes(2);
    expect(app()->isDownForMaintenance())->toBeFalse();
});

test('prune keeps the dump of the last update for rollback', function () {
    // Alohida papka: prune haqiqiy backups/ dagi eski zaxiralarni o'chirib yuborardi
    $directory = storage_path('framework/testing/prune-backups');
    $backup = Mockery::mock(Backup::class)->makePartial();
    $backup->shouldReceive('directory')->andReturn($directory);
    File::ensureDirectoryExists($directory);
    $old = $directory.'/2000-01-01_000000_daily.dump';
    $kept = $directory.'/2000-01-01_000000_before-update.dump';
    File::put($old, 'x');
    File::put($kept, 'x');
    touch($old, now()->subDays(30)->getTimestamp());
    touch($kept, now()->subDays(30)->getTimestamp());
    $state = File::exists(Backup::updateStateFile()) ? File::get(Backup::updateStateFile()) : null;
    File::put(Backup::updateStateFile(), json_encode(['commit' => 'old', 'backup' => $kept]));

    try {
        expect($backup->prune(14))->toBe(1)
            ->and(File::exists($old))->toBeFalse()
            ->and(File::exists($kept))->toBeTrue();
    } finally {
        File::deleteDirectory($directory);
        $state === null ? File::delete(Backup::updateStateFile()) : File::put(Backup::updateStateFile(), $state);
    }
});

test('call recordings are archived per day, not in the daily files archive', function () {
    $day = now()->subDay();
    $recording = storage_path('app/private/recordings/test-branch/'.$day->format('Y/m/d').'/1.mp3');
    File::ensureDirectoryExists(dirname($recording));
    File::put($recording, 'mp3');
    $backup = app(Backup::class);

    try {
        $files = $backup->createFiles('test');
        $recordings = $backup->createRecordings($day, 'test_recordings');

        expect(Process::run(['tar', '--list', '--gzip', '--file='.$files])->output())->not->toContain('recordings/')
            ->and(Process::run(['tar', '--list', '--file='.$recordings])->output())->toContain('recordings/test-branch/'.$day->format('Y/m/d').'/1.mp3')
            ->and($backup->createRecordings(now()->subYears(30)))->toBeNull();
    } finally {
        File::deleteDirectory(storage_path('app/private/recordings/test-branch'));
        File::delete(array_filter([$files ?? null, $recordings ?? null]));
    }
});

test('files restore copies uploads and takes only APP_KEY from the archived .env', function () {
    $source = sys_get_temp_dir().'/dtaxi-restore-src-'.uniqid();
    $root = sys_get_temp_dir().'/dtaxi-restore-dst-'.uniqid();
    File::ensureDirectoryExists($source.'/storage/app/private/drivers');
    File::put($source.'/storage/app/private/drivers/license.jpg', 'jpg');
    File::put($source.'/.env', "APP_KEY=base64:ESKI\nDB_PASSWORD=eski-parol\n");
    File::ensureDirectoryExists($root.'/storage/app/private');
    File::put($root.'/.env', "APP_KEY=base64:YANGI\nDB_PASSWORD=yangi-parol\n");
    $archive = $source.'.tar.gz';
    Process::path($source)->run(['tar', '--create', '--gzip', '--file='.$archive, '.env', 'storage/app/private'])->throw();

    try {
        expect(app(Backup::class)->restoreFiles($archive, $root))->toBeTrue()
            ->and(File::get($root.'/storage/app/private/drivers/license.jpg'))->toBe('jpg')
            ->and(File::get($root.'/.env'))->toBe("APP_KEY=base64:ESKI\nDB_PASSWORD=yangi-parol\n")
            ->and(File::get($root.'/.env.restored'))->toContain('eski-parol')
            ->and(File::isDirectory($root.'/storage/app/restore-tmp'))->toBeFalse();
    } finally {
        File::deleteDirectory($source);
        File::deleteDirectory($root);
        File::delete($archive);
    }
});

test('dtaxi:restore asks for confirmation and restores the database in maintenance mode', function () {
    $this->artisan('dtaxi:restore', ['dump' => '/yoq/fayl.dump'])->expectsOutputToContain('Fayl topilmadi')->assertFailed();

    $dump = tempnam(sys_get_temp_dir(), 'dump');
    $log = [];
    Process::fake(function (PendingProcess $process) use (&$log) {
        $log[] = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return Process::result();
    });

    try {
        $this->artisan('dtaxi:restore', ['dump' => $dump])->expectsConfirmation("Joriy baza {$dump} bilan almashtiriladi, hozirgi ma'lumotlar yo'qoladi. Davom etilsinmi?", 'no')->assertFailed();
        expect($log)->toBe([]);

        $this->artisan('dtaxi:restore', ['dump' => $dump])->expectsConfirmation("Joriy baza {$dump} bilan almashtiriladi, hozirgi ma'lumotlar yo'qoladi. Davom etilsinmi?", 'yes')->assertSuccessful();
        expect(collect($log)->first(fn ($c) => str_contains($c, 'pg_restore --clean --if-exists --no-owner')))->not->toBeNull()
            ->and(collect($log)->first(fn ($c) => str_ends_with($c, 'artisan telephony:sync')))->not->toBeNull()
            ->and(app()->isDownForMaintenance())->toBeFalse();
    } finally {
        File::delete($dump);
    }
});
