<?php

use App\Enums\DriverStatus;
use App\Enums\Role;
use App\Enums\TransactionSource;
use App\Events\ChatMessageSent;
use App\Events\OfferWithdrawn;
use App\Events\OrderOffered;
use App\Events\OrderUpdated;
use App\Models\Branch;
use App\Models\CashShift;
use App\Models\Client;
use App\Models\Complaint;
use App\Models\Driver;
use App\Models\Landmark;
use App\Models\OrderCancellation;
use App\Models\Region;
use App\Models\TariffClass;
use App\Models\User;
use App\Services\Dispatch\Dispatcher;
use App\Services\Orders\OrderService;
use App\Services\Regions\OsmRegionImporter;
use App\Support\Settings;
use Database\Seeders\RolesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Orders/helpers.php';

beforeEach(function () {
    Queue::fake();
    $this->seed(RolesSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->admin = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Admin);
    $this->superadmin = User::factory()->create()->assignRole(Role::Superadmin);
});

function driverPayload(Branch $branch, array $overrides = []): array
{
    return [
        'branch_id' => $branch->id,
        'name' => 'Aziz Karimov',
        'phone' => '90 555 44 33',
        'password' => 'secret12',
        'callsign' => '777',
        'vehicle' => [
            'brand' => 'Chevrolet', 'model' => 'Cobalt', 'color' => 'oq', 'plate_number' => '40 a 123 bc',
            'tariff_class_ids' => TariffClass::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('name', 'Standart')->pluck('id')->all(),
        ],
        ...$overrides,
    ];
}

test('superadmin creates a branch whose service area comes from the chosen district', function () {
    app(OsmRegionImporter::class)->import(base_path('tests/fixtures/boundaries.geojsonseq'));
    $district = Region::query()->where('name_uz', 'Rishton tumani')->firstOrFail();

    $this->actingAs($this->superadmin)->post(route('admin.branches.store'), [
        'name' => 'Rishton', 'region_id' => $district->id, 'timezone' => 'Asia/Tashkent',
        'settings' => ['drivers__commission_percent' => '12'],
    ])->assertRedirect(route('admin.branches.index'));

    $branch = Branch::query()->where('name', 'Rishton')->firstOrFail();
    expect($branch->service_area)->not->toBeNull()
        ->and($branch->tariffClasses()->withoutGlobalScopes()->count())->toBe(4)
        ->and(app(Settings::class)->get('drivers.commission_percent', $branch))->toBe(12);
});

test('branch admin cannot manage branches', function () {
    $this->actingAs($this->admin)->get(route('admin.branches.index'))->assertForbidden();
});

test('admin registers a driver with car; driver must be approved before going online', function () {
    $this->actingAs($this->admin)->post(route('admin.drivers.store'), driverPayload($this->branch))->assertRedirect();

    $driver = Driver::query()->where('callsign', '777')->firstOrFail();
    expect($driver->user->phone)->toBe('+998905554433')
        ->and($driver->user->hasRole(Role::Driver))->toBeTrue()
        ->and($driver->vehicle->plate_number)->toBe('40A123BC')
        ->and($driver->vehicle->tariffClasses)->toHaveCount(1)
        ->and($driver->is_approved)->toBeFalse();

    $this->actingAs($this->admin)->post(route('admin.drivers.approve', $driver), ['approved' => true]);
    expect($driver->fresh()->is_approved)->toBeTrue()->and($driver->fresh()->vehicle->is_approved)->toBeTrue();
});

test('changing the plate creates a new car that needs approval again', function () {
    $this->actingAs($this->admin)->post(route('admin.drivers.store'), driverPayload($this->branch));
    $driver = Driver::query()->where('callsign', '777')->firstOrFail();
    $this->post(route('admin.drivers.approve', $driver), ['approved' => true]);
    $old = $driver->vehicle;

    $this->put(route('admin.drivers.update', $driver), driverPayload($this->branch, [
        'password' => null,
        'vehicle' => [...driverPayload($this->branch)['vehicle'], 'plate_number' => '40B999CC'],
    ]))->assertSessionHasNoErrors();

    expect($old->fresh()->is_current)->toBeFalse()
        ->and($driver->fresh()->vehicle->plate_number)->toBe('40B999CC')
        ->and($driver->fresh()->vehicle->is_approved)->toBeFalse();
});

test('admins only see drivers of their branch', function () {
    $other = Branch::factory()->create();
    $foreign = driverNear($other, 0);

    $this->actingAs($this->admin)->get(route('admin.drivers.edit', $foreign))->assertNotFound();
});

test('admin adjusts balance with a mandatory comment', function () {
    $driver = driverNear($this->branch, 0, ['balance' => 0]);

    $this->actingAs($this->admin)->post(route('admin.drivers.balance', $driver), ['amount' => 5000])->assertSessionHasErrors('comment');
    $this->post(route('admin.drivers.balance', $driver), ['amount' => 5000, 'comment' => 'Bonus'])->assertRedirect();

    expect($driver->fresh()->balance)->toBe(5000);
});

test('admin uploads and approves driver documents', function () {
    Storage::fake();
    $driver = driverNear($this->branch, 0);

    $this->actingAs($this->admin)->post(route('admin.drivers.documents.store', $driver), [
        'type' => 'license', 'file' => UploadedFile::fake()->image('prava.jpg'), 'expires_at' => '2030-01-01',
    ])->assertRedirect();

    $doc = $driver->documents()->firstOrFail();
    $this->put(route('admin.drivers.documents.review', [$driver, $doc]), ['status' => 'approved']);
    expect($doc->fresh()->status->value)->toBe('approved');
    $this->get(route('admin.drivers.documents.show', [$driver, $doc]))->assertOk();
});

test('blocking a driver takes them off the air', function () {
    $driver = driverNear($this->branch, 0);

    $this->actingAs($this->admin)->post(route('admin.drivers.block', $driver), ['minutes' => 60]);

    expect($driver->fresh()->isBlocked())->toBeTrue()->and($driver->fresh()->status)->toBe(DriverStatus::Offline);
});

test('admin creates operators and cashiers only for own branch', function () {
    $other = Branch::factory()->create();

    $this->actingAs($this->admin)->post(route('admin.staff.store'), [
        'name' => 'Dilnoza', 'email' => 'dilnoza@test.uz', 'password' => 'password12', 'role' => 'operator', 'branch_id' => $this->branch->id,
    ])->assertRedirect();
    expect(User::query()->where('email', 'dilnoza@test.uz')->firstOrFail()->hasRole(Role::Operator))->toBeTrue();

    $this->post(route('admin.staff.store'), [
        'name' => 'X', 'email' => 'x@test.uz', 'password' => 'password12', 'role' => 'operator', 'branch_id' => $other->id,
    ])->assertSessionHasErrors('branch_id');

    $this->post(route('admin.staff.store'), [
        'name' => 'Y', 'email' => 'y@test.uz', 'password' => 'password12', 'role' => 'admin', 'branch_id' => $this->branch->id,
    ])->assertSessionHasErrors('role');
});

test('admin edits tariff prices and rules', function () {
    $class = TariffClass::query()->where('branch_id', $this->branch->id)->where('name', 'Standart')->firstOrFail();

    $this->actingAs($this->admin)->put(route('admin.tariffs.update', $class), [
        'name' => 'Standart', 'sort' => 0, 'is_delivery' => false, 'is_default' => true, 'is_active' => true,
        'tariff' => [
            'base_fare' => 12000, 'base_km' => 2.5, 'per_km' => 2200, 'rounding_step' => 1000,
            'waiting_enabled' => true, 'waiting_free_min' => 3, 'waiting_per_min' => 500,
            'night_enabled' => true, 'night_from' => '23:00', 'night_to' => '06:00', 'night_coeff' => 1.3,
            'outside_enabled' => false, 'outside_per_km' => 3000,
        ],
    ])->assertSessionHasNoErrors();

    $tariff = $class->tariff()->first();
    expect($tariff->base_fare)->toBe(12000)->and($tariff->waiting_enabled)->toBeTrue()->and($tariff->rounding_step)->toBe(1000);
});

test('admin blacklists a client and edits landmarks', function () {
    $client = Client::factory()->create();
    $landmark = Landmark::factory()->create(['name' => 'Eski nom']);

    $this->actingAs($this->admin)->put(route('admin.clients.update', $client), ['is_blacklisted' => true, 'note' => 'Soxta chaqiruvlar']);
    $this->put(route('admin.landmarks.update', $landmark), ['name' => 'Yangi nom', 'aliases' => ['Новое'], 'lat' => 40.4, 'lng' => 71.8]);

    expect($client->fresh()->is_blacklisted)->toBeTrue()
        ->and($landmark->fresh()->name)->toBe('Yangi nom')
        ->and(Landmark::query()->search('новое')->first()?->id)->toBe($landmark->id);
});

test('admin sees order history with the event timeline', function () {
    $order = app(OrderService::class)->create(orderData($this->branch), dispatch: false);

    $this->actingAs($this->admin)->get(route('admin.orders.index'))->assertOk();
    $this->get(route('admin.orders.show', $order))->assertOk()
        ->assertInertia(fn ($page) => $page->where('events.0.type', 'created'));
});

test('cashier tops up a driver only within an open shift', function () {
    $cashier = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Cashier);
    $driver = driverNear($this->branch, 0, ['balance' => 0]);

    $this->actingAs($cashier)->post(route('cashier.topup'), ['driver_id' => $driver->id, 'amount' => 50000])->assertSessionHasErrors('amount');

    $this->post(route('cashier.shift.open'));
    $this->post(route('cashier.topup'), ['driver_id' => $driver->id, 'amount' => 50000])->assertSessionHasNoErrors();
    $this->post(route('cashier.topup'), ['driver_id' => $driver->id, 'amount' => 20000]);

    $shift = CashShift::query()->firstOrFail();
    expect($driver->fresh()->balance)->toBe(70000)
        ->and($shift->total_cash)->toBe(70000)
        ->and($driver->transactions()->first()->source)->toBe(TransactionSource::Cash);

    $this->post(route('cashier.shift.close'));
    expect($shift->fresh()->closed_at)->not->toBeNull();
});

test('cashier finds drivers by callsign, phone or plate', function () {
    $cashier = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Cashier);
    $driver = driverNear($this->branch, 0, ['callsign' => '512']);
    $plate = $driver->vehicle->plate_number;

    foreach (['512', strtolower($plate)] as $q) {
        $this->actingAs($cashier)->getJson(route('cashier.drivers.search', ['q' => $q]))->assertJsonPath('0.id', $driver->id);
    }
});

test('cashier cannot reach admin or operator pages', function () {
    $cashier = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Cashier);

    $this->actingAs($cashier)->get(route('admin.drivers.index'))->assertForbidden();
    $this->get(route('operator.index'))->assertForbidden();
    $this->get(route('cashier.index'))->assertOk()->assertInertia(fn ($page) => $page->component('cashier/index'));
    $this->get(route('dashboard'))->assertRedirect(route('cashier.index'));
});

test('every admin page renders for a branch admin', function (string $route) {
    $driver = driverNear($this->branch, 0);
    $params = str_contains($route, 'edit') ? [$driver] : [];

    $this->actingAs($this->admin)->get(route($route, $params))->assertOk();
})->with(['dashboard', 'admin.drivers.index', 'admin.drivers.create', 'admin.drivers.edit', 'admin.staff.index', 'admin.tariffs.index', 'admin.orders.index', 'admin.clients.index', 'admin.landmarks.index']);

test('operator lands on the operator panel', function () {
    $operator = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Operator);

    $this->actingAs($operator)->get(route('dashboard'))->assertRedirect(route('operator.index'));
});

test('operator chats with a driver', function () {
    Event::fake([ChatMessageSent::class]);
    $operator = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Operator);
    $driver = driverNear($this->branch, 0);

    $this->actingAs($operator)->postJson(route('operator.chat.send', $driver), ['body' => 'Mijoz 2 daqiqada chiqadi'])->assertCreated();
    $this->getJson(route('operator.chat.threads'))->assertJsonPath('0.driver_id', $driver->id);
    $this->getJson(route('operator.chat.messages', $driver))->assertJsonPath('0.body', 'Mijoz 2 daqiqada chiqadi');
});

test('operator logs a lost item for an order and admin resolves it', function () {
    $this->seed(RolesSeeder::class);
    $branch = Branch::factory()->create();
    $operator = User::factory()->create(['branch_id' => $branch->id])->assignRole(Role::Operator);
    Queue::fake();
    Http::fake();
    require_once __DIR__.'/../Orders/helpers.php';
    $order = app(OrderService::class)->create(orderData($branch), dispatch: false);

    $this->actingAs($operator)->post(route('admin.complaints.store'), ['order_id' => $order->id, 'type' => 'lost_item', 'text' => 'Sumka qolib ketdi'])->assertRedirect();
    $complaint = Complaint::query()->firstOrFail();
    expect($complaint->client_id)->toBe($order->client_id)->and($complaint->branch_id)->toBe($branch->id);

    $this->actingAs($operator)->put(route('admin.complaints.update', $complaint), ['status' => 'resolved', 'resolution' => 'Haydovchi olib keldi'])->assertRedirect();
    expect($complaint->fresh()->status)->toBe('resolved')->and($complaint->fresh()->resolved_by)->toBe($operator->id);

    $this->actingAs($operator)->get(route('admin.orders.show', $order))->assertOk()
        ->assertInertia(fn ($page) => $page->has('complaints', 1)->has('calls', 0));
});

test('admin returns an unfair penalty: money, rating and the cancel count come back', function () {
    $this->seed(RolesSeeder::class);
    $branch = Branch::factory()->create();
    $admin = User::factory()->create(['branch_id' => $branch->id])->assignRole(Role::Admin);
    Queue::fake();
    Http::fake();
    Event::fake([OrderOffered::class, OrderUpdated::class, OfferWithdrawn::class]);
    require_once __DIR__.'/../Orders/helpers.php';
    $driver = driverNear($branch, 300, ['balance' => 50000]);
    $order = app(OrderService::class)->create(orderData($branch));
    app(Dispatcher::class)->accept($order, $driver);
    app(OrderService::class)->cancelByDriver($order->fresh(), $driver->fresh(), 'Mijoz chiqmadi');
    $penalty = $driver->transactions()->where('type', 'penalty')->firstOrFail();

    $this->actingAs($admin)->post(route('admin.drivers.transactions.excuse', [$driver, $penalty]))->assertRedirect();
    $this->actingAs($admin)->post(route('admin.drivers.transactions.excuse', [$driver, $penalty]))->assertRedirect();

    expect($driver->fresh()->balance)->toBe(50000)
        ->and($driver->fresh()->rating)->toBe(100)
        ->and($penalty->fresh()->excused_at)->not->toBeNull()
        ->and(OrderCancellation::query()->where('driver_id', $driver->id)->value('penalty_amount'))->toBe(0);
});

test('client rating on a completed order lowers the driver rating once', function () {
    $this->seed(RolesSeeder::class);
    $branch = Branch::factory()->create();
    $admin = User::factory()->create(['branch_id' => $branch->id])->assignRole(Role::Admin);
    Queue::fake();
    Http::fake();
    Event::fake([OrderOffered::class, OrderUpdated::class, OfferWithdrawn::class]);
    require_once __DIR__.'/../Orders/helpers.php';
    $driver = driverNear($branch, 300, ['balance' => 50000, 'rating' => 90]);
    $orders = app(OrderService::class);
    $order = $orders->create(orderData($branch));
    app(Dispatcher::class)->accept($order, $driver);
    $orders->start($order->fresh(), $driver->fresh());
    $orders->complete($order->fresh(), $driver->fresh(), 2000);
    $rating = $driver->fresh()->rating;

    $this->actingAs($admin)->post(route('admin.orders.rate', $order), ['rating' => 2])->assertRedirect();
    $this->actingAs($admin)->post(route('admin.orders.rate', $order), ['rating' => 1])->assertRedirect();

    expect($order->fresh()->client_rating)->toBe(1)->and($driver->fresh()->rating)->toBe($rating - 5);
});
