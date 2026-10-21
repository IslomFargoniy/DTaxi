<?php

use App\Enums\DriverStatus;
use App\Enums\OfferResponse;
use App\Enums\OrderStatus;
use App\Enums\TransactionType;
use App\Events\DriverLocationUpdated;
use App\Events\OfferWithdrawn;
use App\Events\OrderOffered;
use App\Events\OrderUpdated;
use App\Jobs\ExpandOrderSearch;
use App\Jobs\ExpireDirectOffer;
use App\Jobs\SendPushNotification;
use App\Models\Branch;
use App\Models\DriverTransaction;
use App\Models\User;
use App\Services\Dispatch\Dispatcher;
use App\Services\Dispatch\DriverDiscipline;
use App\Services\Orders\OrderService;
use App\Services\Push\FcmPushNotifier;
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

function expireOffer(int $offerId): void
{
    (new ExpireDirectOffer($offerId))->handle(app(Dispatcher::class), app(DriverDiscipline::class));
}

function penalties(int $driverId): int
{
    return DriverTransaction::query()->where('driver_id', $driverId)->where('type', TransactionType::Penalty)->count();
}

test('rejected direct offer on a pre-order restarts the normal search with expansion', function () {
    $assigned = driverNear($this->branch, 300, ['balance' => 50000]);
    $other = driverNear($this->branch, 600);
    $order = $this->orders->create(orderData($this->branch, ['scheduled_at' => now()->addHours(3)]));
    expect($order->fresh()->status)->toBe(OrderStatus::New);

    $this->dispatcher->offerDirect($order->fresh(), $assigned, $this->operator);
    $round = $order->fresh()->search_round;
    $this->dispatcher->reject($order->fresh(), $assigned);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Searching)
        ->and($order->search_round)->toBe($round + 1)
        ->and($order->offers()->where('driver_id', $other->id)->first()?->response)->toBeNull()
        // Rad etgan haydovchiga shu raundda qayta taklif ketmaydi
        ->and($order->offers()->where('driver_id', $assigned->id)->first()->response)->toBe(OfferResponse::Rejected)
        ->and(penalties($assigned->id))->toBe(1);
    Queue::assertPushed(ExpandOrderSearch::class, fn ($job) => $job->orderId === $order->id && $job->round === $order->search_round);
});

test('expired direct offer on a pre-order restarts the normal search with expansion', function () {
    $assigned = driverNear($this->branch, 300, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch, ['scheduled_at' => now()->addHours(3)]));

    $offer = $this->dispatcher->offerDirect($order->fresh(), $assigned, $this->operator);
    expireOffer($offer->id);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Searching)
        ->and($offer->fresh()->response)->toBe(OfferResponse::Ignored);
    Queue::assertPushed(ExpandOrderSearch::class, fn ($job) => $job->orderId === $order->id && $job->round === $order->search_round);
});

test('direct offer is refused for busy, offline, blocked or unapproved drivers', function (array $attributes) {
    $driver = driverNear($this->branch, 300, $attributes);
    $order = $this->orders->create(orderData($this->branch), dispatch: false);

    expect(fn () => $this->dispatcher->offerDirect($order->fresh(), $driver, $this->operator))
        ->toThrow(InvalidArgumentException::class, __('The driver is not available for orders right now.'));
    expect($order->fresh()->status)->toBe(OrderStatus::New)
        ->and($order->offers()->count())->toBe(0);
})->with([
    'busy' => [['status' => DriverStatus::Busy]],
    'offline' => [['status' => DriverStatus::Offline]],
    'not seen' => [['last_seen_at' => now()->subMinutes(10)]],
    'blocked' => [['blocked_until' => now()->addMinutes(10)]],
    'unapproved' => [['is_approved' => false]],
]);

test('direct offer expiring while the driver is busy with another order costs no penalty', function () {
    $driver = driverNear($this->branch, 300, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch), dispatch: false);
    $offer = $this->dispatcher->offerDirect($order->fresh(), $driver, $this->operator);
    $driver->forceFill(['status' => DriverStatus::Busy])->save();

    expireOffer($offer->id);

    expect(penalties($driver->id))->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::Searching);
});

test('reject racing the expiry job penalises the driver only once', function (bool $rejectFirst) {
    $driver = driverNear($this->branch, 300, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch), dispatch: false);
    $offer = $this->dispatcher->offerDirect($order->fresh(), $driver, $this->operator);
    $stale = $order->fresh();

    if ($rejectFirst) {
        $this->dispatcher->reject($stale, $driver);
        expireOffer($offer->id);
    } else {
        expireOffer($offer->id);
        $this->dispatcher->reject($stale, $driver);
    }

    expect(penalties($driver->id))->toBe(1)
        ->and($driver->fresh()->balance)->toBe(48000);
})->with(['reject first' => true, 'expiry first' => false]);

test('scheduled dispatch with a pre-loaded order does not revive an order cancelled meanwhile', function () {
    driverNear($this->branch, 300);
    $order = $this->orders->create(orderData($this->branch, ['scheduled_at' => now()->addHours(3)]));
    $loaded = $order->fresh();

    $this->orders->cancelByClient($order->fresh(), $this->operator, 'Kerak emas');

    // dispatchScheduled ro'yxatni oldindan o'qigan — shu nusxa bilan efirga chiqarishga urinadi
    expect($this->dispatcher->startSearch($loaded, [OrderStatus::New]))->toBeFalse();
    expect($order->fresh()->status)->toBe(OrderStatus::CancelledByClient)
        ->and($order->offers()->count())->toBe(0);

    $this->travel(3)->hours();
    expect($this->orders->dispatchScheduled())->toBe(0);
});

test('restarting the search after a driver cancel does not revive an order the operator cancelled', function () {
    $driver = driverNear($this->branch, 300, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch));
    $this->dispatcher->accept($order->fresh(), $driver);
    $stale = $order->fresh();

    $this->orders->cancelByOperator($order->fresh(), $this->operator, 'Mijoz rad etdi');

    expect($this->dispatcher->restartSearch($stale))->toBeFalse()
        ->and($order->fresh()->status)->toBe(OrderStatus::CancelledByOperator);
    expect(fn () => $this->orders->cancelByDriver($stale, $driver->fresh(), 'test'))->toThrow(InvalidArgumentException::class);
    expect($order->fresh()->status)->toBe(OrderStatus::CancelledByOperator);
});

test('a stale expansion job does not broadcast an order that was already taken', function () {
    $taker = driverNear($this->branch, 300);
    $order = $this->orders->create(orderData($this->branch));
    $stale = $order->fresh();
    $far = driverNear($this->branch, 4500);
    $this->dispatcher->accept($order->fresh(), $taker);

    $this->dispatcher->expand($stale, 0, $stale->search_round);
    expect($this->dispatcher->broadcast($stale))->toBe(0);

    expect($order->fresh()->search_radius_m)->toBe(3000)
        ->and($order->offers()->where('driver_id', $far->id)->exists())->toBeFalse();
});

test('an order nobody takes stops being re-broadcast after the age limit and becomes "no driver found"', function () {
    $silent = driverNear($this->branch, 500);
    $order = $this->orders->create(orderData($this->branch));
    foreach ([0, 1, 2] as $step) {
        $this->dispatcher->expand($order->fresh(), $step);
    }
    expect($order->fresh()->search_exhausted_at)->not->toBeNull();

    $this->travel(20)->minutes();
    $this->artisan('orders:rebroadcast')->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::Searching);

    $this->travel(11)->minutes();
    $this->artisan('orders:rebroadcast')->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::NoDriverFound)
        ->and($order->events()->where('type', 'no_driver_found')->exists())->toBeTrue()
        ->and($order->offers()->where('driver_id', $silent->id)->first()->response)->toBe(OfferResponse::Ignored);
});

test('offer payloads carry a fresh offered_at on every (re)offer, also in push data', function () {
    $driver = driverNear($this->branch, 500);
    $order = $this->orders->create(orderData($this->branch));

    $first = null;
    Event::assertDispatched(OrderOffered::class, function (OrderOffered $e) use (&$first) {
        $first = $e->broadcastWith()['offered_at'];

        return is_int($first);
    });
    Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->data['offered_at'] === $first
        && ! array_key_exists('dropoff_text', $job->data) && ! in_array(null, $job->data, true));

    // Qayta efir — o'sha qator, lekin yangi vaqt
    $this->travel(2)->seconds();
    foreach ([0, 1, 2] as $step) {
        $this->dispatcher->expand($order->fresh(), $step);
    }
    $this->artisan('orders:rebroadcast')->assertSuccessful();

    $times = collect(Event::dispatched(OrderOffered::class))->map(fn (array $args) => $args[0]->broadcastWith()['offered_at']);
    expect($times)->toHaveCount(2)
        ->and($times->last())->toBeGreaterThan($first)
        ->and($order->offers()->where('driver_id', $driver->id)->count())->toBe(1);

    // Tayinlangan taklif ham yangi vaqt oladi
    $this->travel(2)->seconds();
    $this->dispatcher->offerDirect($order->fresh(), $driver->fresh(), $this->operator);
    $direct = collect(Event::dispatched(OrderOffered::class))->last()[0]->broadcastWith();
    expect($direct['is_direct'])->toBeTrue()
        ->and($direct['offered_at'])->toBeGreaterThan($times->last());
});

test('FCM data skips null values and keeps booleans parseable', function () {
    expect(FcmPushNotifier::stringify(['a' => null, 'b' => 5, 'c' => true, 'd' => false, 'e' => 'x']))
        ->toBe(['b' => '5', 'c' => '1', 'd' => '0', 'e' => 'x']);
});
