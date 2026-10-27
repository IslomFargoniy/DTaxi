<?php

use App\Enums\IntegrationProvider;
use App\Models\Driver;
use App\Models\Integration;
use App\Support\Backup;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

afterEach(function () {
    File::delete(Backup::updateStateFile());
    if (app()->isDownForMaintenance()) {
        Artisan::call('up');
    }
});

function scheduledEvent(string $command): Event
{
    $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => str_contains((string) $e->command, $command));
    expect($event)->not->toBeNull();

    return $event;
}

test('night jobs run on Tashkent time while the app stays in UTC', function () {
    expect(config('app.timezone'))->toBe('UTC');

    $backup = scheduledEvent('dtaxi:backup');
    Carbon::setTestNow(Carbon::parse('2026-10-22 12:00', 'UTC'));

    // 02:30 Toshkent = 21:30 UTC
    expect($backup->timezone)->toBe('Asia/Tashkent')
        ->and($backup->nextRunDate()->utc()->format('H:i'))->toBe('21:30')
        ->and($backup->runInBackground)->toBeTrue();
});

test('every overlapping guard expires so a killed run does not block the job forever', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn (Event $e) => $e->withoutOverlapping);

    expect($events)->not->toBeEmpty();
    foreach ($events as $event) {
        // Laravel standarti — 1440 daqiqa (24 soat)
        expect($event->expiresAt)->toBeLessThan(1440, (string) $event->command);
    }
    expect(scheduledEvent('orders:rebroadcast')->expiresAt)->toBe(2)
        ->and(scheduledEvent('dtaxi:map-export')->runInBackground)->toBeTrue();
});

test('trunk-ips fails instead of shrinking the list when a trunk host does not resolve', function () {
    DB::table('sip_trunks')->insert(['name' => 'a', 'title' => 'A', 'host' => '195.158.1.10', 'is_active' => true]);
    $this->artisan('telephony:trunk-ips')->expectsOutput('195.158.1.10')->assertSuccessful();

    DB::table('sip_trunks')->insert(['name' => 'b', 'title' => 'B', 'host' => 'yoq.invalid', 'is_active' => true]);
    $this->artisan('telephony:trunk-ips')->doesntExpectOutput('195.158.1.10')->assertFailed();
});

test('restore drops tables a newer migration created so the old code can migrate again', function () {
    // Zaxira ro'yxati — hozirgi jadvallar; yangi migratsiya jadvali zaxirada yo'q
    $toc = collect(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"))
        ->map(fn ($t, $i) => ($i + 200)."; 1259 1{$i} TABLE public {$t->tablename} dtaxi")->implode("\n");
    $toc .= "\n9000; 0 16420 TABLE DATA public orders dtaxi";
    DB::statement('CREATE TABLE future_feature (id bigserial PRIMARY KEY)');
    DB::statement('CREATE SEQUENCE future_counter');
    DB::statement("CREATE TYPE future_state AS ENUM ('a', 'b')");

    $dump = tempnam(sys_get_temp_dir(), 'dump');
    $log = [];
    Process::fake(function (PendingProcess $process) use (&$log, $toc) {
        $command = implode(' ', (array) $process->command);
        $log[] = $command;

        return str_starts_with($command, 'pg_restore --list') ? Process::result($toc) : Process::result();
    });

    try {
        app(Backup::class)->restore($dump);
    } finally {
        File::delete($dump);
    }

    expect(Schema::hasTable('future_feature'))->toBeFalse()
        ->and(DB::table('pg_class')->where('relname', 'future_counter')->exists())->toBeFalse()
        ->and(DB::table('pg_type')->where('typname', 'future_state')->exists())->toBeFalse()
        ->and(Schema::hasTable('orders'))->toBeTrue()
        ->and(Schema::hasTable('spatial_ref_sys'))->toBeTrue()
        ->and(DB::table('pg_class')->where('relname', 'orders_id_seq')->exists())->toBeTrue()
        ->and(collect($log)->contains(fn ($c) => str_starts_with($c, 'pg_restore --clean')))->toBeTrue();

    // Bo'sh yoki boshqa fayl ro'yxati — hech narsa o'chirilmaydi
    expect(app(Backup::class)->dropObjectsMissingFrom(''))->toBe([]);
});

test('update goes into maintenance before the backup and stops workers before migrating', function () {
    app(Kernel::class)->registerCommand(new ClosureCommand('optimize', fn () => 0));
    $log = [];
    app(Kernel::class)->registerCommand(new ClosureCommand('migrate {--force}', function () use (&$log) {
        $log[] = 'migrate';

        return 0;
    }));
    $head = 'old';
    Process::fake(function (PendingProcess $process) use (&$log, &$head) {
        $command = implode(' ', (array) $process->command);
        $log[] = (str_starts_with($command, 'pg_dump') && app()->isDownForMaintenance() ? 'down:' : '').$command;

        return match (true) {
            $command === 'git rev-parse HEAD' => Process::result($head),
            str_starts_with($command, 'git pull') => tap(Process::result(), function () use (&$head) {
                $head = 'new';
            }),
            default => Process::result(),
        };
    });

    $this->artisan('dtaxi:update', ['--skip-build' => true])->assertSuccessful();

    $at = fn (string $prefix) => collect($log)->search(fn ($c) => str_starts_with($c, $prefix));
    expect($at('down:pg_dump'))->not->toBeFalse()
        ->and($at('sudo -n supervisorctl stop dtaxi-horizon'))->toBeLessThan($at('migrate'))
        ->and($at('sudo -n supervisorctl stop dtaxi-ari'))->toBeLessThan($at('migrate'))
        ->and($at('sudo -n supervisorctl start dtaxi-horizon'))->toBeGreaterThan($at('migrate'))
        ->and($at('sudo -n supervisorctl start dtaxi-ari'))->toBeGreaterThan($at('migrate'))
        ->and(app()->isDownForMaintenance())->toBeFalse();
});

test('update without supervisor access still restarts workers the old way', function () {
    app(Kernel::class)->registerCommand(new ClosureCommand('optimize', fn () => 0));
    // horizon:terminate Redis'ga ulanadi — CI'da Redis yo'q
    app(Kernel::class)->registerCommand(new ClosureCommand('horizon:terminate', fn () => 0));
    Process::fake(fn (PendingProcess $process) => str_starts_with(implode(' ', (array) $process->command), 'sudo')
        ? Process::result(exitCode: 1)
        : Process::result());

    $this->artisan('dtaxi:update', ['--skip-build' => true])
        ->expectsOutputToContain('Supervisor\'ga ruxsat yo\'q')
        ->expectsOutputToContain('ari:listen qayta ishga tushadi')
        ->assertSuccessful();
});

test('failed backup does not leave the site in maintenance', function () {
    Process::fake(fn (PendingProcess $process) => str_starts_with(implode(' ', (array) $process->command), 'pg_dump')
        ? Process::result(errorOutput: 'disk full', exitCode: 1)
        : Process::result('old'));

    $this->artisan('dtaxi:update', ['--skip-build' => true])->expectsOutputToContain('disk full')->assertFailed();
    expect(app()->isDownForMaintenance())->toBeFalse()
        ->and(File::exists(Backup::updateStateFile()))->toBeFalse();
});

test('backup server: host key fingerprint is pinned when given', function () {
    Integration::for(IntegrationProvider::BackupServer)->fill(['is_enabled' => true, 'credentials' => [
        'host' => 'backup.example.uz', 'username' => 'u', 'password' => 'p', 'host_fingerprint' => ' SHA256:abc ',
    ]])->save();

    $backup = new class extends Backup
    {
        public function disk(): ?Filesystem
        {
            return $this->remoteDisk();
        }
    };
    $disk = $backup->disk();
    expect($disk)->not->toBeNull();

    $provider = (new ReflectionProperty($disk->getAdapter(), 'connectionProvider'))->getValue($disk->getAdapter());
    expect((new ReflectionProperty($provider, 'hostFingerprint'))->getValue($provider))->toBe('SHA256:abc');
});

test('backup server keeps only recent copies: daily by --keep, call recordings by their retention, two map bundles', function () {
    $remote = Storage::fake('backup-remote');
    $backup = new class($remote) extends Backup
    {
        public function __construct(private Filesystem $fake) {}

        protected function remoteDisk(): Filesystem
        {
            return $this->fake;
        }
    };
    $old = now()->subDays(20)->format('Y-m-d');
    $recent = now()->subDays(3)->format('Y-m-d');
    foreach ([
        "{$old}_023000_daily.dump", "{$old}_023000_daily.files.tar.gz", "{$recent}_023000_daily.dump",
        "{$old}_recordings.tar", now()->subDays(120)->format('Y-m-d').'_recordings.tar',
        '2026-01-01_030000_map.tar', '2026-02-01_030000_map.tar', '2026-03-01_030000_map.tar', 'boshqa-fayl.txt',
    ] as $name) {
        $remote->put($name, 'x');
    }

    expect($backup->pruneRemote(14, 90))->toBe(4);
    expect($remote->files())->toEqualCanonicalizing([
        "{$recent}_023000_daily.dump", "{$old}_recordings.tar", '2026-02-01_030000_map.tar', '2026-03-01_030000_map.tar', 'boshqa-fayl.txt',
    ]);
});

test('a driver with money history cannot be deleted by accident; the migration reverts cleanly', function () {
    $driver = Driver::factory()->create();
    DB::table('driver_transactions')->insert(['driver_id' => $driver->id, 'type' => 'topup', 'source' => 'cash', 'amount' => 1000, 'balance_after' => 1000, 'created_at' => now()]);

    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $driver->user_id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('drivers')->where('id', $driver->id)->delete()))->toThrow(QueryException::class);

    $path = 'database/migrations/2026_10_27_100001_add_ops_indexes_and_restrict_driver_deletes.php';
    $this->artisan('migrate:rollback', ['--path' => $path])->assertSuccessful();
    expect(DB::table('pg_indexes')->where('indexname', 'clients_phone_trgm')->exists())->toBeFalse();
    $this->artisan('migrate', ['--path' => $path])->assertSuccessful();
    expect(DB::table('pg_indexes')->where('indexname', 'clients_phone_trgm')->exists())->toBeTrue();
});
