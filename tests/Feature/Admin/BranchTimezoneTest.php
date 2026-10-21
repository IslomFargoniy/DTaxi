<?php

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
    $this->seed(RolesSeeder::class);
    $this->branch = Branch::factory()->create(['timezone' => 'Asia/Tashkent']);
    $this->admin = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Admin);
});

test('company invoice picks the requested month even on the 31st', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-31 12:00', 'Asia/Tashkent'));
    $company = Company::query()->create(['branch_id' => $this->branch->id, 'name' => 'Artel']);
    $february = Order::factory()->create(['branch_id' => $this->branch->id, 'company_id' => $company->id, 'status' => OrderStatus::Completed,
        'price_final' => 11000, 'completed_at' => CarbonImmutable::parse('2026-02-10 12:00', 'Asia/Tashkent')->utc()]);
    $march = Order::factory()->create(['branch_id' => $this->branch->id, 'company_id' => $company->id, 'status' => OrderStatus::Completed,
        'price_final' => 22000, 'completed_at' => CarbonImmutable::parse('2026-03-02 12:00', 'Asia/Tashkent')->utc()]);

    $csv = $this->actingAs($this->admin)->get(route('admin.companies.invoice', [$company, 'month' => '2026-02']))
        ->assertOk()->streamedContent();

    expect($csv)->toContain('11000')->and($csv)->not->toContain('22000');
});

test('dashboard "today" and the hourly chart follow the branch timezone, not UTC', function () {
    // Toshkentda 21-oktabr 02:00 = UTC bo'yicha hali 20-oktabr
    $this->travelTo(CarbonImmutable::parse('2026-10-21 02:00', 'Asia/Tashkent'));
    Order::factory()->create(['branch_id' => $this->branch->id, 'created_at' => CarbonImmutable::parse('2026-10-21 01:10', 'Asia/Tashkent')->utc()]);
    Order::factory()->create(['branch_id' => $this->branch->id, 'created_at' => CarbonImmutable::parse('2026-10-20 23:30', 'Asia/Tashkent')->utc()]);

    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.orders_today', 1)
            ->where('stats.by_hour', [1 => 1]));
});

test('order history date filter uses the branch day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00', 'Asia/Tashkent'));
    $early = Order::factory()->create(['branch_id' => $this->branch->id, 'created_at' => CarbonImmutable::parse('2026-10-21 01:10', 'Asia/Tashkent')->utc()]);
    $yesterday = Order::factory()->create(['branch_id' => $this->branch->id, 'created_at' => CarbonImmutable::parse('2026-10-20 23:30', 'Asia/Tashkent')->utc()]);

    $this->actingAs($this->admin)->get(route('admin.orders.index', ['from' => '2026-10-21', 'to' => '2026-10-21']))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('orders.data', 1)
            ->where('orders.data.0.id', $early->id));
});
