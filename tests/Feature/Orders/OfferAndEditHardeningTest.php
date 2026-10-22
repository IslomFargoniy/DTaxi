<?php

use App\Enums\DriverStatus;
use App\Enums\OfferResponse;
use App\Enums\OrderStatus;
use App\Enums\TransactionType;
use App\Events\DriverLocationUpdated;
use App\Events\OfferWithdrawn;
use App\Events\OrderOffered;
use App\Events\OrderUpdated;
use App\Jobs\ExpireDirectOffer;
use App\Models\Branch;
use App\Models\DriverTransaction;
use App\Models\Landmark;
use App\Models\Order;
use App\Models\User;
use App\Services\Dispatch\Dispatcher;
use App\Services\Dispatch\DriverDiscipline;
use App\Services\Orders\OrderService;
use Clickbar\Magellan\Data\Geometries\Point;
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
    $this->operator = User::factory()->create();
});

/**
 * Navbatga qo'yilgan oxirgi ExpireDirectOffer job'ini bajarish (muddati kelgandek).
 */
function runLastExpiryJob(): void
{
    /** @var ExpireDirectOffer $job */
    $job = Queue::pushed(ExpireDirectOffer::class)->last();
    $job->handle(app(Dispatcher::class), app(DriverDiscipline::class));
}

function penaltyCount(int $driverId): int
{
    return DriverTransaction::query()->where('driver_id', $driverId)->where('type', TransactionType::Penalty)->count();
}

test('a stale direct-offer expiry does not fine the driver for a normal broadcast offer on the same row', function () {
    $driver = driverNear($this->branch, 300, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch), dispatch: false);
    $offer = $this->dispatcher->offerDirect($order->fresh(), $driver, $this->operator);

    // Operator qayta efirga chiqardi: shu qator oddiy efir taklifi bo'lib qayta yozildi
    $this->orders->redispatch($order->fresh(), $this->operator);
    expect($offer->fresh()->is_direct)->toBeFalse()->and($offer->fresh()->response)->toBeNull();

    $job = Queue::pushed(ExpireDirectOffer::class)->first();
    $job->handle(app(Dispatcher::class), app(DriverDiscipline::class));

    expect(penaltyCount($driver->id))->toBe(0)
        ->and($offer->fresh()->response)->toBeNull();
});

test('the expiry job of an earlier direct offer does not cut a re-sent direct offer short', function () {
    $driver = driverNear($this->branch, 300, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch), dispatch: false);
    $this->dispatcher->offerDirect($order->fresh(), $driver, $this->operator);
    $first = Queue::pushed(ExpireDirectOffer::class)->first();

    $this->travel(5)->seconds();
    $offer = $this->dispatcher->offerDirect($order->fresh(), $driver->fresh(), $this->operator);

    $first->handle(app(Dispatcher::class), app(DriverDiscipline::class));
    expect($offer->fresh()->response)->toBeNull()
        ->and(penaltyCount($driver->id))->toBe(0);

    // O'z muddati kelganda — odatdagidek tugaydi
    runLastExpiryJob();
    expect($offer->fresh()->response)->toBe(OfferResponse::Ignored)
        ->and(penaltyCount($driver->id))->toBe(1);
});

test('a broadcast offer cannot be accepted after its offer time ran out', function () {
    $driver = driverNear($this->branch, 300, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch));

    $this->travel(60)->seconds();

    expect($this->dispatcher->accept($order->fresh(), $driver->fresh()))->toBeFalse()
        ->and($order->fresh()->status)->toBe(OrderStatus::Searching)
        ->and($driver->fresh()->status)->toBe(DriverStatus::Free);
});

test('a broadcast offer within its offer time is accepted', function () {
    $driver = driverNear($this->branch, 300, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch));

    $this->travel(15)->seconds();

    expect($this->dispatcher->accept($order->fresh(), $driver->fresh()))->toBeTrue();
});

test('an offer from an earlier search round cannot be accepted', function () {
    $driver = driverNear($this->branch, 300, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch));
    // Yangi raund boshlandi, bu haydovchiga qayta taklif ketmadi (masalan radiusdan chiqib ketgan)
    Order::query()->whereKey($order->id)->increment('search_round');

    expect($this->dispatcher->accept($order->fresh(), $driver->fresh()))->toBeFalse()
        ->and($order->fresh()->status)->toBe(OrderStatus::Searching);
});

test('a driver who became ineligible after the offer cannot accept it', function (array $attributes) {
    $driver = driverNear($this->branch, 300, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch));
    $driver->forceFill($attributes)->save();

    expect($this->dispatcher->accept($order->fresh(), $driver->fresh()))->toBeFalse()
        ->and($order->fresh()->status)->toBe(OrderStatus::Searching)
        ->and($driver->fresh()->status)->toBe(DriverStatus::Free);
})->with([
    'blocked' => [['blocked_until' => now()->addMinutes(30)]],
    'low balance' => [['balance' => 0]],
    'unapproved' => [['is_approved' => false]],
]);

test('operator edit with a stale screen does not change tariff or pickup of an order a driver just accepted', function () {
    $driver = driverNear($this->branch, 300, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch));
    $stale = $order->fresh();
    $this->dispatcher->accept($order->fresh(), $driver->fresh());
    $otherClass = $this->branch->tariffClasses()->where('id', '!=', $order->tariff_class_id)->firstOrFail();

    $this->orders->update($stale, [
        'comment' => 'Yangi izoh',
        'tariff_class_id' => $otherClass->id,
        'pickup_text' => 'Boshqa joy', 'pickup_lat' => 40.40, 'pickup_lng' => 71.80,
    ], $this->operator);

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(OrderStatus::Assigned)
        ->and($fresh->tariff_class_id)->toBe($order->tariff_class_id)
        ->and($fresh->pickup_text)->toBe('Mustaqillik maydoni')
        ->and($fresh->comment)->toBe('Yangi izoh');
});

test('operator edit is refused when the order finished meanwhile', function () {
    $order = $this->orders->create(orderData($this->branch));
    $stale = $order->fresh();
    $this->orders->cancelByClient($order->fresh(), null, 'Kerak emas');

    expect(fn () => $this->orders->update($stale, ['comment' => 'x'], $this->operator))
        ->toThrow(InvalidArgumentException::class);
    expect($order->fresh()->comment)->toBeNull();
});

test('start and complete store place names once on the order', function () {
    Landmark::factory()->create(['name' => 'Chorsu bozori', 'category' => 'market', 'location' => Point::makeGeodetic(40.3870, 71.7870)]);
    $driver = driverNear($this->branch, 300, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch));
    $this->dispatcher->accept($order->fresh(), $driver->fresh());

    $this->orders->start($order->fresh(), $driver->fresh(), Point::makeGeodetic(40.3870, 71.7870));
    $this->orders->complete($order->fresh(), $driver->fresh(), 3000, at: Point::makeGeodetic(40.5, 71.5));

    $fresh = $order->fresh();
    expect($fresh->start_name)->toBe('Chorsu bozori')
        // Yaqinda nomli joy yo'q — bo'sh satr (tarixda qayta qidirilmaydi)
        ->and($fresh->finish_name)->toBe('');
});
