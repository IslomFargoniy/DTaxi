<?php

use App\Enums\DriverStatus;
use App\Enums\OfferResponse;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Events\DriverLocationUpdated;
use App\Events\OfferWithdrawn;
use App\Events\OrderOffered;
use App\Events\OrderUpdated;
use App\Jobs\ExpireDirectOffer;
use App\Models\Branch;
use App\Models\DriverLocation;
use App\Models\User;
use App\Services\Dispatch\Dispatcher;
use App\Services\Dispatch\DriverDiscipline;
use App\Services\Orders\OrderService;
use Clickbar\Magellan\Data\Geometries\Point;
use Database\Seeders\RolesSeeder;
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
    $this->orders = app(OrderService::class);
    $this->dispatcher = app(Dispatcher::class);
});

test('after a driver cancels, drivers who saw the first offer get it again but the canceller does not', function () {
    $canceller = driverNear($this->branch, 300, ['balance' => 50000]);
    $other = driverNear($this->branch, 600);
    $order = $this->orders->create(orderData($this->branch));
    $this->dispatcher->accept($order, $canceller);

    $this->orders->cancelByDriver($order->fresh(), $canceller->fresh(), 'Mashina buzildi');

    $order->refresh();
    $offer = $order->offers()->where('driver_id', $other->id)->first();
    expect($order->status)->toBe(OrderStatus::Searching)
        ->and($offer->response)->toBeNull()
        ->and($offer->round)->toBe($order->search_round)
        ->and($order->offers()->where('driver_id', $canceller->id)->first()->response)->toBe(OfferResponse::Cancelled);

    // Bekor qilgan haydovchi qayta efirda ham ko'rmaydi, boshqasi esa qabul qila oladi
    $this->dispatcher->expand($order->fresh(), 0, $order->search_round);
    expect($order->offers()->where('driver_id', $canceller->id)->first()->response)->toBe(OfferResponse::Cancelled)
        ->and($this->dispatcher->accept($order->fresh(), $other->fresh()))->toBeTrue();
});

test('exhausted search is flagged once and keeps re-broadcasting to drivers who did not answer', function () {
    $order = $this->orders->create(orderData($this->branch));
    $silent = driverNear($this->branch, 500);
    $this->dispatcher->expand($order->fresh(), 0, $order->fresh()->search_round);
    $this->dispatcher->expand($order->fresh(), 1, $order->fresh()->search_round);
    expect($order->offers()->where('driver_id', $silent->id)->exists())->toBeTrue();

    $this->dispatcher->expand($order->fresh(), 2, $order->fresh()->search_round);
    $this->dispatcher->expand($order->fresh(), 2, $order->fresh()->search_round);
    $round = $order->fresh()->search_round;

    $this->artisan('orders:rebroadcast')->assertSuccessful();

    $order->refresh();
    expect($order->search_exhausted_at)->not->toBeNull()
        ->and($order->events()->where('type', 'search_exhausted')->count())->toBe(1)
        ->and($order->search_round)->toBe($round + 1)
        ->and($order->offers()->where('driver_id', $silent->id)->first()->round)->toBe($order->search_round)
        ->and($order->offers()->where('driver_id', $silent->id)->first()->response)->toBeNull();

    // Eski raunddagi kechikkan job hech narsa qilmaydi
    $this->dispatcher->expand($order->fresh(), 0, $round);
    expect($order->fresh()->search_radius_m)->toBe(8000);
});

test('completing twice charges commission once', function () {
    $driver = driverNear($this->branch, 500, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch));
    $this->dispatcher->accept($order, $driver);
    $this->orders->start($order->fresh(), $driver->fresh());

    $stale = $order->fresh();
    $this->orders->complete($order->fresh(), $driver->fresh(), 3000);
    $this->orders->complete($order->fresh(), $driver->fresh(), 3000);

    // Parallel takroriy so'rov (eskirgan model bilan) ham muvaffaqiyat — ilova 422 olib qotib qolmasin
    expect($this->orders->complete($stale, $driver->fresh(), 3000)->status)->toBe(OrderStatus::Completed)
        ->and($driver->transactions()->where('type', TransactionType::Commission)->count())->toBe(1);
});

test('a stale complete by another driver is still refused', function () {
    $driver = driverNear($this->branch, 500, ['balance' => 50000]);
    $other = driverNear($this->branch, 600, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch));
    $this->dispatcher->accept($order, $driver);
    $this->orders->start($order->fresh(), $driver->fresh());
    $stale = $order->fresh();
    $this->orders->complete($order->fresh(), $driver->fresh(), 3000);

    expect(fn () => $this->orders->complete($stale, $other->fresh(), 3000))->toThrow(InvalidArgumentException::class);
});

test('under-reported distance is charged commission by the GPS track', function () {
    $driver = driverNear($this->branch, 0, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch));
    $this->dispatcher->accept($order, $driver);
    $this->orders->start($order->fresh(), $driver->fresh());

    // ~5 km shimolga GPS treki
    foreach (range(1, 10) as $i) {
        DriverLocation::query()->create([
            'driver_id' => $driver->id,
            'location' => Point::makeGeodetic(40.3864 + $i * 0.0045, 71.7864),
            'recorded_at' => now()->addSeconds($i * 30),
        ]);
    }
    $this->travel(6)->minutes();
    $completed = $this->orders->complete($order->fresh(), $driver->fresh(), 0);

    // Mijoz narxi — ilova ko'rsatgani bo'yicha, komissiya — trek bo'yicha
    $event = $completed->events()->where('type', 'completed')->first();
    expect($completed->price_final)->toBe(10000)
        ->and($completed->commission)->toBeGreaterThan(1000)
        ->and($event->payload['suspicious'])->toBeTrue()
        ->and($event->payload['commission_base'])->toBeGreaterThan(10000);
});

test('operator cancel with a stale order still frees the driver who just accepted', function () {
    $driver = driverNear($this->branch, 500);
    $order = $this->orders->create(orderData($this->branch));
    $stale = $order->fresh();
    $this->dispatcher->accept($order, $driver);

    $this->orders->cancelByOperator($stale, User::factory()->create(), 'Mijoz rad etdi');

    expect($order->fresh()->status)->toBe(OrderStatus::CancelledByOperator)
        ->and($driver->fresh()->status)->toBe(DriverStatus::Free);
});

test('cancelling a finished order is refused and the operator sees a message instead of an error page', function () {
    $this->seed(RolesSeeder::class);
    $operator = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Operator);
    $order = $this->orders->create(orderData($this->branch));
    $this->orders->cancelByOperator($order->fresh(), $operator, 'birinchi');

    $this->actingAs($operator)
        ->post(route('operator.orders.cancel', $order), ['reason' => 'ikkinchi'])
        ->assertRedirect();

    expect($order->fresh()->cancel_reason)->toBe('birinchi');
});

test('direct offer is refused for an order that is already taken', function () {
    $taker = driverNear($this->branch, 300);
    $other = driverNear($this->branch, 600);
    $order = $this->orders->create(orderData($this->branch));
    $this->dispatcher->accept($order, $taker);

    expect(fn () => $this->dispatcher->offerDirect($order->fresh(), $other, User::factory()->create()))
        ->toThrow(InvalidArgumentException::class);
});

test('expired direct offer on a new order goes on air without errors, and no penalty if someone else took it', function () {
    $driver = driverNear($this->branch, 500, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch), dispatch: false);
    $offer = $this->dispatcher->offerDirect($order, $driver, User::factory()->create());

    expect($order->fresh()->search_radius_m)->not->toBeNull();

    $other = driverNear($this->branch, 700);
    $this->dispatcher->broadcast($order->fresh());
    $this->dispatcher->accept($order->fresh(), $other);

    (new ExpireDirectOffer($offer->id))->handle($this->dispatcher, app(DriverDiscipline::class));
    expect($driver->fresh()->balance)->toBe(50000);
});

test('stuck busy driver without an active order is released', function () {
    $driver = driverNear($this->branch, 500, ['status' => DriverStatus::Busy]);

    $this->artisan('drivers:release-stuck')->assertSuccessful();

    expect($driver->fresh()->status)->toBe(DriverStatus::Free);
});

test('buffered GPS points keep their own time and do not move the driver back', function () {
    $driver = driverNear($this->branch, 0);
    Sanctum::actingAs($driver->user, ['driver']);
    $now = now()->getTimestampMs();

    $this->postJson('/api/driver/v1/location', ['points' => [
        ['lat' => 40.40, 'lng' => 71.78, 't' => $now - 600_000],
        ['lat' => 40.41, 'lng' => 71.78, 't' => $now - 1_000],
    ]])->assertOk();

    $driver->refresh();
    expect($driver->last_location->getLatitude())->toEqualWithDelta(40.41, 0.0001)
        ->and(DriverLocation::query()->where('driver_id', $driver->id)->orderBy('recorded_at')->first()->recorded_at->getTimestampMs())
        ->toEqualWithDelta($now - 600_000, 1000);

    // Kechikib kelgan eski nuqta hozirgi joyni o'zgartirmaydi
    $this->postJson('/api/driver/v1/location', ['points' => [['lat' => 40.30, 'lng' => 71.70, 't' => $now - 1_200_000]]])->assertOk();
    expect($driver->fresh()->last_location->getLatitude())->toEqualWithDelta(40.41, 0.0001);
    Event::assertDispatchedTimes(DriverLocationUpdated::class, 1);
});

test('disabled users lose app tokens and panel sessions', function () {
    $driver = driverNear($this->branch, 0);
    $token = $driver->user->createToken('phone', ['driver'])->plainTextToken;

    $this->withToken($token)->getJson('/api/driver/v1/me')->assertOk();

    $driver->user->update(['is_active' => false]);
    expect($driver->user->tokens()->count())->toBe(0);

    $operator = User::factory()->create(['is_active' => false]);
    $this->actingAs($operator)->get('/dashboard')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', __('Your account is disabled.'));
});

test('impossible GPS jumps (two phones on one account) do not inflate the commission', function () {
    $driver = driverNear($this->branch, 0, ['balance' => 50000]);
    $order = $this->orders->create(orderData($this->branch));
    $this->dispatcher->accept($order, $driver);
    $this->orders->start($order->fresh(), $driver->fresh());

    // Har soniyada ikki shahar orasida "sakraydi"
    foreach (range(1, 20) as $i) {
        DriverLocation::query()->create([
            'driver_id' => $driver->id,
            'location' => $i % 2 ? Point::makeGeodetic(40.3864, 71.7864) : Point::makeGeodetic(41.31, 69.28),
            'recorded_at' => now()->addSeconds($i),
        ]);
    }
    $this->travel(1)->minutes();
    $completed = $this->orders->complete($order->fresh(), $driver->fresh(), 0);

    expect($completed->commission)->toBe(1000)
        ->and($completed->events()->where('type', 'completed')->first()->payload['commission_base'])->toBeNull();
});
