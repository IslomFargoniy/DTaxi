<?php

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Call;
use App\Models\Driver;
use App\Models\PhoneNumber;
use App\Models\Pjsip\PsAor;
use App\Models\Pjsip\PsAuth;
use App\Models\Pjsip\PsEndpoint;
use App\Models\Pjsip\PsRegistration;
use App\Models\SipTrunk;
use App\Models\SoundClip;
use App\Models\User;
use App\Services\Telephony\Extensions;
use App\Services\Telephony\IceServers;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Http::fake(['*/ari/endpoints/PJSIP' => Http::response([['resource' => 'trunk-sarkor', 'state' => 'online']])]);
    $this->seed(RolesSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->superadmin = User::factory()->create()->assignRole(Role::Superadmin);
    $this->admin = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Admin);
});

test('superadmin adds a SIP trunk and it is written to Asterisk realtime', function () {
    $this->actingAs($this->superadmin)->post(route('admin.telephony.trunks.store'), [
        'name' => 'sarkor', 'title' => 'Sarkor', 'host' => '10.0.0.5', 'port' => 5060,
        'username' => '712000000', 'password' => 'secret', 'max_channels' => 4, 'is_active' => true,
    ])->assertRedirect();

    $trunk = SipTrunk::query()->sole();
    expect($trunk->password)->toBe('secret')
        ->and(PsEndpoint::query()->find('trunk-sarkor'))->not->toBeNull()
        // Username bo'yicha tanilmasin — /ws orqali "From: trunk-sarkor" soxtalashtirib bo'lmaydi
        ->and(PsEndpoint::query()->find('trunk-sarkor')?->identify_by)->toBe('ip');

    $this->actingAs($this->superadmin)->get(route('admin.telephony.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/telephony')->where('trunks.0.state', 'online')->where('trunks.0.has_password', true));
});

test('superadmin links a number to branches with IVR digits', function () {
    $trunk = SipTrunk::query()->create(['name' => 'sarkor', 'title' => 'Sarkor', 'host' => '10.0.0.5']);
    $other = Branch::factory()->create();

    $this->actingAs($this->superadmin)->post(route('admin.telephony.numbers.store'), [
        'sip_trunk_id' => $trunk->id, 'number' => '+998712000000', 'routing_mode' => 'ivr', 'is_active' => true,
        'branches' => [['id' => $this->branch->id, 'is_primary' => true, 'ivr_digit' => 1], ['id' => $other->id, 'ivr_digit' => 2]],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $number = PhoneNumber::query()->sole();
    expect($number->branches()->orderByPivot('ivr_digit')->pluck('branches.id')->all())->toBe([$this->branch->id, $other->id]);
});

test('superadmin changes call center settings and branch limits', function () {
    $this->actingAs($this->superadmin)->put(route('admin.telephony.settings.update'), [
        'calls_max_wait_seconds' => 120, 'calls_operator_ring_seconds' => 15, 'calls_pause_on_missed_ring' => false, 'calls_ivr_auto_order' => true,
        'calls_auto_calls_enabled' => true, 'calls_auto_call_attempts' => 2, 'calls_auto_call_retry_seconds' => 60,
        'calls_still_waiting_minutes' => 0, 'calls_recordings_retention_days' => 30,
        'calls_rating_call_enabled' => true, 'calls_rating_call_delay_minutes' => 5,
        'calls_max_recording_seconds' => 3600, 'calls_driver_gain' => 2,
        'calls_operator_outbound_limit' => 10, 'calls_operator_outbound_window_minutes' => 5, 'calls_login_code_calls_per_hour' => 50,
        'calls_outbound_allowed_patterns' => ['^998\\d{9}$', ' ^7\\d{10}$ '],
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->superadmin)->put(route('admin.telephony.limits.update'), [
        'branches' => [['id' => $this->branch->id, 'max_channels' => 3, 'max_auto_calls' => 1]],
    ])->assertSessionHasNoErrors();

    expect(app(Settings::class)->get('calls.max_wait_seconds'))->toBe(120)
        ->and(app(Settings::class)->get('calls.driver_gain'))->toBe(2)
        ->and(app(Settings::class)->get('calls.outbound_allowed_patterns'))->toBe(['^998\\d{9}$', '^7\\d{10}$'])
        ->and($this->branch->refresh()->max_channels)->toBe(3);
});

test('broken outbound number pattern is rejected', function () {
    $valid = collect([
        'calls.max_wait_seconds', 'calls.operator_ring_seconds', 'calls.pause_on_missed_ring', 'calls.ivr_auto_order',
        'calls.auto_calls_enabled', 'calls.auto_call_attempts', 'calls.auto_call_retry_seconds', 'calls.still_waiting_minutes',
        'calls.recordings_retention_days', 'calls.rating_call_enabled', 'calls.rating_call_delay_minutes',
        'calls.max_recording_seconds', 'calls.driver_gain', 'calls.operator_outbound_limit', 'calls.operator_outbound_window_minutes',
    ])->mapWithKeys(fn (string $k) => [str_replace('.', '_', $k) => app(Settings::class)->get($k)])->all();

    $this->actingAs($this->superadmin)->put(route('admin.telephony.settings.update'), [
        ...$valid, 'calls_outbound_allowed_patterns' => ['^998(\\d{9}$'],
    ])->assertSessionHasErrors('calls_outbound_allowed_patterns.0');
});

test('branch admin cannot manage telephony but sees calls of own branch', function () {
    $other = Branch::factory()->create();
    Call::query()->create(['branch_id' => $this->branch->id, 'direction' => 'inbound', 'kind' => 'client', 'caller' => '+998901111111', 'status' => 'completed', 'started_at' => now()]);
    Call::query()->create(['branch_id' => $other->id, 'direction' => 'inbound', 'kind' => 'client', 'caller' => '+998902222222', 'status' => 'missed', 'started_at' => now()]);

    $this->actingAs($this->admin)->get(route('admin.telephony.index'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.calls.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/calls')
            ->has('calls.data', 1)
            ->where('calls.data.0.caller', '+998901111111')
            ->where('stats.answered', 1));
});

test('recordings are served only through the app', function () {
    Storage::fake();
    Storage::put('recordings/1/2026/10/17/1.mp3', 'mp3');
    $call = Call::query()->create(['branch_id' => $this->branch->id, 'direction' => 'inbound', 'kind' => 'client', 'status' => 'completed', 'started_at' => now(), 'recording_path' => 'recordings/1/2026/10/17/1.mp3']);

    $this->actingAs($this->admin)->get(route('admin.calls.recording', $call))->assertOk();
    $operator = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Operator);
    $this->actingAs($operator)->get(route('admin.calls.recording', $call))->assertForbidden();
});

test('voice clips can be listened to as wav', function () {
    Storage::fake();
    Storage::put('sounds/uz/phrase/thanks.sln', str_repeat("\0", 1600));
    $clip = SoundClip::query()->create(['category' => 'phrase', 'key' => 'thanks', 'text' => "Rahmat, oq yo'l!", 'file_path' => 'phrase/thanks']);

    $response = $this->actingAs($this->superadmin)->get(route('admin.sounds.play', $clip))->assertOk();
    expect(substr((string) $response->getContent(), 0, 4))->toBe('RIFF');

    $this->actingAs($this->superadmin)->get(route('admin.sounds.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/sounds')->has('clips'));
});

test('operator gets softphone credentials', function () {
    $operator = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Operator);

    $this->actingAs($operator)->getJson(route('operator.phone.show'))->assertOk()
        ->assertJsonPath('sip.extension', 'op'.$operator->id)
        ->assertJsonPath('status', 'offline');
});

test('driver app gets WebRTC credentials to call the dispatcher', function () {
    $driver = Driver::factory()->create(['branch_id' => $this->branch->id]);
    Sanctum::actingAs($driver->user, ['driver']);

    $this->getJson('/api/driver/v1/phone')->assertOk()
        ->assertJsonPath('sip.extension', 'dr'.$driver->id)
        ->assertJsonPath('target', 'dispatcher');
    $this->get(route('driver-app.call'))->assertOk()->assertSee('Dispetcher');
});

test('without TURN configured only the STUN server is given to WebRTC clients', function () {
    config(['services.webrtc.stun' => 'stun:stun.example.org:19302', 'services.webrtc.turn_url' => null, 'services.webrtc.turn_secret' => null]);
    $operator = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Operator);

    $this->actingAs($operator)->getJson(route('operator.phone.show'))->assertOk()
        ->assertJsonPath('sip.ice_servers', [['urls' => ['stun:stun.example.org:19302']]])
        ->assertJsonPath('sip.ice_expires_at', null);
});

test('with TURN configured operator and driver get time-limited TURN credentials', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC'));
    config([
        'services.webrtc.stun' => 'stun:stun.example.org:19302',
        'services.webrtc.turn_url' => 'turn:taxi.example.uz:3478?transport=udp, turns:taxi.example.uz:5349?transport=tcp',
        'services.webrtc.turn_secret' => 'coturn-secret',
        'services.webrtc.turn_ttl' => 43200,
    ]);
    $expires = now()->getTimestamp() + 43200;
    $operator = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Operator);

    $sip = $this->actingAs($operator)->getJson(route('operator.phone.show'))->assertOk()->json('sip');

    expect($sip['ice_expires_at'])->toBe($expires)
        ->and($sip['ice_servers'][0])->toBe(['urls' => ['stun:stun.example.org:19302']])
        ->and($sip['ice_servers'][1]['urls'])->toBe(['turn:taxi.example.uz:3478?transport=udp', 'turns:taxi.example.uz:5349?transport=tcp'])
        ->and($sip['ice_servers'][1]['username'])->toBe($expires.':'.$operator->id)
        // coturn use-auth-secret: base64(hmac-sha1(secret, username))
        ->and($sip['ice_servers'][1]['credential'])
        ->toBe(base64_encode(hash_hmac('sha1', $expires.':'.$operator->id, 'coturn-secret', true)));

    $driver = Driver::factory()->create(['branch_id' => $this->branch->id]);
    Sanctum::actingAs($driver->user, ['driver']);
    $this->getJson('/api/driver/v1/phone')->assertOk()
        ->assertJsonPath('sip.ice_servers.1.username', $expires.':'.$driver->user_id)
        ->assertJsonPath('sip.ice_expires_at', $expires);
});

test('TURN credential matches the coturn REST API reference value', function () {
    // RFC 2104 / coturn: echo -n "1700000000:5" | openssl dgst -sha1 -hmac secret -binary | base64
    expect(IceServers::credential('secret', '1700000000:5'))
        ->toBe(base64_encode(hash_hmac('sha1', '1700000000:5', 'secret', true)))
        ->toHaveLength(28);
});

test('asterisk is shown as online even when no PJSIP endpoints exist yet', function () {
    Http::swap(new Factory);
    Http::fake([
        '*/ari/asterisk/info' => Http::response(['system' => []]),
        '*/ari/endpoints/PJSIP' => Http::response(['message' => 'No Endpoints found'], 404),
    ]);

    $this->actingAs($this->superadmin)->get(route('admin.telephony.index'))
        ->assertInertia(fn ($page) => $page->where('asteriskOnline', true));
});

test('deleting a user removes its extension from Asterisk and sync prunes leftovers', function () {
    $operator = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Operator);
    app(Extensions::class)->syncFor($operator);
    $extension = $operator->fresh()->sip_extension;
    PsEndpoint::query()->create(['id' => 'op999999', 'transport' => 'transport-ws']);

    $operator->delete();
    $this->artisan('telephony:sync')->assertSuccessful();

    expect(PsEndpoint::query()->whereKey($extension)->exists())->toBeFalse()
        ->and(PsEndpoint::query()->whereKey('op999999')->exists())->toBeFalse();
});

test('extension auth is stored as md5 digest without the plain password', function () {
    $operator = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Operator);
    app(Extensions::class)->syncFor($operator);
    $operator->refresh();

    $auth = PsAuth::query()->findOrFail('op'.$operator->id);
    $password = (string) $operator->sip_password;
    expect($auth->auth_type)->toBe('md5')
        ->and($auth->password)->toBeNull()
        ->and($auth->realm)->toBe(Extensions::REALM)
        ->and($auth->md5_cred)->toBe(md5("op{$operator->id}:".Extensions::REALM.":{$password}"))
        ->and(DB::table('ps_auths')->where('password', $password)->orWhere('md5_cred', $password)->exists())->toBeFalse()
        // Brauzer/ilova ochiq parolni baribir oladi (JsSIP digest'ni o'zi hisoblaydi)
        ->and(app(Extensions::class)->credentials($operator, 'taxi.test')['password'] ?? null)->toBe($password);
});

test('telephony:sync converts legacy plaintext extension auth rows to md5', function () {
    $operator = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Operator);
    app(Extensions::class)->syncFor($operator);
    $operator->refresh();
    $id = 'op'.$operator->id;
    // Eski versiya yozgan qator
    PsAuth::query()->whereKey($id)->update(['auth_type' => 'userpass', 'password' => $operator->sip_password, 'md5_cred' => null, 'realm' => null]);

    $this->artisan('telephony:sync')->assertSuccessful();

    $auth = PsAuth::query()->findOrFail($id);
    expect($auth->auth_type)->toBe('md5')
        ->and($auth->password)->toBeNull()
        ->and($auth->md5_cred)->toBe(md5("{$id}:".Extensions::REALM.':'.$operator->sip_password));
});

test('renaming a trunk removes the Asterisk rows of the old name', function () {
    $trunk = SipTrunk::query()->create(['name' => 'sarkor', 'title' => 'Sarkor', 'host' => '10.0.0.5', 'username' => '712000000', 'password' => 'secret', 'is_active' => true]);
    expect(PsRegistration::query()->find('reg-trunk-sarkor'))->not->toBeNull();

    $trunk->update(['name' => 'uztelecom']);

    expect(PsEndpoint::query()->find('trunk-sarkor'))->toBeNull()
        ->and(PsAor::query()->find('aor-trunk-sarkor'))->toBeNull()
        ->and(PsAuth::query()->find('auth-trunk-sarkor'))->toBeNull()
        ->and(PsRegistration::query()->find('reg-trunk-sarkor'))->toBeNull()
        ->and(PsEndpoint::query()->find('trunk-uztelecom'))->not->toBeNull()
        ->and(PsRegistration::query()->find('reg-trunk-uztelecom'))->not->toBeNull();
});

test('sync prunes trunk rows left without an active trunk', function () {
    SipTrunk::query()->create(['name' => 'sarkor', 'title' => 'Sarkor', 'host' => '10.0.0.5', 'is_active' => true]);
    PsEndpoint::query()->create(['id' => 'trunk-old', 'aors' => 'aor-trunk-old']);
    PsAor::query()->create(['id' => 'aor-trunk-old']);
    PsAor::query()->create(['id' => 'aor-trunk-gone']);

    expect(app(Extensions::class)->pruneOrphans())->toBe(2)
        ->and(PsEndpoint::query()->find('trunk-old'))->toBeNull()
        ->and(PsAor::query()->whereIn('id', ['aor-trunk-old', 'aor-trunk-gone'])->exists())->toBeFalse()
        ->and(PsEndpoint::query()->find('trunk-sarkor'))->not->toBeNull();
});

test('caller ID names cannot break out of the quoted name', function () {
    expect(Extensions::callerName("Ali \"x\" <999>\\\n"))->toBe('Ali x 999');

    $operator = User::factory()->create(['name' => 'Eve" <1000>', 'branch_id' => $this->branch->id])->assignRole(Role::Operator);
    app(Extensions::class)->syncFor($operator);

    expect(PsEndpoint::query()->find('op'.$operator->id)->callerid)->toBe('"Eve 1000" <op'.$operator->id.'>');
});
