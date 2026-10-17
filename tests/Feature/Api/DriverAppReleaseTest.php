<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->seed(RolesSeeder::class);
});

test('superadmin publishes a new driver app version and the app sees it', function () {
    $admin = User::factory()->create()->assignRole(Role::Superadmin);

    $this->getJson('/api/driver/v1/app-version')->assertJson(['version_code' => 0, 'url' => null]);

    $this->actingAs($admin)->post(route('admin.driver-app.publish'), [
        'version_code' => 2,
        'version_name' => '1.1.0',
        'min_version_code' => 1,
        'apk' => [
            'arm64-v8a' => UploadedFile::fake()->create('a.apk', 100),
            'armeabi-v7a' => UploadedFile::fake()->create('b.apk', 100),
        ],
    ])->assertSessionHasNoErrors();

    $this->getJson('/api/driver/v1/app-version?abi=armeabi-v7a')
        ->assertJson(['version_code' => 2, 'min_version_code' => 1, 'url' => '/driver-app/armeabi-v7a.apk']);

    $this->get('/driver-app/arm64-v8a.apk')->assertOk()->assertHeader('content-type', 'application/vnd.android.package-archive');
    $this->get('/driver-app')->assertOk();
});

test('version code must grow', function () {
    $admin = User::factory()->create()->assignRole(Role::Superadmin);
    $publish = fn (int $code) => $this->actingAs($admin)->post(route('admin.driver-app.publish'), [
        'version_code' => $code, 'version_name' => "v{$code}", 'min_version_code' => 0,
        'apk' => ['arm64-v8a' => UploadedFile::fake()->create('a.apk', 10)],
    ]);

    $publish(5)->assertSessionHasNoErrors();
    $publish(5)->assertSessionHasErrors('version_code');
});

test('unknown apk names are not served', function () {
    $this->get('/driver-app/x86.apk')->assertNotFound();
});
