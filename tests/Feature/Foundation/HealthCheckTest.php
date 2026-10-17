<?php

use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Models\Integration;
use App\Models\SipTrunk;
use App\Models\User;
use App\Support\HealthCheck;
use Database\Seeders\RolesSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

test('detects a disconnected trunk and stopped call service, alerts Telegram only on change', function () {
    $this->seed(RolesSeeder::class);
    $trunk = SipTrunk::query()->create(['name' => 'sarkor', 'title' => 'Sarkor', 'host' => '10.0.0.5']);
    Integration::for(IntegrationProvider::Telegram)->fill(['is_enabled' => true, 'credentials' => ['bot_token' => 'T', 'chat_id' => '42']])->save();
    Http::fake([
        '*/ari/endpoints/PJSIP' => Http::response([['resource' => 'trunk-sarkor', 'state' => 'offline']]),
        'api.telegram.org/*' => Http::response(['ok' => true]),
        '*' => Http::response([]),
    ]);

    $problems = app(HealthCheck::class)->run();
    expect($problems)->toHaveKeys(["trunk.{$trunk->id}", 'ari']);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.telegram.org') && str_contains((string) $r['text'], 'Sarkor'));

    // Holat o'zgarmasa — qayta yuborilmaydi
    app(HealthCheck::class)->run();
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'api.telegram.org')))->toHaveCount(1);

    // Superadmin panelda ko'radi
    $admin = User::factory()->create()->assignRole(Role::Superadmin);
    $this->actingAs($admin)->get(route('admin.telephony.index'))
        ->assertInertia(fn ($page) => $page->where('health', fn ($health) => collect($health)->contains('SIP trunk is not connected: Sarkor')));
});

test('healthy system has no problems', function () {
    Cache::put('ari:listen:heartbeat', time(), 30);
    Http::fake(['*' => Http::response([])]);

    expect(app(HealthCheck::class)->run())->not->toHaveKeys(['asterisk', 'ari', 'osrm']);
});
