<?php

use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesSeeder;

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    $this->superadmin = User::factory()->create()->assignRole(Role::Superadmin);
});

test('panel changes are written to the audit log without secrets', function () {
    $this->actingAs($this->superadmin)->put(route('admin.integrations.update', IntegrationProvider::Payme->value), [
        'is_enabled' => true, 'credentials' => ['merchant_id' => 'm-1', 'key' => 'super-secret', 'test_key' => ''],
    ]);
    $this->actingAs($this->superadmin)->put(route('admin.integrations.update', IntegrationProvider::Payme->value), [
        'is_enabled' => false, 'credentials' => ['merchant_id' => 'm-2', 'key' => '', 'test_key' => ''],
    ]);

    $log = AuditLog::query()->where('subject_type', 'Integration')->where('action', 'updated')->latest('id')->firstOrFail();
    expect($log->user_id)->toBe($this->superadmin->id)
        ->and($log->changes['credentials'])->toBe('••• (changed)')
        ->and($log->changes['is_enabled'])->toEqual(['from' => true, 'to' => false])
        ->and(json_encode(AuditLog::query()->get()))->not->toContain('super-secret');

    $this->actingAs($this->superadmin)->get(route('admin.audit.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/audit')->has('logs.data', 2));
});

test('only superadmin sees the audit log', function () {
    $admin = User::factory()->create()->assignRole(Role::Admin);

    $this->actingAs($admin)->get(route('admin.audit.index'))->assertForbidden();
});
