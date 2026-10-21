<?php

use App\Enums\OrderStatus;
use App\Events\DriverLocationUpdated;
use App\Events\OfferWithdrawn;
use App\Events\OrderOffered;
use App\Events\OrderUpdated;
use App\Models\Branch;
use App\Models\Driver;
use App\Models\DriverLocation;
use App\Models\Landmark;
use App\Models\Order;
use App\Services\Orders\OrderService;
use App\Services\Orders\RideTrack;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../Orders/helpers.php';

beforeEach(function () {
    Queue::fake();
    Event::fake([OrderOffered::class, OrderUpdated::class, OfferWithdrawn::class, DriverLocationUpdated::class]);
    Http::fake();
    $this->branch = Branch::factory()->create();
});

function historyDriver(Driver $driver): Driver
{
    Sanctum::actingAs($driver->user, ['driver']);

    return $driver;
}

/**
 * Haydovchi buyurtmani API orqali boshidan oxirigacha olib boradi.
 *
 * @param  array<string, mixed>  $start
 * @param  array<string, mixed>  $complete
 */
function rideThrough(Order $order, array $start = [], array $complete = []): void
{
    test()->postJson("/api/driver/v1/orders/{$order->id}/accept")->assertOk();
    test()->postJson("/api/driver/v1/orders/{$order->id}/start", $start)->assertOk();
    test()->postJson("/api/driver/v1/orders/{$order->id}/complete", ['inside_m' => 5000, ...$complete])->assertOk();
}

test('start and complete store the position sent by the app', function () {
    historyDriver(driverNear($this->branch, 300));
    $order = app(OrderService::class)->create(orderData($this->branch));

    rideThrough($order, ['lat' => 40.3870, 'lng' => 71.7870], ['lat' => 40.4000, 'lng' => 71.8000]);

    $order->refresh();
    expect($order->start_location->getLatitude())->toEqualWithDelta(40.3870, 0.00001)
        ->and($order->start_location->getLongitude())->toEqualWithDelta(71.7870, 0.00001)
        ->and($order->finish_location->getLatitude())->toEqualWithDelta(40.4000, 0.00001)
        ->and($order->finish_location->getLongitude())->toEqualWithDelta(71.8000, 0.00001);
});

test('without coordinates the last known driver position is used', function () {
    $driver = historyDriver(driverNear($this->branch, 300));
    $order = app(OrderService::class)->create(orderData($this->branch));

    rideThrough($order);

    expect($order->fresh()->start_location->getLatitude())->toEqualWithDelta($driver->last_location->getLatitude(), 0.00001)
        ->and($order->fresh()->finish_location)->not->toBeNull();
});

test('a lone latitude is rejected', function () {
    historyDriver(driverNear($this->branch, 300));
    $order = app(OrderService::class)->create(orderData($this->branch));
    $this->postJson("/api/driver/v1/orders/{$order->id}/accept")->assertOk();

    $this->postJson("/api/driver/v1/orders/{$order->id}/start", ['lat' => 40.1])->assertUnprocessable();
    expect($order->fresh()->status)->toBe(OrderStatus::Assigned);
});

test('history lists only own finished orders, newest first, with place names', function () {
    Landmark::factory()->create(['name' => 'Chorsu bozori', 'category' => 'market', 'location' => Point::makeGeodetic(40.3870, 71.7870)]);
    $driver = historyDriver(driverNear($this->branch, 300));

    $older = app(OrderService::class)->create(orderData($this->branch));
    rideThrough($older, ['lat' => 40.3870, 'lng' => 71.7870], ['lat' => 40.5, 'lng' => 71.5]);
    $older->forceFill(['created_at' => now()->subHour()])->save();

    $newer = app(OrderService::class)->create(orderData($this->branch));
    $this->postJson("/api/driver/v1/orders/{$newer->id}/accept")->assertOk();
    app(OrderService::class)->cancelByClient($newer->fresh(), null, 'Kerak emas');

    // Boshqa haydovchining buyurtmasi va hali faol buyurtma ko'rinmaydi
    $other = driverNear($this->branch, 20_000);
    Order::factory()->create(['branch_id' => $this->branch->id, 'driver_id' => $other->id, 'status' => OrderStatus::Completed]);
    Order::factory()->create(['branch_id' => $this->branch->id, 'driver_id' => $driver->id, 'status' => OrderStatus::InProgress]);

    $response = $this->getJson('/api/driver/v1/orders/history')->assertOk();

    $response->assertJsonCount(2, 'items')
        ->assertJsonPath('items.0.id', $newer->id)
        ->assertJsonPath('items.0.status', 'cancelled_by_client')
        ->assertJsonPath('items.1.id', $older->id)
        ->assertJsonPath('items.1.status', 'completed')
        ->assertJsonPath('items.1.start_name', 'Chorsu bozori')
        ->assertJsonPath('items.1.finish_name', null)
        ->assertJsonPath('items.1.distance_m', 5000)
        ->assertJsonPath('items.1.cashless', false)
        ->assertJsonPath('next_page', null);
    expect($response->json('items.1.price'))->toBeGreaterThan(0)
        ->and($response->json('items.1.finish.lat'))->toEqualWithDelta(40.5, 0.00001);
});

test('an order the driver gave up shows as cancelled by the driver without the next ride details', function () {
    historyDriver(driverNear($this->branch, 300));
    $order = app(OrderService::class)->create(orderData($this->branch));
    $this->postJson("/api/driver/v1/orders/{$order->id}/accept")->assertOk();
    $this->postJson("/api/driver/v1/orders/{$order->id}/cancel", ['reason' => 'Mashina buzildi'])->assertOk();

    $this->getJson('/api/driver/v1/orders/history')
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.status', 'cancelled_by_driver')
        ->assertJsonPath('items.0.price', null);
});

test('history is paginated', function () {
    $driver = historyDriver(driverNear($this->branch, 300));
    Order::factory()->count(25)->create(['branch_id' => $this->branch->id, 'driver_id' => $driver->id, 'status' => OrderStatus::Completed]);

    $this->getJson('/api/driver/v1/orders/history')->assertJsonCount(20, 'items')->assertJsonPath('next_page', 2);
    $this->getJson('/api/driver/v1/orders/history?page=2')->assertJsonCount(5, 'items')->assertJsonPath('next_page', null);
});

test('track returns the simplified driven route without GPS jumps', function () {
    $driver = historyDriver(driverNear($this->branch, 300));
    $order = app(OrderService::class)->create(orderData($this->branch));
    rideThrough($order, ['lat' => 40.3870, 'lng' => 71.7870], ['lat' => 40.4, 'lng' => 71.8]);
    $order->refresh();

    // 1000 ta nuqta (2 s oraliq): shimolga to'g'ri yo'l, keyin sharqqa — soddalashtirilgach burilish saqlanadi
    $rows = [];
    for ($i = 0; $i < 1000; $i++) {
        $lat = 40.3870 + min($i, 500) * 0.00002;
        $lng = 71.7870 + max(0, $i - 500) * 0.00002 + ($i % 2) * 0.000001;
        $rows[] = ['driver_id' => $driver->id, 'location' => Point::makeGeodetic($lat, $lng), 'recorded_at' => $order->started_at->copy()->addSeconds($i * 2)];
    }
    // GPS sakrashi (Toshkent) chizilmaydi
    $rows[] = ['driver_id' => $driver->id, 'location' => Point::makeGeodetic(41.31, 69.28), 'recorded_at' => $order->started_at->copy()->addSeconds(1001)];
    // Yo'ldan oldingi nuqta hisobga olinmaydi
    $rows[] = ['driver_id' => $driver->id, 'location' => Point::makeGeodetic(40.0, 71.0), 'recorded_at' => $order->started_at->copy()->subMinute()];
    foreach ($rows as $row) {
        DriverLocation::query()->create($row);
    }
    $order->forceFill(['completed_at' => $order->started_at->copy()->addSeconds(2100)])->save();

    $response = $this->getJson("/api/driver/v1/orders/{$order->id}/track")->assertOk();
    $points = $response->json('points');

    expect(count($points))->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(RideTrack::MAX_POINTS)
        ->and($points[0][0])->toEqualWithDelta(40.3870, 0.0001)
        ->and(collect($points)->every(fn (array $p) => $p[0] < 41))->toBeTrue()
        ->and($response->json('start.lat'))->toEqualWithDelta(40.3870, 0.00001)
        ->and($response->json('finish.lng'))->toEqualWithDelta(71.8, 0.00001);
});

test('track is empty when the GPS points are gone', function () {
    historyDriver(driverNear($this->branch, 300));
    $order = app(OrderService::class)->create(orderData($this->branch));
    rideThrough($order);

    $this->getJson("/api/driver/v1/orders/{$order->id}/track")
        ->assertOk()
        ->assertJsonPath('points', [])
        ->assertJsonStructure(['start' => ['lat', 'lng'], 'finish' => ['lat', 'lng']]);
});

test('drivers cannot see the track of other drivers orders', function () {
    $other = driverNear($this->branch, 20_000);
    $order = Order::factory()->create(['branch_id' => $this->branch->id, 'driver_id' => $other->id, 'status' => OrderStatus::Completed]);
    historyDriver(driverNear($this->branch, 300));

    $this->getJson("/api/driver/v1/orders/{$order->id}/track")->assertNotFound();
});
