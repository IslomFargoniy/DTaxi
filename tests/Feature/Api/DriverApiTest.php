<?php

use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Enums\TransactionSource;
use App\Events\ChatMessageSent;
use App\Events\DriverLocationUpdated;
use App\Events\OfferWithdrawn;
use App\Events\OrderOffered;
use App\Events\OrderUpdated;
use App\Models\Branch;
use App\Models\DeviceToken;
use App\Models\Driver;
use App\Models\DriverLocation;
use App\Models\User;
use App\Services\Drivers\DriverBalance;
use App\Services\Orders\OrderService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../Orders/helpers.php';

beforeEach(function () {
    Queue::fake();
    Event::fake([OrderOffered::class, OrderUpdated::class, OfferWithdrawn::class, DriverLocationUpdated::class, ChatMessageSent::class]);
    Http::fake();
    $this->branch = Branch::factory()->create();
});

function actingDriver(Driver $driver): Driver
{
    Sanctum::actingAs($driver->user, ['driver']);

    return $driver;
}

test('driver logs in with phone and password and gets a token', function () {
    $driver = driverNear($this->branch, 0);
    $driver->user->update(['phone' => '+998901112233', 'password' => 'secret123']);

    $this->postJson('/api/driver/v1/auth/login', ['phone' => '90 111 22 33', 'password' => 'secret123', 'device_name' => 'Redmi'])
        ->assertOk()
        ->assertJsonStructure(['token', 'driver' => ['callsign', 'balance', 'vehicle', 'tariffs']]);

    $this->postJson('/api/driver/v1/auth/login', ['phone' => '90 111 22 33', 'password' => 'wrong', 'device_name' => 'Redmi'])
        ->assertUnprocessable();
});

test('panel users without a driver profile cannot log in to the app', function () {
    $user = User::factory()->create(['phone' => '+998907776655', 'password' => 'secret123']);

    $this->postJson('/api/driver/v1/auth/login', ['phone' => '907776655', 'password' => 'secret123', 'device_name' => 'x'])
        ->assertUnprocessable();
});

test('going online requires approval, no block and enough balance', function () {
    $driver = actingDriver(driverNear($this->branch, 0, ['status' => DriverStatus::Offline, 'balance' => 1000]));

    $this->postJson('/api/driver/v1/shift', ['online' => true])->assertUnprocessable();

    $driver->update(['balance' => 10000]);
    $this->postJson('/api/driver/v1/shift', ['online' => true])->assertOk()->assertJson(['status' => 'free']);
    $this->postJson('/api/driver/v1/shift', ['online' => false])->assertOk()->assertJson(['status' => 'offline']);
});

test('location points update the driver position and history', function () {
    $driver = actingDriver(driverNear($this->branch, 0));

    $this->postJson('/api/driver/v1/location', ['points' => [
        ['lat' => 40.39, 'lng' => 71.79, 'speed' => 30],
        ['lat' => 40.391, 'lng' => 71.791],
    ]])->assertOk();

    expect($driver->fresh()->last_location->getLatitude())->toEqualWithDelta(40.391, 0.0001)
        ->and(DriverLocation::query()->where('driver_id', $driver->id)->count())->toBe(2);
});

test('driver accepts an offer and walks the order to completion through the API', function () {
    $driver = actingDriver(driverNear($this->branch, 300));
    $order = app(OrderService::class)->create(orderData($this->branch));

    $this->postJson("/api/driver/v1/orders/{$order->id}/accept")->assertOk()->assertJsonPath('order.status', 'assigned');
    $this->getJson('/api/driver/v1/orders/current')->assertJsonPath('order.id', $order->id);
    $this->postJson("/api/driver/v1/orders/{$order->id}/arrive")->assertJsonPath('order.status', 'arrived');
    $this->postJson("/api/driver/v1/orders/{$order->id}/start")->assertJsonPath('order.status', 'in_progress');
    $this->postJson("/api/driver/v1/orders/{$order->id}/complete", ['inside_m' => 7400])
        ->assertOk()
        ->assertJsonPath('order.status', 'completed')
        ->assertJsonPath('order.price_final', 19000);

    expect($driver->fresh()->status)->toBe(DriverStatus::Free);
});

test('second driver gets 409 when the order is already taken', function () {
    $first = driverNear($this->branch, 300);
    $second = driverNear($this->branch, 400);
    $order = app(OrderService::class)->create(orderData($this->branch));

    actingDriver($first);
    $this->postJson("/api/driver/v1/orders/{$order->id}/accept")->assertOk();

    actingDriver($second);
    $this->postJson("/api/driver/v1/orders/{$order->id}/accept")->assertStatus(409);
});

test('drivers cannot touch orders that were not offered to them', function () {
    $stranger = actingDriver(driverNear($this->branch, 50_000));
    $order = app(OrderService::class)->create(orderData($this->branch));

    $this->postJson("/api/driver/v1/orders/{$order->id}/accept")->assertNotFound();
    $this->postJson("/api/driver/v1/orders/{$order->id}/arrive")->assertNotFound();
});

test('wrong step order is rejected with a message', function () {
    $driver = actingDriver(driverNear($this->branch, 300));
    $order = app(OrderService::class)->create(orderData($this->branch));
    $this->postJson("/api/driver/v1/orders/{$order->id}/accept")->assertOk();

    $this->postJson("/api/driver/v1/orders/{$order->id}/complete", ['inside_m' => 1000])->assertUnprocessable();
    expect($order->fresh()->status)->toBe(OrderStatus::Assigned);
});

test('device token is stored for push', function () {
    $driver = actingDriver(driverNear($this->branch, 0));

    $this->postJson('/api/driver/v1/device-token', ['token' => 'fcm-abc'])->assertOk();

    expect(DeviceToken::query()->where('user_id', $driver->user_id)->value('token'))->toBe('fcm-abc');
});

test('driver chats with the dispatcher', function () {
    $driver = actingDriver(driverNear($this->branch, 0));

    $this->postJson('/api/driver/v1/chat', ['body' => 'Mijoz chiqmayapti'])->assertCreated()->assertJsonPath('mine', true);
    $this->getJson('/api/driver/v1/chat')->assertOk()->assertJsonCount(1)->assertJsonPath('0.body', 'Mijoz chiqmayapti');
});

test('balance history is available', function () {
    $driver = actingDriver(driverNear($this->branch, 0, ['balance' => 0]));
    app(DriverBalance::class)->topUp($driver, 20000, TransactionSource::Cash);

    $this->getJson('/api/driver/v1/balance')->assertOk()->assertJsonPath('balance', 20000)->assertJsonPath('items.0.amount', 20000);
});

test('route falls back to an estimate when OSRM is down', function () {
    actingDriver(driverNear($this->branch, 0));
    Http::fake(fn () => throw new ConnectionException('down'));

    $this->getJson('/api/driver/v1/route?from_lat=40.38&from_lng=71.78&to_lat=40.40&to_lng=71.80')
        ->assertOk()->assertJsonPath('estimated', true);
});

test('route for the driver app includes turn-by-turn steps', function () {
    // beforeEach'dagi umumiy soxta javob birinchi mos keladi — toza fabrika
    Http::swap(new Factory);
    Http::fake(['*/route/v1/driving/*' => Http::response(['code' => 'Ok', 'routes' => [[
        'distance' => 700, 'duration' => 90,
        'geometry' => ['coordinates' => [[71.78, 40.38], [71.79, 40.39]]],
        'legs' => [['steps' => [
            ['name' => '', 'distance' => 500, 'maneuver' => ['type' => 'depart', 'location' => [71.78, 40.38]]],
            ['name' => 'Mustaqillik', 'distance' => 200, 'maneuver' => ['type' => 'turn', 'modifier' => 'left', 'location' => [71.785, 40.385]]],
        ]]],
    ]]])]);
    actingDriver(driverNear($this->branch, 0));

    $this->getJson('/api/driver/v1/route?from_lat=40.38&from_lng=71.78&to_lat=40.39&to_lng=71.79')->assertOk()
        ->assertJsonPath('steps.1.type', 'turn')
        ->assertJsonPath('steps.1.modifier', 'left')
        ->assertJsonPath('steps.1.name', 'Mustaqillik')
        ->assertJsonPath('steps.1.lat', 40.385);
});

test('fake GPS points are ignored and flagged for the admin', function () {
    $driver = driverNear($this->branch, 0);
    actingDriver($driver);
    $before = $driver->last_location;

    $this->postJson('/api/driver/v1/location', ['points' => [['lat' => 40.50, 'lng' => 71.90, 'mock' => true]]])->assertOk();

    $driver->refresh();
    expect($driver->mock_location_at)->not->toBeNull()
        ->and($driver->last_location->getLatitude())->toBe($before->getLatitude());
});
