<?php

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\TransactionSource;
use App\Models\Branch;
use App\Models\Call;
use App\Models\Driver;
use App\Models\Order;
use App\Models\SipTrunk;
use App\Models\User;
use App\Services\Drivers\DriverBalance;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
    $this->seed(RolesSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->admin = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Admin);
});

test('report shows orders, revenue, drivers, top-ups and channel peaks for own branch only', function () {
    $driver = Driver::factory()->create(['branch_id' => $this->branch->id]);
    Order::factory()->count(2)->create(['branch_id' => $this->branch->id, 'driver_id' => $driver->id, 'status' => OrderStatus::Completed, 'price_final' => 15000, 'commission' => 1500]);
    Order::factory()->create(['branch_id' => $this->branch->id, 'status' => OrderStatus::CancelledByClient, 'cancel_reason' => 'Mijoz kutmadi']);
    Order::factory()->create(['branch_id' => Branch::factory()->create()->id, 'status' => OrderStatus::Completed, 'price_final' => 99999]);
    app(DriverBalance::class)->topUp($driver, 50000, TransactionSource::Click);

    $trunk = SipTrunk::query()->create(['name' => 't', 'title' => 'T', 'host' => '10.0.0.5', 'max_channels' => 2]);
    foreach ([[0, 10], [2, 8], [3, 6]] as [$start, $end]) {
        Call::query()->create(['branch_id' => $this->branch->id, 'sip_trunk_id' => $trunk->id, 'direction' => 'inbound', 'kind' => 'client',
            'status' => 'completed', 'started_at' => now()->subMinutes(30 - $start), 'ended_at' => now()->subMinutes(30 - $end)]);
    }
    Call::query()->create(['branch_id' => $this->branch->id, 'sip_trunk_id' => $trunk->id, 'direction' => 'inbound', 'kind' => 'client',
        'status' => 'rejected', 'meta' => ['reason' => 'channels'], 'started_at' => now()->subMinutes(26), 'ended_at' => now()->subMinutes(26)]);

    $this->actingAs($this->admin)->get(route('admin.reports.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/reports')
            ->where('daily.0.orders', 3)
            ->where('daily.0.revenue', 30000)
            ->where('daily.0.commission', 3000)
            ->where('cancellations.0.reason', 'Mijoz kutmadi')
            ->where('drivers.0.trips', 2)
            ->where('topups.0.source', 'click')
            ->where('topups.0.total', 50000)
            ->where('channels.days.0.peak', 3)
            ->where('channels.days.0.lost', 1)
            ->where('channels.recommendation', 'lost'));
});

test('reports export to CSV', function () {
    Order::factory()->create(['branch_id' => $this->branch->id, 'status' => OrderStatus::Completed, 'price_final' => 12000]);

    $response = $this->actingAs($this->admin)->get(route('admin.reports.export', 'daily'))->assertOk();
    expect($response->streamedContent())->toContain('orders;completed')->toContain('12000');
});

test('operators cannot open reports', function () {
    $operator = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Operator);

    $this->actingAs($operator)->get(route('admin.reports.index'))->assertForbidden();
});
