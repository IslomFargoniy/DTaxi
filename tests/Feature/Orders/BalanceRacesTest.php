<?php

use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Events\DriverLocationUpdated;
use App\Events\OfferWithdrawn;
use App\Events\OrderOffered;
use App\Events\OrderUpdated;
use App\Models\Branch;
use App\Models\User;
use App\Services\Dispatch\Dispatcher;
use App\Services\Dispatch\DriverDiscipline;
use App\Services\Drivers\DriverBalance;
use App\Services\Orders\OrderService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Queue::fake();
    Event::fake([OrderOffered::class, OfferWithdrawn::class, OrderUpdated::class, DriverLocationUpdated::class]);
    Http::fake();
    $this->branch = Branch::factory()->create();
    $this->orders = app(OrderService::class);
    $this->dispatcher = app(Dispatcher::class);
});

test('excusing the same penalty twice (stale copies) refunds only once', function () {
    $driver = driverNear($this->branch, 500, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch));
    $this->dispatcher->accept($order, $driver);
    $this->orders->cancelByDriver($order->fresh(), $driver->fresh(), 'test');

    $penalty = $driver->transactions()->where('type', TransactionType::Penalty)->firstOrFail();
    $copy = $penalty->fresh();
    $admin = User::factory()->create();

    app(DriverDiscipline::class)->excuse($penalty, $admin);
    expect(fn () => app(DriverDiscipline::class)->excuse($copy, $admin))->toThrow(InvalidArgumentException::class);

    expect($driver->fresh()->balance)->toBe(50000)
        ->and($driver->fresh()->rating)->toBe(100)
        ->and($driver->transactions()->where('type', TransactionType::Bonus)->count())->toBe(1);
});

test('saving a driver model after a balance change does not overwrite a concurrent top-up', function () {
    $driver = driverNear($this->branch, 500, ['balance' => 50000]);
    $balance = app(DriverBalance::class);

    $balance->penalty($driver, 2000, 'test');
    // Shu orada kassir boshqa nusxa orqali to'ldirdi
    $balance->topUp($driver->fresh(), 10000, TransactionSource::Cash);
    $driver->forceFill(['rating' => 90])->save();

    expect($driver->fresh()->balance)->toBe(58000);
});

test('cancel penalty lowers rating atomically without touching the balance of a stale model', function () {
    $driver = driverNear($this->branch, 500, ['balance' => 50000]);
    $stale = $driver->fresh();
    $order = $this->orders->create(orderData($this->branch));

    app(DriverBalance::class)->topUp($driver->fresh(), 10000, TransactionSource::Cash);
    app(DriverDiscipline::class)->cancelled($stale, $order, 'test');

    expect($driver->fresh()->balance)->toBe(58000)
        ->and($driver->fresh()->rating)->toBe(95)
        ->and($stale->rating)->toBe(95);
});

test('only the first client rating changes the driver rating', function () {
    $driver = driverNear($this->branch, 500);
    $order = $this->orders->create(orderData($this->branch));
    $this->dispatcher->accept($order, $driver);
    $stale = $order->fresh();

    $this->orders->rateByClient($order->fresh(), 1);
    $this->orders->rateByClient($stale, 1);

    expect($driver->fresh()->rating)->toBe(90)
        ->and($order->fresh()->client_rating)->toBe(1);
});
