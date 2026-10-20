<?php

use App\Enums\Role;
use App\Enums\TransactionType;
use App\Events\OfferWithdrawn;
use App\Events\OrderOffered;
use App\Events\OrderUpdated;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Services\Dispatch\Dispatcher;
use App\Services\Orders\OrderService;
use App\Support\Settings;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Queue::fake();
    Event::fake([OrderOffered::class, OfferWithdrawn::class, OrderUpdated::class]);
    Http::fake();
    $this->branch = Branch::factory()->create();
    $this->orders = app(OrderService::class);
    $this->dispatcher = app(Dispatcher::class);
});

function rideFor(object $test, array $overrides = [], int $meters = 3000): Order
{
    $driver = driverNear($test->branch, 500, ['balance' => 50000]);
    $order = $test->orders->create(orderData($test->branch, $overrides));
    $test->dispatcher->accept($order, $driver);
    $test->orders->start($order->fresh(), $driver->fresh());

    return $test->orders->complete($order->fresh(), $driver->fresh(), $meters);
}

test('peak-hour coefficient is fixed on the order when created inside the window', function () {
    $settings = app(Settings::class);
    $settings->set('pricing.surge_coeff', 1.5, $this->branch);
    $hour = now($this->branch->timezone)->hour;
    $settings->set('pricing.surge_from_hour', $hour, $this->branch);
    $settings->set('pricing.surge_to_hour', ($hour + 1) % 24, $this->branch);

    $order = rideFor($this);

    // 10 000 × 1,5 = 15 000
    expect($order->tariff_snapshot['surge_coeff'])->toEqual(1.5)
        ->and($order->price_final)->toBe(15000)
        ->and($order->price_breakdown['surge'])->toBe(5000);
});

test('peak-hour coefficient is not applied outside the window', function () {
    $settings = app(Settings::class);
    $settings->set('pricing.surge_coeff', 2, $this->branch);
    $hour = now($this->branch->timezone)->hour;
    $settings->set('pricing.surge_from_hour', ($hour + 2) % 24, $this->branch);
    $settings->set('pricing.surge_to_hour', ($hour + 3) % 24, $this->branch);

    expect(rideFor($this)->price_final)->toBe(10000);
});

test('regular client discount lowers the price', function () {
    Client::query()->create(['phone' => '+998901234567', 'discount_percent' => 10]);

    $order = rideFor($this);

    expect($order->price_final)->toBe(9000)
        ->and($order->price_breakdown['discount'])->toBe(-1000);
});

test('corporate trip uses the company discount and pays the driver through the balance', function () {
    $company = Company::query()->create(['branch_id' => $this->branch->id, 'name' => 'Artel', 'discount_percent' => 20]);
    Client::query()->create(['phone' => '+998901234567', 'company_id' => $company->id]);

    $order = rideFor($this);
    $driver = $order->driver()->withoutGlobalScopes()->first();

    // 10 000 − 20% = 8 000; komissiya 800; haydovchiga +8 000
    expect($order->company_id)->toBe($company->id)
        ->and($order->price_final)->toBe(8000)
        ->and($driver->transactions()->where('type', TransactionType::Corporate)->value('amount'))->toBe(8000)
        ->and($driver->balance)->toBe(50000 - 800 + 8000);
});

test('low-rated drivers skip the nearest radius but get the order on the next step', function () {
    $good = driverNear($this->branch, 500, ['rating' => 90]);
    $low = driverNear($this->branch, 600, ['rating' => 40]);

    $order = $this->orders->create(orderData($this->branch));
    expect($order->offers()->pluck('driver_id')->all())->toBe([$good->id]);

    $this->dispatcher->expand($order->fresh(), 0, $order->fresh()->search_round);
    expect($order->offers()->pluck('driver_id')->sort()->values()->all())->toBe(collect([$good->id, $low->id])->sort()->values()->all());
});

test('admin downloads a monthly invoice for a company', function () {
    $this->seed(RolesSeeder::class);
    $admin = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Admin);
    $company = Company::query()->create(['branch_id' => $this->branch->id, 'name' => 'Artel']);
    Client::query()->create(['phone' => '+998901234567', 'company_id' => $company->id]);
    $order = rideFor($this);

    $csv = $this->actingAs($admin)->get(route('admin.companies.invoice', [$company, 'month' => now()->format('Y-m')]))
        ->assertOk()->streamedContent();

    expect($csv)->toContain((string) $order->id)->and($csv)->toContain('10000');
});

test('operators cannot set client discounts', function () {
    $this->seed(RolesSeeder::class);
    $operator = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Operator);
    $client = Client::query()->create(['phone' => '+998901111111']);

    $this->actingAs($operator)->put(route('admin.clients.update', $client), ['note' => 'VIP', 'discount_percent' => 50])->assertRedirect();

    expect($client->fresh()->note)->toBe('VIP')->and($client->fresh()->discount_percent)->toBeNull();
});
