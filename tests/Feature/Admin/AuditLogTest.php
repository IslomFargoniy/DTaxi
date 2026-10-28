<?php

use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Region;
use App\Models\User;
use App\Services\Regions\OsmRegionImporter;
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

test('branch area changes are logged without polygon coordinates', function () {
    app(OsmRegionImporter::class)->import(base_path('tests/fixtures/boundaries.geojsonseq'));
    $district = Region::query()->where('name_uz', 'Rishton tumani')->firstOrFail();
    $branch = Branch::factory()->create(['center' => null, 'service_area' => null]);

    $this->actingAs($this->superadmin)->put(route('admin.branches.update', $branch), [
        'name' => $branch->name, 'region_id' => $district->id, 'timezone' => 'Asia/Tashkent',
        'center_lat' => 40.3864, 'center_lng' => 71.7864,
    ])->assertSessionHasNoErrors();

    $log = AuditLog::query()->where('subject_type', 'Branch')->where('action', 'updated')->latest('id')->firstOrFail();
    expect($log->changes['service_area'])->toBe('Map area changed')
        ->and($log->changes['center'])->toEqual(['from' => null, 'to' => '40.3864, 71.7864'])
        ->and(json_encode($log->changes))->not->toContain('coordinates');
});

test('only superadmin sees the audit log', function () {
    $admin = User::factory()->create()->assignRole(Role::Admin);

    $this->actingAs($admin)->get(route('admin.audit.index'))->assertForbidden();
});
