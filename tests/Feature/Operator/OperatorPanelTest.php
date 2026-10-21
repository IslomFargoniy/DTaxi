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
use App\Services\Dispatch\Dispatcher;
use App\Services\Orders\OrderService;
use Carbon\CarbonImmutable;
use Clickbar\Magellan\Data\Geometries\Point;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
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

test('map fallback center comes from branch data, not a hardcoded city', function () {
    $this->branch->forceFill(['center' => null])->save();
    Branch::factory()->create(['center' => Point::makeGeodetic(41.31, 69.28)]);

    $this->actingAs($this->operator)->get(route('operator.index'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('map.center.lat', 41.31)
            ->where('map.center.lng', 69.28));
});

test('without any branch center the map centers on recent pickups', function () {
    $this->branch->forceFill(['center' => null])->save();
    Order::factory()->create(['branch_id' => $this->branch->id, 'pickup_location' => Point::makeGeodetic(40.38, 71.78)]);
    Order::factory()->create(['branch_id' => $this->branch->id, 'pickup_location' => Point::makeGeodetic(40.46, 71.72)]);

    $this->actingAs($this->operator)->get(route('operator.index'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('map.center.lat', fn ($lat) => abs($lat - 40.42) < 0.001)
            ->where('map.center.lng', fn ($lng) => abs($lng - 71.75) < 0.001));
});

test('operator map gets the branch service area to draw', function () {
    $this->branch->forceFill(['center' => Point::makeGeodetic(40.3864, 71.7864)])->save();
    DB::update('UPDATE branches SET service_area = ST_Multi(ST_Buffer(center, 5000)::geometry)::geography WHERE id = ?', [$this->branch->id]);

    $this->actingAs($this->operator)->get(route('operator.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('branches.0.area.type', fn ($type) => in_array($type, ['Polygon', 'MultiPolygon'], true))->has('branches.0.area.coordinates.0'));
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

test('operator edits a waiting order: extra price goes on air again and is paid on completion', function () {
    $driver = driverNear($this->branch, 500, ['balance' => 50000]);
    $order = app(OrderService::class)->create(orderData($this->branch));
    $round = $order->fresh()->search_round;

    $this->actingAs($this->operator)
        ->patch(route('operator.orders.update', $order), ['comment' => '3-podyezd', 'price_bonus' => 5000])
        ->assertRedirect();

    $order->refresh();
    expect($order->comment)->toBe('3-podyezd')
        ->and($order->price_bonus)->toBe(5000)
        ->and($order->search_round)->toBe($round + 1)
        ->and($order->offers()->where('driver_id', $driver->id)->first()->response)->toBeNull();

    $orders = app(OrderService::class);
    app(Dispatcher::class)->accept($order, $driver);
    $orders->start($order->fresh(), $driver->fresh());
    $completed = $orders->complete($order->fresh(), $driver->fresh(), 3000);

    expect($completed->price_final)->toBe(15000)
        ->and($completed->price_breakdown['bonus'])->toBe(5000);
});

test('tariff and pickup cannot be changed once a driver is assigned, comment can', function () {
    $driver = driverNear($this->branch, 500);
    $order = app(OrderService::class)->create(orderData($this->branch));
    app(Dispatcher::class)->accept($order, $driver);
    $otherClass = $this->branch->tariffClasses()->where('id', '!=', $order->tariff_class_id)->first();

    $this->actingAs($this->operator)
        ->patch(route('operator.orders.update', $order), ['comment' => 'Yangi izoh', 'tariff_class_id' => $otherClass->id])
        ->assertRedirect();

    expect($order->fresh()->comment)->toBe('Yangi izoh')
        ->and($order->fresh()->tariff_class_id)->toBe($order->tariff_class_id);
});

test('operator re-dispatches a searching order to everyone again', function () {
    $driver = driverNear($this->branch, 500);
    $order = app(OrderService::class)->create(orderData($this->branch));
    $round = $order->fresh()->search_round;

    $this->actingAs($this->operator)->post(route('operator.orders.redispatch', $order))->assertRedirect();

    expect($order->fresh()->search_round)->toBe($round + 1)
        ->and($order->offers()->where('driver_id', $driver->id)->first()->round)->toBe($round + 1);
});

test('client card warns about an active order and offers recent destinations', function () {
    $orders = app(OrderService::class);
    $orders->create(orderData($this->branch, ['dropoff_text' => 'Vokzal', 'dropoff_lat' => 40.37, 'dropoff_lng' => 71.79]));

    $this->actingAs($this->operator)
        ->getJson(route('operator.lookup.client', ['phone' => '90 123 45 67']))
        ->assertOk()
        ->assertJsonPath('orders.0.status', OrderStatus::Searching->value)
        ->assertJsonPath('dropoffs.0.text', 'Vokzal');
});

test('order with stops: distance and estimate include every leg, driver sees the stops', function () {
    $direct = app(OrderService::class)->create(orderData($this->branch, ['dropoff_text' => 'Vokzal', 'dropoff_lat' => 40.40, 'dropoff_lng' => 71.7864]), dispatch: false);

    $this->actingAs($this->operator)->post(route('operator.orders.store'), [
        ...orderData($this->branch, ['dropoff_text' => 'Vokzal', 'dropoff_lat' => 40.40, 'dropoff_lng' => 71.7864]),
        'stops' => [['text' => 'Dorixona', 'lat' => 40.3864, 'lng' => 71.80]],
    ])->assertRedirect();

    $order = Order::query()->latest('id')->firstOrFail();
    expect($order->stops)->toEqual([['text' => 'Dorixona', 'lat' => 40.3864, 'lng' => 71.8]])
        ->and($order->distance_m)->toBeGreaterThan($direct->distance_m)
        ->and($order->price_estimate)->toBeGreaterThanOrEqual($direct->price_estimate);
    expect(OrderOffered::payload($order->load('tariffClass'), $order->offers()->firstOrNew([], ['offered_at' => now()]), 20)['stops_text'])->toBe('Dorixona');
});

test('a point on the map gets the nearest house address as its name', function () {
    $house = Landmark::query()->create(['name' => "Mustaqillik ko'chasi, 22", 'category' => 'address', 'location' => Point::makeGeodetic(40.3866, 71.7864)]);
    Landmark::query()->create(['name' => 'Uzoq bozor', 'location' => Point::makeGeodetic(40.40, 71.80)]);

    $this->actingAs($this->operator)
        ->getJson(route('operator.lookup.reverse', ['lat' => 40.3864, 'lng' => 71.7864]))
        ->assertOk()->assertJsonPath('name', $house->name);
    $this->getJson(route('operator.lookup.reverse', ['lat' => 41.0, 'lng' => 70.0]))->assertJsonPath('name', null);

    // Operator matn yozmay yuborsa ham haydovchi manzil nomini ko'radi
    $order = app(OrderService::class)->create(orderData($this->branch, ['pickup_text' => 'Xaritadagi nuqta']), dispatch: false);
    expect($order->pickup_text)->toBe("Mustaqillik ko'chasi, 22");
});

test('client lookup returns the last order for one-click repeat', function () {
    $orders = app(OrderService::class);
    $orders->create(orderData($this->branch, ['pickup_text' => 'Eski joy']), dispatch: false);
    $last = $orders->create(orderData($this->branch, [
        'dropoff_text' => 'Vokzal', 'dropoff_lat' => 40.37, 'dropoff_lng' => 71.79, 'comment' => 'Darvoza oldida',
    ]), dispatch: false);

    $this->actingAs($this->operator)
        ->getJson(route('operator.lookup.client', ['phone' => $last->client->phone]))
        ->assertOk()
        ->assertJsonPath('last_order.id', $last->id)
        ->assertJsonPath('last_order.tariff_class_id', $last->tariff_class_id)
        ->assertJsonPath('last_order.pickup.text', $last->pickup_text)
        ->assertJsonPath('last_order.dropoff.text', 'Vokzal')
        ->assertJsonPath('last_order.comment', 'Darvoza oldida');

    $this->getJson(route('operator.lookup.client', ['phone' => '+998 99 000 00 00']))
        ->assertOk()->assertJsonPath('last_order', null);
});

test('operator goes offline on logout', function () {
    $this->operator->forceFill(['operator_status' => 'ready'])->save();

    $this->actingAs($this->operator)->post(route('logout'))->assertRedirect();

    expect($this->operator->fresh()->operator_status)->toBe('offline');
});
