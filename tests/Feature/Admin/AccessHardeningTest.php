<?php

use App\Enums\Role;
use App\Events\ChatMessageSent;
use App\Models\Branch;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\Company;
use App\Models\Landmark;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../Orders/helpers.php';

beforeEach(function () {
    Queue::fake();
    Event::fake([ChatMessageSent::class]);
    $this->seed(RolesSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->otherBranch = Branch::factory()->create();
    $this->admin = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Admin);
    $this->operator = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Operator);
});

test('driver login is limited per phone number, not only per IP', function () {
    $driver = driverNear($this->branch, 0);
    $driver->user->update(['phone' => '+998901112233', 'password' => 'secret123']);

    foreach (range(1, 5) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
            ->postJson('/api/driver/v1/auth/login', ['phone' => '90 111 22 33', 'password' => 'wrong', 'device_name' => 'x'])
            ->assertUnprocessable();
    }

    // Boshqa IP, boshqa yozilish — bari bir raqam
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
        ->postJson('/api/driver/v1/auth/login', ['phone' => '+998 90 111-22-33', 'password' => 'secret123', 'device_name' => 'x'])
        ->assertTooManyRequests();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
        ->postJson('/api/driver/v1/auth/login', ['phone' => '90 999 22 33', 'password' => 'wrong', 'device_name' => 'x'])
        ->assertUnprocessable();
});

test('driver tokens expire after long inactivity but not during a long shift', function () {
    $driver = driverNear($this->branch, 0);
    $token = $driver->user->createToken('phone', ['driver']);
    $token->accessToken->forceFill(['created_at' => now()->subDays(200), 'last_used_at' => now()->subDays(2)])->save();

    $this->withToken($token->plainTextToken)->getJson('/api/driver/v1/me')->assertOk();

    $token->accessToken->forceFill(['last_used_at' => now()->subDays(61)])->save();
    app('auth')->forgetGuards();

    $this->withToken($token->plainTextToken)->getJson('/api/driver/v1/me')->assertUnauthorized();
});

test('new driver passwords need at least 8 characters', function () {
    $payload = [
        'branch_id' => $this->branch->id,
        'name' => 'Aziz Karimov',
        'phone' => '90 555 44 33',
        'password' => 'abc123',
        'callsign' => '777',
        'vehicle' => ['brand' => 'Chevrolet', 'model' => 'Cobalt', 'color' => 'oq', 'plate_number' => '40 a 123 bc'],
    ];

    $this->actingAs($this->admin)->post(route('admin.drivers.store'), $payload)->assertSessionHasErrors('password');
});

test('branch admin cannot attach a client to another branch company', function () {
    $client = Client::factory()->create();
    $own = Company::query()->create(['branch_id' => $this->branch->id, 'name' => 'Own LLC']);
    $foreign = Company::query()->withoutGlobalScopes()->create(['branch_id' => $this->otherBranch->id, 'name' => 'Foreign LLC']);

    $this->actingAs($this->admin)->put(route('admin.clients.update', $client), ['company_id' => $foreign->id])
        ->assertSessionHasErrors('company_id');
    expect($client->fresh()->company_id)->toBeNull();

    $this->put(route('admin.clients.update', $client), ['company_id' => $own->id])->assertSessionHasNoErrors();
    expect($client->fresh()->company_id)->toBe($own->id);

    $superadmin = User::factory()->create()->assignRole(Role::Superadmin);
    $this->actingAs($superadmin)->put(route('admin.clients.update', $client), ['company_id' => $foreign->id])->assertSessionHasNoErrors();
    expect($client->fresh()->company_id)->toBe($foreign->id);
});

test('operators add landmarks but only admins edit or delete them', function () {
    $landmark = Landmark::factory()->create(['name' => 'Eski nom']);

    $this->actingAs($this->operator)->get(route('admin.landmarks.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManage', false));
    $this->put(route('admin.landmarks.update', $landmark), ['name' => 'Yangi', 'lat' => 40.4, 'lng' => 71.8])->assertForbidden();
    $this->delete(route('admin.landmarks.destroy', $landmark))->assertForbidden();
    $this->postJson(route('operator.landmarks.store'), ['name' => 'Yangi joy', 'lat' => 40.39, 'lng' => 71.78])->assertSuccessful();

    $this->actingAs($this->admin)->get(route('admin.landmarks.index'))->assertInertia(fn ($page) => $page->where('canManage', true));
    $this->delete(route('admin.landmarks.destroy', $landmark))->assertRedirect();
    expect(Landmark::query()->find($landmark->id))->toBeNull();
});

test('chat routes follow the chat.drivers permission and admins keep access', function () {
    $driver = driverNear($this->branch, 0);

    expect($this->admin->can('chat.drivers'))->toBeTrue()->and($this->operator->can('chat.drivers'))->toBeTrue();
    $this->actingAs($this->admin)->getJson(route('operator.chat.threads'))->assertOk();

    $cashier = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Cashier);
    $this->actingAs($cashier)->getJson(route('operator.chat.threads'))->assertForbidden();
    $this->postJson(route('operator.chat.send', $driver), ['body' => 'x'])->assertForbidden();
});

test('operator chat accepts only an order of that driver in the operator branch', function () {
    $driver = driverNear($this->branch, 0);
    $otherDriver = driverNear($this->branch, 100);
    $foreignDriver = driverNear($this->otherBranch, 0);
    $own = Order::factory()->create(['branch_id' => $this->branch->id, 'driver_id' => $driver->id]);
    $notHis = Order::factory()->create(['branch_id' => $this->branch->id, 'driver_id' => $otherDriver->id]);
    $foreign = Order::factory()->create(['branch_id' => $this->otherBranch->id, 'driver_id' => $driver->id]);

    $this->actingAs($this->operator);
    $this->postJson(route('operator.chat.send', $driver), ['body' => 'a', 'order_id' => $notHis->id])->assertJsonValidationErrors('order_id');
    $this->postJson(route('operator.chat.send', $driver), ['body' => 'a', 'order_id' => $foreign->id])->assertJsonValidationErrors('order_id');
    $this->postJson(route('operator.chat.send', $driver), ['body' => 'a', 'order_id' => $own->id])->assertCreated();
    $this->postJson(route('operator.chat.send', $foreignDriver), ['body' => 'a'])->assertNotFound();

    expect(ChatMessage::query()->pluck('order_id')->all())->toBe([$own->id]);
});

test('driver chat accepts only the driver own order', function () {
    $driver = driverNear($this->branch, 0);
    $other = driverNear($this->branch, 100);
    $own = Order::factory()->create(['branch_id' => $this->branch->id, 'driver_id' => $driver->id]);
    $notHis = Order::factory()->create(['branch_id' => $this->branch->id, 'driver_id' => $other->id]);
    Sanctum::actingAs($driver->user, ['driver']);

    $this->postJson('/api/driver/v1/chat', ['body' => 'a', 'order_id' => $notHis->id])->assertJsonValidationErrors('order_id');
    $this->postJson('/api/driver/v1/chat', ['body' => 'a', 'order_id' => $own->id])->assertCreated();
});

test('garbage report and call dates are validation errors, not server errors', function () {
    $this->actingAs($this->admin);

    $this->get(route('admin.reports.index', ['from' => 'not-a-date']))->assertSessionHasErrors('from');
    $this->get(route('admin.reports.export', ['report' => 'daily', 'to' => '2026-13-45x']))->assertSessionHasErrors('to');
    $this->get(route('admin.calls.index', ['from' => 'garbage']))->assertSessionHasErrors('from');
    $this->get(route('admin.calls.index', ['from' => '2026-10-01', 'to' => '2026-10-02']))->assertOk();
});

test('reverb accepts the app host and native clients, not foreign sites', function () {
    $previous = [$_SERVER['APP_URL'] ?? null, $_ENV['APP_URL'] ?? null];
    $_SERVER['APP_URL'] = $_ENV['APP_URL'] = 'https://taxi.example.uz';
    putenv('APP_URL=https://taxi.example.uz');
    $config = require config_path('reverb.php');
    [$_SERVER['APP_URL'], $_ENV['APP_URL']] = $previous;
    putenv('APP_URL'.($previous[0] !== null ? '='.$previous[0] : ''));

    expect($config['apps']['apps'][0]['allowed_origins'])->toBe(['taxi.example.uz', '']);
});
