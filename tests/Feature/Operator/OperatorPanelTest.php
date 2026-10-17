<?php

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Events\OfferWithdrawn;
use App\Events\OrderOffered;
use App\Events\OrderUpdated;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Landmark;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderService;
use Carbon\CarbonImmutable;
use Clickbar\Magellan\Data\Geometries\Point;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Orders/helpers.php';

beforeEach(function () {
    Queue::fake();
    Event::fake([OrderOffered::class, OrderUpdated::class, OfferWithdrawn::class]);
    Http::fake();
    $this->seed(RolesSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->operator = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Operator);
});

test('operator opens the panel', function () {
    $this->actingAs($this->operator)->get(route('operator.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('operator/index')->has('branches', 1)->has('tariffClasses', 4));
});

test('cashier cannot open the operator panel', function () {
    $cashier = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Cashier);

    $this->actingAs($cashier)->get(route('operator.index'))->assertForbidden();
});

test('operator creates an order and it goes on air', function () {
    $driver = driverNear($this->branch, 400);

    $this->actingAs($this->operator)->post(route('operator.orders.store'), orderData($this->branch, ['comment' => 'Oq darvoza']))
        ->assertRedirect();

    $order = Order::query()->firstOrFail();
    expect($order->status)->toBe(OrderStatus::Searching)
        ->and($order->operator_id)->toBe($this->operator->id)
        ->and($order->offers()->pluck('driver_id')->all())->toBe([$driver->id]);
});

test('operator cannot create orders for another branch', function () {
    $other = Branch::factory()->create();

    $this->actingAs($this->operator)->post(route('operator.orders.store'), orderData($other))
        ->assertSessionHasErrors('branch_id');
});

test('operator only sees own branch orders', function () {
    $other = Branch::factory()->create();
    Order::factory()->create(['branch_id' => $other->id]);
    Order::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->operator)->get(route('operator.index'))
        ->assertInertia(fn ($page) => $page->has('orders', 1));
});

test('client card shows recent addresses and blacklist flag', function () {
    $client = Client::factory()->create(['phone' => '+998901234567', 'is_blacklisted' => true]);
    $client->addresses()->create(['text' => 'Uy', 'location' => Point::makeGeodetic(40.38, 71.78), 'last_used_at' => now()]);

    $this->actingAs($this->operator)->getJson(route('operator.lookup.client', ['phone' => '90 123 45 67']))
        ->assertOk()
        ->assertJsonPath('client.is_blacklisted', true)
        ->assertJsonPath('addresses.0.text', 'Uy');
});

test('landmark search finds latin, cyrillic and misspelled names', function () {
    Landmark::factory()->create(['name' => 'Chorsu bozori', 'aliases' => ['Чорсу']]);
    Landmark::factory()->create(['name' => '3-maktab']);

    foreach (['chorsu', 'Чорсу', 'chorsy boz'] as $query) {
        $this->actingAs($this->operator)->getJson(route('operator.lookup.landmarks', ['q' => $query]))
            ->assertOk()->assertJsonPath('0.name', 'Chorsu bozori');
    }
});

test('operator adds a new landmark from the map', function () {
    $this->actingAs($this->operator)->postJson(route('operator.landmarks.store'), [
        'name' => "Yangi to'yxona", 'lat' => 40.39, 'lng' => 71.78,
    ])->assertCreated();

    expect(Landmark::query()->where('name', "Yangi to'yxona")->exists())->toBeTrue();
});

test('operator assigns a driver manually', function () {
    $driver = driverNear($this->branch, 400);
    $order = app(OrderService::class)->create(orderData($this->branch), dispatch: false);

    $this->actingAs($this->operator)->post(route('operator.orders.assign', $order), ['driver_id' => $driver->id])->assertRedirect();

    expect($order->offers()->where('driver_id', $driver->id)->value('is_direct'))->toBeTrue();
});

test('operator cancels an order', function () {
    $order = app(OrderService::class)->create(orderData($this->branch));

    $this->actingAs($this->operator)->post(route('operator.orders.cancel', $order), ['reason' => 'Mijoz rad etdi', 'by_client' => true])
        ->assertRedirect();

    expect($order->fresh()->status)->toBe(OrderStatus::CancelledByClient);
});

test('pre-order waits and goes on air shortly before its time', function () {
    $driver = driverNear($this->branch, 400);
    $local = now($this->branch->timezone)->addHours(2)->format('Y-m-d\TH:i');

    $this->actingAs($this->operator)->post(route('operator.orders.store'), orderData($this->branch, ['scheduled_at' => $local]))
        ->assertSessionHasNoErrors();

    $order = Order::query()->sole();
    expect($order->status)->toBe(OrderStatus::New)
        ->and($order->scheduled_at->toDateTimeString())->toBe(CarbonImmutable::createFromFormat('Y-m-d\TH:i', $local, $this->branch->timezone)->utc()->toDateTimeString())
        ->and($order->offers()->count())->toBe(0);

    $this->travel(1)->hours();
    expect(app(OrderService::class)->dispatchScheduled())->toBe(0);

    $this->travel(50)->minutes();
    $driver->forceFill(['last_seen_at' => now()])->save();
    expect(app(OrderService::class)->dispatchScheduled())->toBe(1)
        ->and($order->refresh()->status)->toBe(OrderStatus::Searching)
        ->and($order->offers()->pluck('driver_id')->all())->toBe([$driver->id]);
});

test('pre-order time must be in the future', function () {
    $this->actingAs($this->operator)->post(route('operator.orders.store'), orderData($this->branch, [
        'scheduled_at' => now($this->branch->timezone)->subHour()->format('Y-m-d\TH:i'),
    ]))->assertSessionHasErrors('scheduled_at');
});
