<?php

use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Events\DriverLocationUpdated;
use App\Events\OfferWithdrawn;
use App\Events\OrderOffered;
use App\Events\OrderUpdated;
use App\Models\Branch;
use App\Models\DeviceToken;
use App\Models\Driver;
use App\Models\Landmark;
use App\Models\Order;
use App\Models\Tariff;
use App\Models\TariffClass;
use App\Services\Orders\OrderService;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../Orders/helpers.php';

beforeEach(function () {
    Queue::fake();
    Event::fake([OrderOffered::class, OrderUpdated::class, OfferWithdrawn::class, DriverLocationUpdated::class]);
    Http::fake();
    $this->branch = Branch::factory()->create(['timezone' => 'Asia/Tashkent']);
});

function signInDriver(Driver $driver): Driver
{
    Sanctum::actingAs($driver->user, ['driver']);

    return $driver;
}

test('going offline does not overwrite busy set by an accept that lands right after the driver was loaded', function () {
    $driver = signInDriver(driverNear($this->branch, 300, ['balance' => 50000]));

    // Controller haydovchini o'qigan zahoti parallel "qabul qildim" uni band qiladi
    $raced = false;
    Driver::retrieved(function (Driver $loaded) use ($driver, &$raced) {
        if (! $raced && $loaded->id === $driver->id) {
            $raced = true;
            DB::table('drivers')->where('id', $driver->id)->update(['status' => DriverStatus::Busy->value]);
        }
    });

    $this->postJson('/api/driver/v1/shift', ['online' => false])->assertUnprocessable();

    expect($driver->fresh()->status)->toBe(DriverStatus::Busy);
});

test('going offline and online still works and reports the new status', function () {
    $driver = signInDriver(driverNear($this->branch, 300, ['balance' => 50000]));

    $this->postJson('/api/driver/v1/shift', ['online' => false])->assertOk()->assertJsonPath('status', 'offline');
    expect($driver->fresh()->status)->toBe(DriverStatus::Offline);
    $this->postJson('/api/driver/v1/shift', ['online' => true])->assertOk()->assertJsonPath('status', 'free');
    expect($driver->fresh()->status)->toBe(DriverStatus::Free);
    Event::assertDispatched(DriverLocationUpdated::class);
});

test('block end time is shown in the branch timezone', function () {
    $this->travelTo(now()->setTimezone('UTC')->setTime(9, 0));
    $driver = signInDriver(driverNear($this->branch, 300, [
        'balance' => 50000,
        'status' => DriverStatus::Offline,
        'blocked_until' => now()->setTimezone('UTC')->setTime(10, 0),
    ]));

    $response = $this->postJson('/api/driver/v1/shift', ['online' => true])->assertUnprocessable();

    // 10:00 UTC = 15:00 Toshkent
    expect($response->json('message'))->toContain('15:00')->not->toContain('10:00');
});

test('history uses the stored place names instead of searching landmarks for every row', function () {
    $landmark = Landmark::factory()->create(['name' => 'Chorsu bozori', 'category' => 'market', 'location' => Point::makeGeodetic(40.3870, 71.7870)]);
    signInDriver(driverNear($this->branch, 300, ['balance' => 50000]));
    $order = app(OrderService::class)->create(orderData($this->branch));
    $this->postJson("/api/driver/v1/orders/{$order->id}/accept")->assertOk();
    $this->postJson("/api/driver/v1/orders/{$order->id}/start", ['lat' => 40.3870, 'lng' => 71.7870])->assertOk();
    $this->postJson("/api/driver/v1/orders/{$order->id}/complete", ['inside_m' => 5000, 'lat' => 40.5, 'lng' => 71.5])->assertOk();

    // Keyin joy o'chirildi — tarixda safar paytidagi nom qoladi
    $landmark->delete();
    DB::enableQueryLog();
    $this->getJson('/api/driver/v1/orders/history')->assertOk()
        ->assertJsonPath('items.0.start_name', 'Chorsu bozori')
        ->assertJsonPath('items.0.finish_name', null);

    expect(collect(DB::getQueryLog())->contains(fn (array $q) => str_contains($q['query'], 'landmarks')))->toBeFalse();
});

test('history still finds place names for old orders without stored names', function () {
    Landmark::factory()->create(['name' => 'Chorsu bozori', 'category' => 'market', 'location' => Point::makeGeodetic(40.3870, 71.7870)]);
    $driver = signInDriver(driverNear($this->branch, 300));
    Order::factory()->create([
        'branch_id' => $this->branch->id,
        'driver_id' => $driver->id,
        'status' => OrderStatus::Completed,
        'start_location' => Point::makeGeodetic(40.3870, 71.7870),
    ]);

    $this->getJson('/api/driver/v1/orders/history')->assertOk()->assertJsonPath('items.0.start_name', 'Chorsu bozori');
});

test('a device token moves to the driver who signs in on that phone and is never duplicated', function () {
    $first = signInDriver(driverNear($this->branch, 0));
    $this->postJson('/api/driver/v1/device-token', ['token' => 'fcm-shared'])->assertOk();
    $this->postJson('/api/driver/v1/device-token', ['token' => 'fcm-shared'])->assertOk();

    $second = signInDriver(driverNear($this->branch, 100));
    $this->postJson('/api/driver/v1/device-token', ['token' => 'fcm-shared'])->assertOk();

    expect(DeviceToken::query()->where('token', 'fcm-shared')->count())->toBe(1)
        ->and(DeviceToken::query()->where('token', 'fcm-shared')->value('user_id'))->toBe($second->user_id)
        ->and(DeviceToken::query()->where('user_id', $first->user_id)->exists())->toBeFalse();
});

test('logging out removes the phone push token so offers stop reaching it', function () {
    $driver = driverNear($this->branch, 0);
    $token = $driver->user->createToken('android', ['driver'])->plainTextToken;
    DeviceToken::query()->create(['token' => 'fcm-logout', 'user_id' => $driver->user_id, 'platform' => 'android']);

    $this->withToken($token)->postJson('/api/driver/v1/auth/logout')->assertOk();

    expect(DeviceToken::query()->where('user_id', $driver->user_id)->exists())->toBeFalse()
        ->and($driver->user->tokens()->count())->toBe(0);
});

test('profile sends the branch centre so the app map opens where the firm works', function () {
    $this->branch->forceFill(['center' => Point::makeGeodetic(42.46, 59.61)])->save();
    signInDriver(driverNear($this->branch, 0));

    $this->getJson('/api/driver/v1/me')->assertOk()
        ->assertJsonPath('data.config.map_center.lat', 42.46)
        ->assertJsonPath('data.config.map_center.lng', 59.61);
});

test('profile still lists tariffs when the driver account is left in another branch', function () {
    $class = TariffClass::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Standart']);
    Tariff::factory()->create(['tariff_class_id' => $class->id]);
    $driver = driverNear($this->branch, 0);
    // Haydovchi boshqa filialga o'tkazilgan, lekin akkaunti eski filialda qolgan
    $driver->user->forceFill(['branch_id' => Branch::factory()->create()->id])->save();
    signInDriver($driver);

    $this->getJson('/api/driver/v1/me')->assertOk()
        ->assertJsonPath('data.tariffs.0.name', 'Standart');
});
