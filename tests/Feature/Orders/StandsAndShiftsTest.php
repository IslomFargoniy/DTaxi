<?php

use App\Enums\DriverStatus;
use App\Events\DriverLocationUpdated;
use App\Events\OfferWithdrawn;
use App\Events\OrderOffered;
use App\Events\OrderUpdated;
use App\Models\Branch;
use App\Models\DriverShift;
use App\Models\Stand;
use App\Services\Dispatch\Dispatcher;
use App\Services\Drivers\DriverLocator;
use App\Services\Drivers\StandQueue;
use App\Services\Orders\OrderService;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Queue::fake();
    Event::fake([OrderOffered::class, OfferWithdrawn::class, OrderUpdated::class, DriverLocationUpdated::class]);
    Http::fake();
    $this->branch = Branch::factory()->create();
    // Farg'ona markazidan ~500 m shimolda — "Vokzal" navbat joyi
    $this->stand = Stand::query()->create([
        'branch_id' => $this->branch->id, 'name' => 'Vokzal',
        'location' => Point::makeGeodetic(40.3864 + 500 / 111_000, 71.7864), 'radius_m' => 150,
    ]);
});

test('free drivers at a stand queue in arrival order and the order from the stand goes to the first one', function () {
    $locator = app(DriverLocator::class);
    $first = driverNear($this->branch, 520);
    $second = driverNear($this->branch, 480);
    $nearer = driverNear($this->branch, 200);
    $locator->update($first, 40.3864 + 520 / 111_000, 71.7864);
    $this->travel(5)->seconds();
    $locator->update($second, 40.3864 + 480 / 111_000, 71.7864);
    $locator->update($nearer, 40.3864 + 200 / 111_000, 71.7864);

    Sanctum::actingAs($second->user, ['driver']);
    $this->getJson('/api/driver/v1/shift')->assertOk()
        ->assertJsonPath('stand.stand', 'Vokzal')->assertJsonPath('stand.position', 2)->assertJsonPath('stand.total', 2);

    $order = app(OrderService::class)->create(orderData($this->branch, ['pickup_lat' => 40.3864 + 500 / 111_000]));
    expect($order->offers()->pluck('driver_id')->all())->toBe([$first->id]);

    // Navbat boshidagi olmasa — keyingi bosqichda hamma
    app(Dispatcher::class)->expand($order->fresh(), 0, $order->fresh()->search_round);
    expect($order->offers()->count())->toBe(3);
});

test('a driver leaves the queue when driving away or taking an order', function () {
    $locator = app(DriverLocator::class);
    $driver = driverNear($this->branch, 500);
    $locator->update($driver, 40.3864 + 500 / 111_000, 71.7864);
    expect(app(StandQueue::class)->position($driver->fresh()))->not->toBeNull();

    $locator->update($driver->fresh(), 40.40, 71.80);
    expect(app(StandQueue::class)->position($driver->fresh()))->toBeNull();
});

test('going online opens a shift and the summary counts completed orders and commission', function () {
    $driver = driverNear($this->branch, 1000, ['status' => DriverStatus::Offline, 'balance' => 50000]);
    Sanctum::actingAs($driver->user, ['driver']);

    $this->postJson('/api/driver/v1/shift', ['online' => true])->assertOk();
    $orders = app(OrderService::class);
    $order = $orders->create(orderData($this->branch));
    app(Dispatcher::class)->accept($order, $driver->fresh());
    $orders->start($order->fresh(), $driver->fresh());
    $orders->complete($order->fresh(), $driver->fresh(), 3000);

    $this->getJson('/api/driver/v1/shift')->assertOk()
        ->assertJsonPath('shift.orders', 1)
        ->assertJsonPath('shift.earnings', 10000)
        ->assertJsonPath('shift.commission', 1000)
        ->assertJsonPath('shift.ended_at', null);

    $this->postJson('/api/driver/v1/shift', ['online' => false])->assertOk();
    $this->getJson('/api/driver/v1/shift')->assertOk()->assertJsonPath('shift.orders', 1);
    expect(DriverShift::query()->where('driver_id', $driver->id)->whereNotNull('ended_at')->count())->toBe(1);
});
