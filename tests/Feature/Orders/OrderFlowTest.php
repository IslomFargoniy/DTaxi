<?php

use App\Enums\DriverStatus;
use App\Enums\OfferResponse;
use App\Enums\OrderStatus;
use App\Enums\TransactionType;
use App\Events\OfferWithdrawn;
use App\Events\OrderOffered;
use App\Jobs\ExpandOrderSearch;
use App\Jobs\ExpireDirectOffer;
use App\Jobs\SendPushNotification;
use App\Models\Branch;
use App\Models\Client;
use App\Models\User;
use App\Services\Dispatch\Dispatcher;
use App\Services\Dispatch\DriverDiscipline;
use App\Services\Orders\OrderService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Queue::fake();
    Event::fake([OrderOffered::class, OfferWithdrawn::class]);
    Http::fake(); // OSRM yo'q — taxminiy hisob ishlaydi
    $this->branch = Branch::factory()->create();
    $this->orders = app(OrderService::class);
    $this->dispatcher = app(Dispatcher::class);
});

test('new order is offered to every suitable driver in the radius at once', function () {
    $near = driverNear($this->branch, 500);
    $alsoNear = driverNear($this->branch, 2500);
    $far = driverNear($this->branch, 6000);
    $busy = driverNear($this->branch, 300, ['status' => DriverStatus::Busy]);
    $stale = driverNear($this->branch, 300, ['last_seen_at' => now()->subMinutes(5)]);
    $poor = driverNear($this->branch, 300, ['balance' => 1000]);
    $blocked = driverNear($this->branch, 300, ['blocked_until' => now()->addMinutes(10)]);

    $order = $this->orders->create(orderData($this->branch));

    expect($order->fresh()->status)->toBe(OrderStatus::Searching)
        ->and($order->offers()->pluck('driver_id')->sort()->values()->all())->toBe([$near->id, $alsoNear->id]);

    Event::assertDispatchedTimes(OrderOffered::class, 2);
    Queue::assertPushed(SendPushNotification::class, 2);
    Queue::assertPushed(ExpandOrderSearch::class, fn ($job) => $job->step === 0);
});

test('drivers whose car is not allowed in the tariff class are skipped', function () {
    $driver = driverNear($this->branch, 500);
    $driver->vehicle->tariffClasses()->detach();

    $order = $this->orders->create(orderData($this->branch));

    expect($order->offers()->count())->toBe(0);
});

test('first driver to accept gets the order and others lose the offer', function () {
    $first = driverNear($this->branch, 500);
    $second = driverNear($this->branch, 800);
    $order = $this->orders->create(orderData($this->branch));

    expect($this->dispatcher->accept($order, $first))->toBeTrue()
        ->and($this->dispatcher->accept($order->fresh(), $second))->toBeFalse();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Assigned)
        ->and($order->driver_id)->toBe($first->id)
        ->and($order->vehicle_id)->toBe($first->vehicle->id)
        ->and($first->fresh()->status)->toBe(DriverStatus::Busy)
        ->and($second->fresh()->status)->toBe(DriverStatus::Free);

    expect($order->offers()->where('driver_id', $second->id)->value('response'))->toBe(OfferResponse::Ignored);
    Event::assertDispatched(OfferWithdrawn::class, fn ($e) => $e->driverId === $second->id);
});

test('a driver cannot take two orders from two broadcasts', function () {
    $driver = driverNear($this->branch, 500);
    $orderA = $this->orders->create(orderData($this->branch));
    $orderB = $this->orders->create(orderData($this->branch, ['phone' => '91 000 00 00']));

    expect($this->dispatcher->accept($orderA, $driver))->toBeTrue()
        ->and($this->dispatcher->accept($orderB, $driver->fresh()))->toBeFalse()
        ->and($orderB->fresh()->status)->toBe(OrderStatus::Searching);
});

test('radius expands step by step and then the operator is told', function () {
    $order = $this->orders->create(orderData($this->branch));
    $far = driverNear($this->branch, 4500);

    expect($order->offers()->count())->toBe(0);

    $this->dispatcher->expand($order->fresh(), 0);
    expect($order->fresh()->search_radius_m)->toBe(5000)
        ->and($order->offers()->pluck('driver_id')->all())->toBe([$far->id]);

    $this->dispatcher->expand($order->fresh(), 1);
    expect($order->fresh()->search_radius_m)->toBe(8000);

    $this->dispatcher->expand($order->fresh(), 2);
    expect($order->events()->where('type', 'search_exhausted')->exists())->toBeTrue();

    // Eskirgan job (boshqa bosqich uchun) hech narsa qilmaydi
    $this->dispatcher->expand($order->fresh(), 0);
    expect($order->fresh()->search_radius_m)->toBe(8000);
});

test('full trip: arrive, wait, ride, complete with fare and commission', function () {
    $driver = driverNear($this->branch, 500, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch));
    $this->dispatcher->accept($order, $driver);

    $this->orders->arrive($order->fresh(), $driver->fresh());
    $this->travel(2)->minutes();
    $this->orders->start($order->fresh(), $driver->fresh());
    $this->travel(15)->minutes();
    $completed = $this->orders->complete($order->fresh(), $driver->fresh(), 7400);

    // Standart: 10 000 + 4,4 km × 2 000 = 18 800 → 19 000
    expect($completed->status)->toBe(OrderStatus::Completed)
        ->and($completed->price_final)->toBe(19000)
        ->and($completed->commission)->toBe(1900)
        ->and($driver->fresh()->balance)->toBe(48100)
        ->and($driver->fresh()->status)->toBe(DriverStatus::Free)
        ->and($completed->client->orders_count)->toBe(1);

    expect($driver->transactions()->first()->type)->toBe(TransactionType::Commission);
    expect($completed->events()->pluck('type')->all())
        ->toBe(['created', 'searching', 'assigned', 'arrived', 'started', 'completed']);
});

test('driver cancel means penalty and the order goes back on air', function () {
    $driver = driverNear($this->branch, 500, ['balance' => 50000]);
    $other = driverNear($this->branch, 700);
    $order = $this->orders->create(orderData($this->branch));
    $this->dispatcher->accept($order, $driver);

    $this->orders->cancelByDriver($order->fresh(), $driver->fresh(), 'Mashina buzildi');

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Searching)
        ->and($order->driver_id)->toBeNull()
        ->and($driver->fresh()->balance)->toBe(48000)
        ->and($driver->fresh()->rating)->toBe(95)
        ->and($driver->fresh()->status)->toBe(DriverStatus::Free);
});

test('too many cancels block the driver for a while', function () {
    $driver = driverNear($this->branch, 500, ['balance' => 100000]);

    foreach (range(1, 3) as $i) {
        $order = $this->orders->create(orderData($this->branch, ['phone' => "90 000 00 0{$i}"]));
        $this->dispatcher->accept($order, $driver->fresh());
        $this->orders->cancelByDriver($order->fresh(), $driver->fresh(), 'test');
    }

    expect($driver->fresh()->isBlocked())->toBeTrue();
});

test('operator cancel frees the driver and withdraws open offers', function () {
    $driver = driverNear($this->branch, 500);
    $order = $this->orders->create(orderData($this->branch));
    $operator = User::factory()->create();

    $this->orders->cancelByOperator($order->fresh(), $operator, 'Mijoz rad etdi');

    expect($order->fresh()->status)->toBe(OrderStatus::CancelledByOperator)
        ->and($order->fresh()->cancel_reason)->toBe('Mijoz rad etdi');
    Event::assertDispatched(OfferWithdrawn::class, fn ($e) => $e->driverId === $driver->id);
});

test('direct offer that is ignored costs a penalty and returns to air', function () {
    $driver = driverNear($this->branch, 500, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch), dispatch: false);
    $operator = User::factory()->create();

    $offer = $this->dispatcher->offerDirect($order, $driver, $operator);
    (new ExpireDirectOffer($offer->id))->handle($this->dispatcher, app(DriverDiscipline::class));

    expect($offer->fresh()->response)->toBe(OfferResponse::Ignored)
        ->and($driver->fresh()->balance)->toBe(48000);
});

test('client is matched by normalized phone and addresses are remembered', function () {
    $this->orders->create(orderData($this->branch, ['phone' => '+998 (90) 123-45-67']), dispatch: false);
    $order = $this->orders->create(orderData($this->branch, ['phone' => '901234567']), dispatch: false);

    expect(Client::query()->count())->toBe(1)
        ->and($order->client->phone)->toBe('+998901234567')
        ->and($order->client->addresses()->first()->usage_count)->toBe(2);
});

test('estimate is calculated when the destination is known', function () {
    $order = $this->orders->create(orderData($this->branch, [
        'dropoff_text' => 'Avtovokzal', 'dropoff_lat' => 40.4264, 'dropoff_lng' => 71.7864,
    ]), dispatch: false);

    // ~4,45 km to'g'ri chiziq × 1,3 ≈ 5,8 km → 10 000 + 2,8 × 2 000 ≈ 15 600 → 16 000
    expect($order->price_estimate)->toBe(16000);
});
