<?php

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Call;
use App\Models\Driver;
use App\Models\PhoneNumber;
use App\Models\Pjsip\PsEndpoint;
use App\Models\SipTrunk;
use App\Models\SoundClip;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RolesSeeder;
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
        ->and(PsEndpoint::query()->find('trunk-sarkor'))->not->toBeNull();

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
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->superadmin)->put(route('admin.telephony.limits.update'), [
        'branches' => [['id' => $this->branch->id, 'max_channels' => 3, 'max_auto_calls' => 1]],
    ])->assertSessionHasNoErrors();

    expect(app(Settings::class)->get('calls.max_wait_seconds'))->toBe(120)
        ->and($this->branch->refresh()->max_channels)->toBe(3);
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

test('asterisk is shown as online even when no PJSIP endpoints exist yet', function () {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake([
        '*/ari/asterisk/info' => Http::response(['system' => []]),
        '*/ari/endpoints/PJSIP' => Http::response(['message' => 'No Endpoints found'], 404),
    ]);

    $this->actingAs($this->superadmin)->get(route('admin.telephony.index'))
        ->assertInertia(fn ($page) => $page->where('asteriskOnline', true));
});
