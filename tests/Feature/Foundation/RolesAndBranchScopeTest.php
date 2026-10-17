<?php

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesSeeder;

class BranchScopedSetting extends Setting
{
    use BelongsToBranch;

    protected $table = 'settings';
}

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    $this->branchA = Branch::factory()->create();
    $this->branchB = Branch::factory()->create();
    BranchScopedSetting::query()->create(['branch_id' => $this->branchA->id, 'key' => 'a', 'value' => 1]);
    BranchScopedSetting::query()->create(['branch_id' => $this->branchB->id, 'key' => 'b', 'value' => 2]);
});

test('every role from the plan exists with its permissions', function () {
    expect(Spatie\Permission\Models\Role::query()->pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'cashier', 'driver', 'operator', 'superadmin']);

    $cashier = User::factory()->create()->assignRole(Role::Cashier);
    expect($cashier->can('balance.topup'))->toBeTrue()
        ->and($cashier->can('orders.manage'))->toBeFalse();
});

test('superadmin passes every permission check', function () {
    $user = User::factory()->create()->assignRole(Role::Superadmin);

    expect($user->can('orders.manage'))->toBeTrue()
        ->and($user->can('anything.at.all'))->toBeTrue();
});

test('branch users only see their own branch data', function () {
    $operator = User::factory()->create(['branch_id' => $this->branchA->id])->assignRole(Role::Operator);
    $this->actingAs($operator);

    expect(BranchScopedSetting::query()->pluck('key')->all())->toBe(['a']);
});

test('users attached to several branches see all of them', function () {
    $operator = User::factory()->create(['branch_id' => $this->branchA->id])->assignRole(Role::Operator);
    $operator->branches()->attach($this->branchB);
    $this->actingAs($operator);

    expect(BranchScopedSetting::query()->orderBy('key')->pluck('key')->all())->toBe(['a', 'b']);
});

test('superadmin sees every branch', function () {
    $this->actingAs(User::factory()->create()->assignRole(Role::Superadmin));

    expect(BranchScopedSetting::query()->count())->toBe(2);
});
