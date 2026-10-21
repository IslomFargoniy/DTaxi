<?php

use App\Enums\DriverStatus;
use App\Enums\IntegrationProvider;
use App\Enums\TransactionSource;
use App\Events\DriverLocationUpdated;
use App\Models\Branch;
use App\Models\DriverLocation;
use App\Models\DriverShift;
use App\Models\Integration;
use App\Services\Drivers\DriverBalance;
use App\Services\Drivers\DriverLocator;
use App\Support\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Queue::fake();
    Event::fake([DriverLocationUpdated::class]);
    Http::fake();
    $this->branch = Branch::factory()->create();
});

test('nightly balance check is quiet when balances match the history', function () {
    $driver = driverNear($this->branch, 0, ['balance' => 7000]);
    app(DriverBalance::class)->topUp($driver, 50000, TransactionSource::Cash);
    app(DriverBalance::class)->penalty($driver, 2000, 'test');

    expect(app(DriverBalance::class)->mismatches())->toBe([]);
    $this->artisan('drivers:balance-check')->assertSuccessful();
    Http::assertNothingSent();
});

test('nightly balance check alerts via Telegram when a balance changed outside the history', function () {
    Integration::for(IntegrationProvider::Telegram)->fill(['is_enabled' => true, 'credentials' => ['bot_token' => 't', 'chat_id' => '1']])->save();
    $driver = driverNear($this->branch, 0, ['balance' => 0, 'callsign' => '777']);
    app(DriverBalance::class)->topUp($driver, 50000, TransactionSource::Cash);
    DB::table('drivers')->where('id', $driver->id)->update(['balance' => 90000]);

    expect(app(DriverBalance::class)->mismatches())->toBe([
        ['driver_id' => $driver->id, 'callsign' => '777', 'balance' => 90000, 'expected' => 50000],
    ]);

    $this->artisan('drivers:balance-check')->assertSuccessful();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.telegram.org') && str_contains((string) $r['text'], '#777'));
});

test('a free driver who stops sending GPS is taken offline and the shift ends at the last GPS time', function () {
    $silent = driverNear($this->branch, 0, ['last_seen_at' => now()->subMinutes(6)]);
    $alive = driverNear($this->branch, 100);
    $busy = driverNear($this->branch, 200, ['status' => DriverStatus::Busy, 'last_seen_at' => now()->subMinutes(30)]);
    DriverShift::query()->create(['driver_id' => $silent->id, 'branch_id' => $this->branch->id, 'started_at' => now()->subHour()]);

    $this->artisan('drivers:mark-offline')->assertSuccessful();

    expect($silent->fresh()->status)->toBe(DriverStatus::Offline)
        ->and($alive->fresh()->status)->toBe(DriverStatus::Free)
        ->and($busy->fresh()->status)->toBe(DriverStatus::Busy)
        ->and(DriverShift::query()->where('driver_id', $silent->id)->first()->ended_at->timestamp)
        ->toBe($silent->last_seen_at->timestamp);
    Event::assertDispatched(DriverLocationUpdated::class, fn ($e) => $e->driver->id === $silent->id);
});

test('the offline timeout follows the branch setting and 0 disables it', function () {
    $driver = driverNear($this->branch, 0, ['last_seen_at' => now()->subMinutes(8)]);
    app(Settings::class)->set('drivers.offline_after_minutes', 10, $this->branch);
    $this->artisan('drivers:mark-offline')->assertSuccessful();
    expect($driver->fresh()->status)->toBe(DriverStatus::Free);

    app(Settings::class)->set('drivers.offline_after_minutes', 0, $this->branch);
    $this->travel(30)->minutes();
    $this->artisan('drivers:mark-offline')->assertSuccessful();
    expect($driver->fresh()->status)->toBe(DriverStatus::Free);
});

test('GPS batch is stored with one insert and keeps every point', function () {
    $driver = driverNear($this->branch, 0);
    $now = now()->getTimestampMs();
    $points = array_map(fn (int $i) => ['lat' => 40.38 + $i / 10000, 'lng' => 71.78, 'speed' => $i, 't' => $now - (200 - $i) * 1000], range(1, 200));

    DB::enableQueryLog();
    app(DriverLocator::class)->record($driver, $points);
    $inserts = collect(DB::getQueryLog())->filter(fn (array $q) => str_starts_with(strtolower($q['query']), 'insert into "driver_locations"'));
    DB::disableQueryLog();

    expect($inserts)->toHaveCount(1)
        ->and(DriverLocation::query()->where('driver_id', $driver->id)->count())->toBe(200)
        ->and(DriverLocation::query()->where('driver_id', $driver->id)->where('speed', 200)->first()->location->getLatitude())
        ->toEqualWithDelta(40.40, 0.00001)
        ->and($driver->fresh()->last_location->getLatitude())->toEqualWithDelta(40.40, 0.00001);
});
