<?php

use App\Console\Commands\AriListen;
use App\Enums\DriverStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Events\CallUpdated;
use App\Events\IncomingCall;
use App\Events\IncomingCallEnded;
use App\Events\OperatorCallEvent;
use App\Events\OrderUpdated;
use App\Jobs\PlaceAutoCall;
use App\Jobs\PlaceCodeCall;
use App\Jobs\ProcessRecording;
use App\Jobs\SendPushNotification;
use App\Models\Branch;
use App\Models\Call;
use App\Models\Client;
use App\Models\DeviceToken;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\PhoneNumber;
use App\Models\Pjsip\PsAuth;
use App\Models\Pjsip\PsEndpoint;
use App\Models\Setting;
use App\Models\SipTrunk;
use App\Models\SoundClip;
use App\Models\User;
use App\Services\Orders\OrderEvents;
use App\Services\Routing\Router;
use App\Services\Sounds\MessageComposer;
use App\Services\Telephony\AriClient;
use App\Services\Telephony\CallCenter;
use App\Services\Telephony\ChannelLimits;
use App\Services\Telephony\ClientNotifier;
use App\Services\Telephony\Extensions;
use App\Services\Telephony\OperatorPool;
use App\Support\Settings;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../Orders/helpers.php';

/**
 * ARI soxtalashtiriladi: qaysi operatorlar brauzerda onlayn — $this->online.
 * $this->ari: 'down' — shu bo'lak bor so'rovlarda ulanish xatosi; 'channels'/'bridges' — GET ro'yxatlari (null — 500).
 */
function fakeAri(array &$online, array &$ari): void
{
    Http::fake(function (Request $request) use (&$online, &$ari) {
        $url = urldecode($request->url());
        foreach ($ari['down'] as $needle) {
            if (str_contains($url, $needle)) {
                throw new ConnectionException('cURL error 7: Failed to connect');
            }
        }
        $get = $request->method() === 'GET';

        return match (true) {
            str_ends_with($url, '/ari/endpoints/PJSIP') => Http::response(array_map(fn ($e) => ['resource' => $e, 'state' => 'online'], $online)),
            $get && str_ends_with($url, '/ari/channels') => $ari['channels'] === null ? Http::response(null, 500) : Http::response(array_map(fn ($c) => is_array($c) ? $c : ['id' => $c], $ari['channels'])),
            $get && str_ends_with($url, '/ari/bridges') => $ari['bridges'] === null ? Http::response(null, 500) : Http::response($ari['bridges']),
            str_ends_with($url, '/ari/bridges') => Http::response(['id' => 'bridge-1']),
            default => Http::response(null, 204),
        };
    });
}

function stasis(string $channelId, array $args, string $name = 'PJSIP/trunk-sarkor-00000001', string $caller = '998901234567'): array
{
    return ['type' => 'StasisStart', 'args' => $args, 'channel' => ['id' => $channelId, 'name' => $name, 'caller' => ['number' => $caller]]];
}

function ariRequests(string $needle): array
{
    return Http::recorded(fn (Request $r) => str_contains(urldecode($r->url()), $needle))->all();
}

beforeEach(function () {
    Queue::fake([ProcessRecording::class, PlaceAutoCall::class, SendPushNotification::class]);
    Event::fake([OperatorCallEvent::class, CallUpdated::class, OrderUpdated::class, IncomingCall::class, IncomingCallEnded::class]);
    $this->seed(RolesSeeder::class);

    $this->online = [];
    $this->ari = ['down' => [], 'channels' => [], 'bridges' => []];
    fakeAri($this->online, $this->ari);

    $this->branch = Branch::factory()->create();
    $this->trunk = SipTrunk::query()->create(['name' => 'sarkor', 'title' => 'Sarkor', 'host' => 'sip.example.uz', 'max_channels' => 4]);
    $this->number = PhoneNumber::query()->create(['sip_trunk_id' => $this->trunk->id, 'number' => '+998712000000']);
    $this->number->branches()->attach($this->branch->id, ['is_primary' => true]);

    $this->operator = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Operator);
    $this->operator->forceFill(['sip_extension' => 'op'.$this->operator->id, 'operator_status' => 'ready'])->save();
    $this->online[] = $this->operator->sip_extension;

    $this->center = app(CallCenter::class);
});

test('inbound call waits in the queue and rings a ready operator', function () {
    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));

    $call = Call::query()->withoutGlobalScopes()->sole();
    expect($call->status)->toBe('waiting')
        ->and($call->branch_id)->toBe($this->branch->id)
        ->and($call->caller)->toBe('+998901234567')
        ->and($call->operator_id)->toBe($this->operator->id);

    expect(ariRequests('/ari/channels/ch-1/answer'))->toHaveCount(1)
        ->and(ariRequests('endpoint=PJSIP/'.$this->operator->sip_extension))->toHaveCount(1);
    Event::assertDispatched(OperatorCallEvent::class, fn ($e) => $e->userId === $this->operator->id && $e->type === 'incoming');
});

test('operator answers: channels are bridged and recorded, hangup completes the call', function () {
    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $call = Call::query()->withoutGlobalScopes()->sole();
    $operatorChannel = $call->meta['operator_channel'];

    $this->center->handle(stasis($operatorChannel, ['operator', (string) $call->id], 'PJSIP/op1-0001'));
    $call->refresh();

    expect($call->status)->toBe('answered')
        ->and($call->meta['bridge'])->toBe('bridge-1')
        ->and(ariRequests('/ari/bridges/bridge-1/record'))->toHaveCount(1);

    $this->center->handle(['type' => 'ChannelDestroyed', 'channel' => ['id' => 'ch-1'], 'cause' => 16]);

    expect($call->refresh()->status)->toBe('completed')
        ->and($call->ended_at)->not->toBeNull()
        ->and(ariRequests("/ari/channels/{$operatorChannel}"))->not->toBeEmpty();
    Queue::assertPushed(ProcessRecording::class, fn ($job) => $job->callId === $call->id);
});

test('operator who misses the ring is paused and the call goes to the next operator', function () {
    $second = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Operator);
    $second->forceFill(['sip_extension' => 'op'.$second->id, 'operator_status' => 'ready', 'operator_status_at' => now()])->save();
    $this->online[] = $second->sip_extension;
    $this->operator->forceFill(['operator_status_at' => now()->subHour()])->save();

    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $call = Call::query()->withoutGlobalScopes()->sole();
    expect($call->operator_id)->toBe($this->operator->id);

    $this->center->handle(['type' => 'ChannelDestroyed', 'channel' => ['id' => $call->meta['operator_channel']], 'cause' => 19]);

    expect($call->refresh()->operator_id)->toBe($second->id)
        ->and($call->status)->toBe('waiting')
        ->and($this->operator->refresh()->operator_status)->toBe('paused');
});

test('caller who hangs up while waiting lands in the missed list', function () {
    $this->operator->forceFill(['operator_status' => 'paused'])->save();

    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $this->center->handle(['type' => 'ChannelDestroyed', 'channel' => ['id' => 'ch-1'], 'cause' => 16]);

    expect(Call::query()->withoutGlobalScopes()->sole()->status)->toBe('missed');

    $this->actingAs($this->operator)->getJson(route('operator.phone.missed'))
        ->assertOk()->assertJsonPath('0.phone', '+998901234567');
});

test('blacklisted client is rejected', function () {
    Client::query()->create(['phone' => '+998901234567', 'is_blacklisted' => true]);

    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));

    expect(Call::query()->withoutGlobalScopes()->sole()->status)->toBe('blocked')
        ->and(ariRequests('endpoint=PJSIP/op'))->toBeEmpty();
});

test('calls over the trunk channel limit are rejected', function () {
    $this->trunk->update(['max_channels' => 1]);
    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $this->center->handle(stasis('ch-2', ['inbound', '712000000'], caller: '998907654321'));

    // Yangi qo'ng'iroqning o'z qatori limitga sanalmaydi: bitta liniyada birinchisi qabul qilinadi
    expect(Call::query()->withoutGlobalScopes()->where('channel_id', 'ch-1')->sole())
        ->status->toBe('waiting')
        ->operator_id->toBe($this->operator->id);
    expect(Call::query()->withoutGlobalScopes()->where('channel_id', 'ch-2')->sole())
        ->status->toBe('rejected')
        ->meta->toMatchArray(['reason' => 'channels']);
});

test('branch channel share does not count the new call itself', function () {
    $this->branch->update(['max_channels' => 1]);
    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));

    expect(Call::query()->withoutGlobalScopes()->sole()->status)->toBe('waiting');
});

test('operator callback fits into a single free line', function () {
    $this->trunk->update(['max_channels' => 1]);

    operatorDials($this, '901234567');

    expect(Call::query()->withoutGlobalScopes()->sole()->status)->toBe('ringing')
        ->and(ariRequests('endpoint=PJSIP/998901234567@trunk-sarkor'))->toHaveCount(1);
});

test('driver calling the dispatcher waits in his branch queue', function () {
    $driver = driverNear($this->branch, 100);

    $this->center->handle(stasis('ch-d', ['internal', 'dispatcher'], "PJSIP/dr{$driver->id}-0001", "dr{$driver->id}"));

    expect(Call::query()->withoutGlobalScopes()->sole())
        ->kind->toBe('driver')
        ->driver_id->toBe($driver->id)
        ->operator_id->toBe($this->operator->id);

    // Kutish paytida "kuting" xabari emas, oddiy gudok
    expect(ariRequests('/ari/channels/ch-d/play/'))->toHaveCount(1)
        ->and(ariRequests('media=tone:ring;tonezone=ru'))->toHaveCount(1)
        // Operator ovozi haydovchiga kuchaytirilib boradi
        ->and(ariRequests('/ari/channels/ch-d/variable?variable=VOLUME(TX)&value=4'))->toHaveCount(1);
});

test('driver call gain comes from settings and can be turned off', function () {
    $driver = driverNear($this->branch, 100);
    app(Settings::class)->set('calls.driver_gain', 2);
    $this->center->handle(stasis('ch-d', ['internal', 'dispatcher'], "PJSIP/dr{$driver->id}-0001", "dr{$driver->id}"));

    app(Settings::class)->set('calls.driver_gain', 0);
    $this->center->handle(stasis('ch-e', ['internal', 'dispatcher'], "PJSIP/dr{$driver->id}-0002", "dr{$driver->id}"));

    expect(ariRequests('/ari/channels/ch-d/variable?variable=VOLUME(TX)&value=2'))->toHaveCount(1)
        ->and(ariRequests('/ari/channels/ch-e/variable'))->toBeEmpty();
});

test('operator callback goes out through the trunk and bridges when answered', function () {
    $this->center->handle(stasis('ch-op', ['internal', '901234567'], "PJSIP/{$this->operator->sip_extension}-0001", $this->operator->sip_extension));

    $call = Call::query()->withoutGlobalScopes()->sole();
    expect($call->kind)->toBe('callback')
        ->and(ariRequests('endpoint=PJSIP/998901234567@trunk-sarkor'))->toHaveCount(1);

    $this->center->handle(stasis($call->meta['peer_channel'], ['peer', (string) $call->id]));

    expect($call->refresh()->status)->toBe('answered')
        ->and(ariRequests('/ari/channels/ch-op/answer'))->toHaveCount(1);
});

test('auto call tells the client the order was cancelled and retries when unanswered', function () {
    SoundClip::query()->create(['category' => 'phrase', 'key' => 'cancelled', 'text' => 'Buyurtmangiz bekor qilindi.', 'file_path' => 'phrase/cancelled']);
    $order = Order::factory()->create(['branch_id' => $this->branch->id, 'status' => OrderStatus::CancelledByOperator]);

    (new PlaceAutoCall($order->id, 'cancelled'))->handle(...array_map('app', [
        AriClient::class, ChannelLimits::class, MessageComposer::class,
        Router::class, CallCenter::class, ClientNotifier::class,
    ]));

    $call = Call::query()->withoutGlobalScopes()->sole();
    expect($call->kind)->toBe('tts')
        ->and($call->meta['media'])->toBe(['sound:dtaxi/phrase/cancelled'])
        ->and(ariRequests('appArgs=tts,'.$call->id))->toHaveCount(1);

    $this->center->handle(['type' => 'ChannelDestroyed', 'channel' => ['id' => $call->channel_id], 'cause' => 19]);

    expect($call->refresh()->status)->toBe('no_answer');
    Queue::assertPushed(PlaceAutoCall::class, fn ($job) => $job->orderId === $order->id && $job->attempt === 2);
});

test('login code call says the code twice to the client and is not retried when unanswered', function () {
    foreach (['login-code', 'code-repeat'] as $key) {
        SoundClip::query()->create(['category' => 'phrase', 'key' => $key, 'text' => 'x', 'file_path' => "phrase/{$key}"]);
    }
    foreach (['4', '7', '2', '1'] as $digit) {
        SoundClip::query()->create(['category' => 'number', 'key' => $digit, 'text' => 'x', 'file_path' => "number/{$digit}"]);
    }

    (new PlaceCodeCall('+998901234567', '4721'))->handle(...array_map('app', [AriClient::class, ChannelLimits::class, MessageComposer::class, CallCenter::class]));

    $call = Call::query()->withoutGlobalScopes()->sole();
    $digits = ['sound:dtaxi/number/4', 'sound:dtaxi/number/7', 'sound:dtaxi/number/2', 'sound:dtaxi/number/1'];
    expect($call->kind)->toBe('tts')
        ->and($call->callee)->toBe('+998901234567')
        ->and($call->order_id)->toBeNull()
        ->and($call->meta['message'])->toBe('login_code')
        ->and($call->meta['media'])->toBe(['sound:dtaxi/phrase/login-code', ...$digits, 'sound:dtaxi/phrase/code-repeat', ...$digits])
        ->and(ariRequests('endpoint=PJSIP/998901234567@trunk-sarkor'))->toHaveCount(1);

    $this->center->handle(['type' => 'ChannelDestroyed', 'channel' => ['id' => $call->channel_id], 'cause' => 19]);
    expect($call->refresh()->status)->toBe('no_answer');
    Queue::assertNotPushed(PlaceAutoCall::class);
});

test('answered auto call plays the message twice and hangs up', function () {
    $order = Order::factory()->create(['branch_id' => $this->branch->id]);
    $call = Call::query()->create([
        'channel_id' => 'ch-tts', 'branch_id' => $this->branch->id, 'direction' => 'outbound', 'kind' => 'tts',
        'order_id' => $order->id, 'status' => 'ringing', 'started_at' => now(), 'meta' => ['message' => 'arrived', 'media' => ['sound:dtaxi/phrase/driver-waiting']],
    ]);
    $this->center->remember('ch-tts', $call, 'client');

    $this->center->handle(stasis('ch-tts', ['tts', (string) $call->id]));
    $this->center->handle(['type' => 'PlaybackFinished', 'playback' => ['id' => $call->refresh()->meta['playback']]]);
    $this->center->handle(['type' => 'PlaybackFinished', 'playback' => ['id' => $call->refresh()->meta['playback']]]);

    expect(ariRequests('/ari/channels/ch-tts/play/'))->toHaveCount(2)
        ->and(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/ari/channels/ch-tts'))->count())->toBe(1);
});

test('the last failed auto call attempt is flagged for the operator', function () {
    $order = Order::factory()->create(['branch_id' => $this->branch->id]);
    $call = Call::query()->create([
        'channel_id' => 'ch-tts', 'branch_id' => $this->branch->id, 'direction' => 'outbound', 'kind' => 'tts', 'attempt' => 3,
        'order_id' => $order->id, 'status' => 'ringing', 'started_at' => now(), 'meta' => ['message' => 'arrived'],
    ]);

    $this->center->handle(['type' => 'ChannelDestroyed', 'channel' => ['id' => 'ch-tts'], 'cause' => 17]);

    expect($call->refresh()->status)->toBe('busy')
        ->and(OrderEvent::query()->where('order_id', $order->id)->where('type', 'client_not_notified')->exists())->toBeTrue();
    Queue::assertNotPushed(PlaceAutoCall::class);
});

test('order events queue the right voice messages', function () {
    $order = Order::factory()->create(['branch_id' => $this->branch->id]);

    OrderEvents::record($order, 'arrived');

    Queue::assertPushed(PlaceAutoCall::class, fn ($job) => $job->message === 'arrived');
    Queue::assertPushed(PlaceAutoCall::class, fn ($job) => $job->message === 'still_waiting');
});

test('operator switches between ready and paused', function () {
    $this->actingAs($this->operator)->postJson(route('operator.phone.status'), ['status' => 'paused'])
        ->assertOk()->assertJson(['status' => 'paused']);

    $this->actingAs($this->operator)->postJson(route('operator.phone.status'), ['status' => 'busy'])
        ->assertUnprocessable();
});

test('calls outside working hours hear a message and are not queued for callback', function () {
    $this->branch->update(['holidays' => [now('Asia/Tashkent')->toDateString()]]);

    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));

    $call = Call::query()->withoutGlobalScopes()->sole();
    expect($call->status)->toBe('rejected')
        ->and($call->meta['reason'])->toBe('closed')
        ->and(ariRequests('endpoint=PJSIP/op'))->toBeEmpty();
    $this->actingAs($this->operator)->getJson(route('operator.phone.missed'))->assertJsonCount(0);
});

function regularClient(Branch $branch): Order
{
    app(Settings::class)->set('calls.ivr_auto_order', true);
    SoundClip::query()->create(['category' => 'ivr', 'key' => 'auto-order-offer', 'text' => 'x', 'file_path' => 'ivr/auto-order-offer']);
    $client = Client::query()->create(['phone' => '+998901234567', 'orders_count' => 3]);

    return Order::factory()->create(['branch_id' => $branch->id, 'client_id' => $client->id, 'status' => OrderStatus::Completed, 'pickup_text' => 'Uyim']);
}

test('regular client presses 1 and gets a taxi to the last address without an operator', function () {
    $last = regularClient($this->branch);
    driverNear($this->branch, 300);

    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $call = Call::query()->withoutGlobalScopes()->sole();
    expect($call->meta['auto_offer'])->toBe($last->id)
        ->and(ariRequests('endpoint=PJSIP/op'))->toBeEmpty();

    $this->center->handle(['type' => 'ChannelDtmfReceived', 'channel' => ['id' => 'ch-1'], 'digit' => '1']);

    $order = Order::query()->withoutGlobalScopes()->latest('id')->first();
    expect($order->id)->not->toBe($last->id)
        ->and($order->source)->toBe(OrderSource::Ivr)
        ->and($order->pickup_text)->toBe('Uyim')
        ->and($order->status)->toBe(OrderStatus::Searching)
        ->and($call->refresh()->order_id)->toBe($order->id);
});

test('client who presses 2 or stays silent goes to the operator queue', function () {
    regularClient($this->branch);

    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $this->center->handle(['type' => 'ChannelDtmfReceived', 'channel' => ['id' => 'ch-1'], 'digit' => '2']);
    expect(Call::query()->withoutGlobalScopes()->sole()->operator_id)->toBe($this->operator->id);

    // Jim tursa: taklif tugagach 5 soniya
    $this->center->handle(stasis('ch-2', ['inbound', '712000000']));
    $call = Call::query()->withoutGlobalScopes()->where('channel_id', 'ch-2')->sole();
    $this->center->handle(['type' => 'PlaybackFinished', 'playback' => ['id' => $call->meta['playback']]]);
    $this->travel(6)->seconds();
    $second = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Operator);
    $second->forceFill(['sip_extension' => 'op'.$second->id, 'operator_status' => 'ready'])->save();
    $this->online[] = $second->sip_extension;
    Cache::forget('telephony:online-endpoints');
    $this->center->tick();

    expect($call->refresh()->operator_id)->toBe($second->id);
});

test('after the trip the client is called and rates it by pressing a digit', function () {
    app(Settings::class)->set('calls.rating_call_enabled', true);
    $driver = driverNear($this->branch, 300, ['rating' => 90]);
    $order = Order::factory()->create(['branch_id' => $this->branch->id, 'status' => OrderStatus::Completed, 'driver_id' => $driver->id]);

    OrderEvents::record($order, 'completed');
    Queue::assertPushed(PlaceAutoCall::class, fn ($job) => $job->message === 'rate');

    $call = Call::query()->create([
        'channel_id' => 'ch-rate', 'branch_id' => $this->branch->id, 'direction' => 'outbound', 'kind' => 'tts',
        'order_id' => $order->id, 'status' => 'ringing', 'started_at' => now(), 'meta' => ['message' => 'rate', 'media' => ['sound:dtaxi/phrase/rate-trip']],
    ]);
    $this->center->remember('ch-rate', $call, 'client');
    $this->center->handle(stasis('ch-rate', ['tts', (string) $call->id]));

    $this->center->handle(['type' => 'ChannelDtmfReceived', 'channel' => ['id' => 'ch-rate'], 'digit' => '2']);
    $this->center->handle(['type' => 'ChannelDtmfReceived', 'channel' => ['id' => 'ch-rate'], 'digit' => '5']);

    expect($order->fresh()->client_rating)->toBe(2)
        ->and($driver->fresh()->rating)->toBe(85);
});

/**
 * Operator haydovchiga (pozivnoy bilan) qo'ng'iroq qildi — ilovaga xabar ketdi, kanal jiringlab turibdi.
 */
function operatorRingsDriver($test, string $callsign = '777'): Call
{
    operatorDials($test, $callsign);

    return Call::query()->withoutGlobalScopes()->sole();
}

test('operator calls a driver by callsign: the app is notified instead of dialing an unregistered endpoint', function () {
    $driver = driverNear($this->branch, 0, ['callsign' => '777']);

    $call = operatorRingsDriver($this);

    expect($call)
        ->kind->toBe('driver')
        ->direction->toBe('internal')
        ->status->toBe('ringing')
        ->driver_id->toBe($driver->id)
        ->operator_id->toBe($this->operator->id)
        ->and($call->meta['driver_ring_until'])->toBe(now()->addSeconds(30)->getTimestamp());

    // Ro'yxatdan o'tmagan PJSIP/dr{id} ga originate yo'q; operator brauzeri gudok chaladi (180 Ringing)
    expect(ariRequests('endpoint=PJSIP/dr'))->toBeEmpty()
        ->and(ariRequests('/ari/channels/ch-op/ring'))->toHaveCount(1)
        ->and(ariRequests('/ari/channels/ch-op/answer'))->toBeEmpty();
    Event::assertDispatched(IncomingCall::class, fn (IncomingCall $e) => $e->driverId === $driver->id && $e->callId === $call->id
        && $e->operator === $this->operator->name && $e->expiresIn === 30);
    Queue::assertPushed(SendPushNotification::class, fn (SendPushNotification $job) => $job->userId === $driver->user_id
        && $job->data['type'] === 'incoming_call' && $job->data['call_id'] === $call->id && $job->data['expires_in'] === 30);
});

test('ring time for the driver app comes from settings', function () {
    driverNear($this->branch, 0, ['callsign' => '777']);
    app(Settings::class)->set('calls.driver_ring_seconds', 45);

    operatorRingsDriver($this);

    Event::assertDispatched(IncomingCall::class, fn (IncomingCall $e) => $e->expiresIn === 45);
});

test('an offline driver without a push token is reported unreachable at once', function () {
    driverNear($this->branch, 0, ['callsign' => '777', 'status' => DriverStatus::Offline]);

    $call = operatorRingsDriver($this);

    expect($call->status)->toBe('failed')
        ->and($call->ended_at)->not->toBeNull()
        ->and(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/ari/channels/ch-op'))->count())->toBe(1);
    Event::assertNotDispatched(IncomingCall::class);
});

test('an offline driver with a push token is still rung through FCM', function () {
    $driver = driverNear($this->branch, 0, ['callsign' => '777', 'status' => DriverStatus::Offline]);
    DeviceToken::query()->create(['user_id' => $driver->user_id, 'platform' => 'android', 'token' => 'fcm-1']);

    expect(operatorRingsDriver($this)->status)->toBe('ringing');
    Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->data['type'] === 'incoming_call');
});

test('operator cannot ring a driver of another branch', function () {
    $other = driverNear(Branch::factory()->create(), 0, ['callsign' => '777']);

    operatorDials($this, '777');
    operatorDials($this, 'dr'.$other->id, 'ch-op2');

    expect(Call::query()->withoutGlobalScopes()->count())->toBe(0);
    Event::assertNotDispatched(IncomingCall::class);
});

test('driver answers from the app: his answer leg is bridged with the operator', function () {
    $driver = driverNear($this->branch, 0, ['callsign' => '777']);
    $call = operatorRingsDriver($this);

    $this->center->handle(stasis('ch-dr', ['internal', "answer-{$call->id}"], "PJSIP/dr{$driver->id}-0001", "dr{$driver->id}"));
    $call->refresh();

    expect($call->status)->toBe('answered')
        ->and($call->answered_at)->not->toBeNull()
        ->and($call->meta['peer_channel'])->toBe('ch-dr')
        ->and($call->meta['bridge'])->toBe('bridge-1')
        ->and(ariRequests('/ari/channels/ch-op/answer'))->toHaveCount(1)
        ->and(ariRequests('/ari/channels/ch-dr/answer'))->toHaveCount(1)
        ->and(ariRequests('/ari/bridges/bridge-1/record'))->toHaveCount(1)
        ->and(ariRequests('/ari/channels/ch-dr/variable?variable=VOLUME(TX)&value=4'))->toHaveCount(1);
    // Javob berilgan qo'ng'iroq dispetcher navbatiga tushmaydi
    expect(Call::query()->withoutGlobalScopes()->count())->toBe(1);

    // Haydovchi qo'ydi — operator ham uziladi, yozuv qayta ishlanadi, "tugadi" xabari ilovaga kerak emas
    $this->center->handle(['type' => 'StasisEnd', 'channel' => ['id' => 'ch-dr']]);
    expect($call->refresh()->status)->toBe('completed')
        ->and(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/ari/channels/ch-op'))->count())->toBe(1);
    Queue::assertPushed(ProcessRecording::class);
    Event::assertNotDispatched(IncomingCallEnded::class);
});

test('answer legs from another driver or for a finished call are rejected', function () {
    $driver = driverNear($this->branch, 0, ['callsign' => '777']);
    $stranger = driverNear($this->branch, 50, ['callsign' => '888']);
    $call = operatorRingsDriver($this);

    $this->center->handle(stasis('ch-x', ['internal', "answer-{$call->id}"], "PJSIP/dr{$stranger->id}-0001", "dr{$stranger->id}"));
    expect($call->refresh()->status)->toBe('ringing')
        ->and(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/ari/channels/ch-x'))->count())->toBe(1);

    // Operator qo'yib qo'ydi — kechikkan javob ulanmaydi
    $this->center->handle(['type' => 'StasisEnd', 'channel' => ['id' => 'ch-op']]);
    $this->center->handle(stasis('ch-late', ['internal', "answer-{$call->id}"], "PJSIP/dr{$driver->id}-0002", "dr{$driver->id}"));

    expect($call->refresh()->status)->not->toBe('answered')
        ->and(ariRequests('/ari/channels/ch-late/answer'))->toBeEmpty()
        ->and(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/ari/channels/ch-late'))->count())->toBe(1)
        ->and(ariRequests('/ari/bridges'))->toBeEmpty();
});

test('operator hanging up while the app rings dismisses the driver screen', function () {
    $driver = driverNear($this->branch, 0, ['callsign' => '777']);
    $call = operatorRingsDriver($this);

    $this->center->handle(['type' => 'StasisEnd', 'channel' => ['id' => 'ch-op']]);

    expect($call->refresh()->ended_at)->not->toBeNull();
    Event::assertDispatched(IncomingCallEnded::class, fn (IncomingCallEnded $e) => $e->driverId === $driver->id && $e->callId === $call->id);
    Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->data['type'] === 'incoming_call_ended' && $job->data['call_id'] === $call->id);
});

test('driver declines in the app: the operator gets busy and the screen is dismissed', function () {
    $driver = driverNear($this->branch, 0, ['callsign' => '777']);
    $call = operatorRingsDriver($this);

    Sanctum::actingAs($driver->user, ['driver']);
    $this->postJson("/api/driver/v1/phone/calls/{$call->id}/decline")->assertOk();

    expect($call->refresh())
        ->status->toBe('declined')
        ->ended_at->not->toBeNull()
        ->and($call->meta['reason'])->toBe('declined')
        ->and(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/ari/channels/ch-op') && $r['reason'] === 'busy')->count())->toBe(1);
    Event::assertDispatched(IncomingCallEnded::class, fn (IncomingCallEnded $e) => $e->callId === $call->id && $e->status === 'declined');
});

test('a driver cannot decline somebody else\'s call', function () {
    driverNear($this->branch, 0, ['callsign' => '777']);
    $stranger = driverNear($this->branch, 50, ['callsign' => '888']);
    $call = operatorRingsDriver($this);

    Sanctum::actingAs($stranger->user, ['driver']);
    $this->postJson("/api/driver/v1/phone/calls/{$call->id}/decline")->assertNotFound();

    expect($call->refresh()->status)->toBe('ringing');
});

test('unanswered driver ring times out on tick', function () {
    $driver = driverNear($this->branch, 0, ['callsign' => '777']);
    $call = operatorRingsDriver($this);

    $this->travel(29)->seconds();
    $this->center->tick();
    expect($call->refresh()->status)->toBe('ringing');

    $this->travel(2)->seconds();
    $this->center->tick();

    expect($call->refresh())
        ->status->toBe('no_answer')
        ->and($call->meta['reason'])->toBe('timeout')
        ->and(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/ari/channels/ch-op') && $r['reason'] === 'no_answer')->count())->toBe(1);
    Event::assertDispatched(IncomingCallEnded::class, fn (IncomingCallEnded $e) => $e->driverId === $driver->id && $e->status === 'no_answer');
});

test('superadmin without a branch gets an extension and takes queued calls when ready', function () {
    $superadmin = User::factory()->create(['branch_id' => null])->assignRole(Role::Superadmin);
    app(Extensions::class)->syncFor($superadmin);
    $superadmin->refresh()->forceFill(['operator_status' => 'ready'])->save();
    $this->operator->forceFill(['operator_status' => 'paused'])->save();
    $this->online[] = $superadmin->sip_extension;

    expect($superadmin->sip_extension)->toBe('op'.$superadmin->id)
        ->and(app(OperatorPool::class)->pick([$this->branch->id])?->id)->toBe($superadmin->id);
});

test('operator is released when Asterisk is unreachable while ringing him', function () {
    $this->ari['down'] = ['appArgs=operator'];

    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));

    $call = Call::query()->withoutGlobalScopes()->sole();
    expect($call->status)->toBe('waiting')
        ->and($call->operator_id)->toBeNull()
        ->and($call->meta['operator_channel'])->toBeNull()
        ->and(app(OperatorPool::class)->busyOperatorIds())->toBe([]);

    // Asterisk qaytdi — keyingi tick'da qo'ng'iroq operatorga boradi
    $this->ari['down'] = [];
    $this->center->tick();
    expect($call->refresh()->operator_id)->toBe($this->operator->id);
});

test('a channel whose StasisStart handler crashed is hung up', function () {
    $this->partialMock(OperatorPool::class, fn ($mock) => $mock->shouldReceive('pick')->andThrow(new RuntimeException('boom')));

    app(CallCenter::class)->handle(stasis('ch-1', ['inbound', '712000000']));

    expect(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/ari/channels/ch-1'))->count())->toBe(1);
});

test('the listener sees settings changed in the panel without a restart', function () {
    $this->operator->forceFill(['operator_status' => 'paused'])->save();
    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $listener = app(AriListen::class);
    $listener->guard(fn () => $this->center->tick());
    $this->travel(100)->seconds();

    // Panel boshqa jarayonda saqlaydi — daemon'ning Settings keshi bilmaydi
    Setting::query()->updateOrCreate(['branch_id' => null, 'key' => 'calls.max_wait_seconds'], ['value' => 60]);
    $listener->guard(fn () => $this->center->tick());

    expect(Call::query()->withoutGlobalScopes()->sole()->meta)->toMatchArray(['closing' => true, 'reason' => 'timeout']);
});

test('max wait also ends a call that is still ringing an operator', function () {
    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $call = Call::query()->withoutGlobalScopes()->sole();
    $operatorChannel = $call->meta['operator_channel'];
    expect($call->operator_id)->toBe($this->operator->id);

    $this->travel(181)->seconds();
    $this->center->tick();

    expect($call->refresh()->meta)->toMatchArray(['closing' => true, 'reason' => 'timeout', 'operator_channel' => null])
        ->and($call->operator_id)->toBeNull()
        ->and(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), "/ari/channels/{$operatorChannel}"))->count())->toBe(1);

    // Operator kanali yopilgani qo'ng'iroqni qayta navbatga qo'ymaydi
    $this->center->handle(['type' => 'ChannelDestroyed', 'channel' => ['id' => $operatorChannel], 'cause' => 16]);
    $this->center->tick();
    expect($call->refresh()->operator_id)->toBeNull()
        ->and(ariRequests('appArgs=operator'))->toHaveCount(1);
});

function answeredCall($test): Call
{
    $test->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $call = Call::query()->withoutGlobalScopes()->sole();
    $test->center->handle(stasis($call->meta['operator_channel'], ['operator', (string) $call->id], 'PJSIP/op1-0001'));

    return $call->refresh();
}

function bridgeDeletes(): int
{
    return Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/ari/bridges/bridge-1'))->count();
}

test('bridge recording has a maximum duration', function () {
    answeredCall($this);

    expect(ariRequests('/ari/bridges/bridge-1/record?name=call-'))->toHaveCount(1)
        ->and(ariRequests('maxDurationSeconds=10800'))->toHaveCount(1);
});

test('the bridge is destroyed even if hanging up the other channels fails', function () {
    $call = answeredCall($this);
    $this->partialMock(AriClient::class, fn ($mock) => $mock->shouldReceive('hangup')->andThrow(new RuntimeException('boom')));

    app(CallCenter::class)->handle(['type' => 'ChannelDestroyed', 'channel' => ['id' => 'ch-1'], 'cause' => 16]);

    expect($call->refresh()->ended_at)->not->toBeNull()
        ->and(bridgeDeletes())->toBe(1);
});

test('a bridge left open at finish is closed by the next channel event, once', function () {
    $call = answeredCall($this);
    $operatorChannel = $call->meta['operator_channel'];

    $this->ari['down'] = ['/ari/bridges/bridge-1'];
    $this->center->handle(['type' => 'ChannelDestroyed', 'channel' => ['id' => 'ch-1'], 'cause' => 16]);
    expect($call->refresh()->status)->toBe('completed')
        ->and($call->meta)->not->toHaveKey('bridge_closed');

    $this->ari['down'] = [];
    $this->center->handle(['type' => 'StasisEnd', 'channel' => ['id' => $operatorChannel]]);
    $this->center->handle(['type' => 'ChannelDestroyed', 'channel' => ['id' => $operatorChannel], 'cause' => 16]);

    expect(bridgeDeletes())->toBe(1)
        ->and($call->refresh()->meta['bridge_closed'])->toBeTrue();
});

test('reconnect keeps calls whose channels are still alive and handles their later events', function () {
    $call = answeredCall($this);
    $operatorChannel = $call->meta['operator_channel'];
    // Daemon qayta ishga tushdi, kesh bog'lanishlari ham yo'qoldi
    Cache::flush();
    $this->travel(30)->seconds();
    $this->ari['channels'] = ['ch-1', $operatorChannel];

    $this->center->reconcile();

    expect($call->refresh()->status)->toBe('answered')
        ->and($call->ended_at)->toBeNull()
        ->and(Cache::get("ari:ch:{$operatorChannel}"))->toBe(['call' => $call->id, 'role' => 'operator']);

    $this->center->handle(['type' => 'StasisEnd', 'channel' => ['id' => $operatorChannel]]);
    expect($call->refresh()->status)->toBe('completed')
        ->and(bridgeDeletes())->toBe(1);
});

test('reconnect finishes calls whose channels vanished and releases the operator', function () {
    $call = answeredCall($this);
    $this->travel(30)->seconds();
    $this->ari['channels'] = [];

    $this->center->reconcile();

    expect($call->refresh()->status)->toBe('completed')
        ->and($call->ended_at)->not->toBeNull()
        ->and(app(OperatorPool::class)->busyOperatorIds())->toBe([])
        ->and(bridgeDeletes())->toBe(1);
});

test('a queued call whose client vanished becomes missed, one whose operator ring vanished goes on', function () {
    $other = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Operator);
    $other->forceFill(['sip_extension' => 'op'.$other->id, 'operator_status' => 'ready', 'operator_status_at' => now()])->save();
    $this->online[] = $other->sip_extension;
    $this->operator->forceFill(['operator_status_at' => now()->subHour()])->save();

    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $this->center->handle(stasis('ch-2', ['inbound', '712000000'], caller: '998907654321'));
    $first = Call::query()->withoutGlobalScopes()->where('channel_id', 'ch-1')->sole();
    $second = Call::query()->withoutGlobalScopes()->where('channel_id', 'ch-2')->sole();
    expect($first->operator_id)->toBe($this->operator->id)->and($second->operator_id)->toBe($other->id);

    $this->travel(30)->seconds();
    Cache::forget('telephony:online-endpoints');
    // Birinchi mijoz ham, uning operator jiringlashi ham yo'q; ikkinchi mijoz tirik, uning operator jiringlashi yo'q
    $this->ari['channels'] = ['ch-2'];
    $this->center->reconcile();

    expect($first->refresh()->status)->toBe('missed')
        ->and($second->refresh()->status)->toBe('waiting')
        ->and($second->ended_at)->toBeNull()
        // Bo'shagan birinchi operatorga o'tdi
        ->and($second->operator_id)->toBe($this->operator->id);
});

test('reconciliation does nothing when Asterisk cannot list channels, and spares brand-new calls', function () {
    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $call = Call::query()->withoutGlobalScopes()->sole();

    $this->ari['channels'] = [];
    $this->center->reconcile();
    expect($call->refresh()->ended_at)->toBeNull();

    $this->travel(30)->seconds();
    $this->ari['channels'] = null;
    $this->center->reconcile();
    expect($call->refresh()->ended_at)->toBeNull();
});

test('orphan Stasis bridges are destroyed, bridges of live calls are kept', function () {
    $call = answeredCall($this);
    $this->ari['channels'] = ['ch-1', $call->meta['operator_channel']];
    $this->ari['bridges'] = [
        ['id' => 'bridge-1', 'bridge_class' => 'stasis', 'channels' => ['ch-1']],
        ['id' => 'orphan', 'bridge_class' => 'stasis', 'channels' => []],
        ['id' => 'foreign', 'bridge_class' => 'base', 'channels' => []],
    ];

    $this->center->reconcile();

    $deleted = Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/ari/bridges/'))
        ->map(fn ($pair) => basename($pair[0]->url()))->values()->all();
    expect($deleted)->toBe(['orphan']);
});

function operatorDials($test, string $exten, string $channel = 'ch-op'): void
{
    $test->center->handle(stasis($channel, ['internal', $exten], "PJSIP/{$test->operator->sip_extension}-0001", $test->operator->sip_extension));
}

test('operator cannot dial numbers outside the allow-list', function (string $exten) {
    operatorDials($this, $exten);

    expect(Call::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(ariRequests('/ari/channels?endpoint='))->toBeEmpty()
        ->and(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/ari/channels/ch-op'))->count())->toBe(1);
})->with(['international' => '0088216123456', 'plus international' => '+79161234567', 'premium short' => '0900', 'too long' => '99890123456789']);

test('operator can dial local numbers in any common form', function (string $exten) {
    operatorDials($this, $exten);

    expect(ariRequests('endpoint=PJSIP/998901234567@trunk-sarkor'))->toHaveCount(1);
})->with(['9 digits' => '901234567', 'full' => '998901234567', 'plus' => '+998901234567']);

test('allowed outbound patterns come from settings', function () {
    app(Settings::class)->set('calls.outbound_allowed_patterns', ['^998\d{9}$', '^7\d{10}$', '(broken']);

    operatorDials($this, '79161234567');

    expect(ariRequests('endpoint=PJSIP/79161234567@trunk-sarkor'))->toHaveCount(1);
});

test('outbound calls per operator are rate limited', function () {
    app(Settings::class)->set('calls.operator_outbound_limit', 2);

    foreach (['ch-a', 'ch-b', 'ch-c'] as $channel) {
        operatorDials($this, '901234567', $channel);
    }

    expect(ariRequests('@trunk-sarkor'))->toHaveCount(2)
        ->and(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/ari/channels/ch-c'))->count())->toBe(1);

    // Haydovchiga qo'ng'iroq bepul — chegaraga kirmaydi
    $driver = driverNear($this->branch, 0, ['callsign' => '777']);
    operatorDials($this, '777', 'ch-d');
    expect(Call::query()->withoutGlobalScopes()->where('driver_id', $driver->id)->sole()->status)->toBe('ringing');
});

test('the listener loop survives any exception', function () {
    app(AriListen::class)->guard(fn () => throw new RuntimeException('boom'));

    expect(true)->toBeTrue();
});

test('a failing broadcast does not hang up or stall the call', function () {
    Event::fake([OperatorCallEvent::class]);
    Event::listen(CallUpdated::class, fn () => throw new RuntimeException('reverb down'));

    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $call = Call::query()->withoutGlobalScopes()->sole();
    $this->center->handle(stasis($call->meta['operator_channel'], ['operator', (string) $call->id], 'PJSIP/op1-0001'));

    expect($call->refresh()->status)->toBe('answered')
        ->and(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/ari/channels/ch-1'))->count())->toBe(0);
});

test('a closing message whose playback never finishes is ended after the deadline', function () {
    SoundClip::query()->create(['category' => 'ivr', 'key' => 'lines-busy', 'text' => 'x', 'file_path' => 'ivr/lines-busy']);
    $this->trunk->update(['max_channels' => 0]);

    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $call = Call::query()->withoutGlobalScopes()->sole();
    expect($call->status)->toBe('rejected')->and($call->meta['playback_until'])->not->toBeNull();

    $this->travel(60)->seconds();
    $this->center->tick();
    expect($call->refresh()->ended_at)->toBeNull();

    $this->travel(61)->seconds();
    $this->center->tick();
    expect($call->refresh())
        ->status->toBe('rejected')
        ->ended_at->not->toBeNull()
        ->and(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/ari/channels/ch-1'))->count())->toBe(1);
});

test('a finished playback clears its deadline', function () {
    regularClient($this->branch);
    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $call = Call::query()->withoutGlobalScopes()->sole();

    $this->center->handle(['type' => 'PlaybackFinished', 'playback' => ['id' => $call->meta['playback']]]);

    expect($call->refresh()->meta['playback_until'])->toBeNull()
        ->and($call->meta['auto_offer_until'])->not->toBeNull();
});

test('an auto order offer that cannot be played goes straight to the operator queue', function () {
    regularClient($this->branch);
    $this->ari['down'] = ['/play/'];

    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));

    expect(Call::query()->withoutGlobalScopes()->sole())
        ->operator_id->toBe($this->operator->id)
        ->meta->toMatchArray(['auto_offer' => null, 'playback' => null]);
});

test('a closing message that cannot be played hangs up at once', function () {
    SoundClip::query()->create(['category' => 'ivr', 'key' => 'lines-busy', 'text' => 'x', 'file_path' => 'ivr/lines-busy']);
    $this->trunk->update(['max_channels' => 0]);
    $this->ari['down'] = ['/play/'];

    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));

    expect(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/ari/channels/ch-1'))->count())->toBe(1);
});

test('if the operator leg cannot be bridged the client goes back to the queue', function () {
    $this->ari['down'] = ['addChannel'];
    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $call = Call::query()->withoutGlobalScopes()->sole();
    $operatorChannel = $call->meta['operator_channel'];

    $this->center->handle(stasis($operatorChannel, ['operator', (string) $call->id], 'PJSIP/op1-0001'));

    expect($call->refresh())
        ->status->toBe('waiting')
        ->operator_id->toBeNull()
        ->and($call->meta['operator_channel'])->toBeNull()
        ->and($call->meta['bridge'])->toBeNull()
        ->and(bridgeDeletes())->toBe(1)
        ->and(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), "/ari/channels/{$operatorChannel}"))->count())->toBe(1);

    // Operator kanali yopilishi "javob bermadi" emas — pauza yo'q; keyingi tick'da yana taklif
    $this->center->handle(['type' => 'ChannelDestroyed', 'channel' => ['id' => $operatorChannel], 'cause' => 16]);
    expect($this->operator->refresh()->operator_status)->toBe('ready');
    $this->ari['down'] = [];
    $this->center->tick();
    expect($call->refresh()->operator_id)->toBe($this->operator->id);
});

test('a callback that cannot be bridged fails instead of showing as answered', function () {
    $this->ari['down'] = ['addChannel'];
    operatorDials($this, '901234567');
    $call = Call::query()->withoutGlobalScopes()->sole();

    $this->center->handle(stasis($call->meta['peer_channel'], ['peer', (string) $call->id]));

    expect($call->refresh())
        ->status->toBe('failed')
        ->ended_at->not->toBeNull()
        ->answered_at->toBeNull();
});

test('a new call does not jump ahead of an older call waiting for an operator', function () {
    $this->operator->forceFill(['operator_status' => 'paused'])->save();
    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $this->travel(5)->seconds();

    $this->operator->forceFill(['operator_status' => 'ready'])->save();
    $this->center->handle(stasis('ch-2', ['inbound', '712000000'], caller: '998907654321'));

    expect(Call::query()->withoutGlobalScopes()->where('channel_id', 'ch-2')->sole()->operator_id)->toBeNull();

    $this->center->tick();
    expect(Call::query()->withoutGlobalScopes()->where('channel_id', 'ch-1')->sole()->operator_id)->toBe($this->operator->id)
        ->and(Call::query()->withoutGlobalScopes()->where('channel_id', 'ch-2')->sole()->operator_id)->toBeNull();
});

test('a call of another branch is not held back by an older call', function () {
    $this->operator->forceFill(['operator_status' => 'paused'])->save();
    $other = Branch::factory()->create();
    Call::query()->create([
        'channel_id' => 'ch-old', 'branch_id' => $other->id, 'direction' => 'inbound', 'kind' => 'client',
        'status' => 'waiting', 'started_at' => now()->subMinute(), 'meta' => ['branch_ids' => [$other->id]],
    ]);
    $this->operator->forceFill(['operator_status' => 'ready'])->save();

    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));

    expect(Call::query()->withoutGlobalScopes()->where('channel_id', 'ch-1')->sole()->operator_id)->toBe($this->operator->id);
});

test('a missed ring moves the operator to the back of the queue when pausing is off', function () {
    app(Settings::class)->set('calls.pause_on_missed_ring', false);
    $this->operator->forceFill(['operator_status_at' => now()->subHour()])->save();
    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $call = Call::query()->withoutGlobalScopes()->sole();

    $this->center->handle(['type' => 'ChannelDestroyed', 'channel' => ['id' => $call->meta['operator_channel']], 'cause' => 19]);

    expect($this->operator->refresh())
        ->operator_status->toBe('ready')
        ->operator_status_at->greaterThan(now()->subMinute())->toBeTrue();
});

test('meta updates merge with keys written by another process', function () {
    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $stale = Call::query()->withoutGlobalScopes()->sole();
    DB::table('calls')->where('id', $stale->id)->update(['meta' => DB::raw("meta || '{\"reason\": \"declined\"}'::jsonb")]);

    (fn () => $this->updateMeta($stale, ['plays' => 1]))->call($this->center);

    expect($stale->meta)->toMatchArray(['reason' => 'declined', 'plays' => 1])
        ->and($stale->isDirty('meta'))->toBeFalse()
        ->and($stale->fresh()->meta)->toMatchArray(['reason' => 'declined', 'plays' => 1, 'branch_ids' => [$this->branch->id]]);
});

test('a lost database connection is rethrown for the listener to retry, without hanging up', function () {
    $lost = new QueryException('pgsql', 'select 1', [], new PDOException('SQLSTATE[08006]: server closed the connection unexpectedly'));
    $this->partialMock(OperatorPool::class, fn ($mock) => $mock->shouldReceive('pick')->andThrow($lost));

    expect(fn () => app(CallCenter::class)->handle(stasis('ch-1', ['inbound', '712000000'])))->toThrow(QueryException::class)
        ->and(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/ari/channels/ch-1'))->count())->toBe(0);
});

test('the listener retries only lost-connection errors', function () {
    $runs = 0;
    app(AriListen::class)->guard(function () use (&$runs) {
        $runs++;
        throw new QueryException('pgsql', 'insert', [], new PDOException('SQLSTATE[23505]: duplicate key'));
    });

    expect($runs)->toBe(1);
});

test('concurrent auto calls wait for the trunk lock instead of overshooting the limit', function () {
    SoundClip::query()->create(['category' => 'phrase', 'key' => 'cancelled', 'text' => 'x', 'file_path' => 'phrase/cancelled']);
    $order = Order::factory()->create(['branch_id' => $this->branch->id, 'status' => OrderStatus::CancelledByOperator]);
    $lock = Cache::lock("autocall:trunk:{$this->trunk->id}", 30);
    $lock->get();

    try {
        (new PlaceAutoCall($order->id, 'cancelled'))->handle(...array_map('app', [
            AriClient::class, ChannelLimits::class, MessageComposer::class,
            Router::class, CallCenter::class, ClientNotifier::class,
        ]));
    } finally {
        $lock->release();
    }

    expect(Call::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('a replayed StasisStart does not open a second call record', function () {
    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));

    expect(Call::query()->withoutGlobalScopes()->count())->toBe(1);
});

test('a late hangup of an old operator ring does not count as a missed ring', function () {
    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $call = Call::query()->withoutGlobalScopes()->sole();
    $this->center->remember('old-ring', $call, 'operator');

    $this->center->handle(['type' => 'ChannelDestroyed', 'channel' => ['id' => 'old-ring'], 'cause' => 19]);

    expect($call->refresh()->operator_id)->toBe($this->operator->id)
        ->and($this->operator->refresh()->operator_status)->toBe('ready');
});

test('an auto call closed as failed is not revived when its channel answers late', function () {
    $call = Call::query()->create([
        'channel_id' => 'tts-1', 'branch_id' => $this->branch->id, 'direction' => 'outbound', 'kind' => 'tts',
        'caller' => '+998712000000', 'callee' => '+998901234567', 'status' => 'failed', 'started_at' => now(), 'ended_at' => now(),
    ]);

    $this->center->handle(stasis('tts-1', ['tts', (string) $call->id]));

    expect($call->refresh()->status)->toBe('failed')
        ->and(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/ari/channels/tts-1')))->toHaveCount(1);
});

test('an operator ring whose originate timed out is hung up, without a missed ring', function () {
    $this->ari['down'] = ['appArgs=operator'];

    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));

    expect(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/ari/channels/') && ! str_contains($r->url(), 'ch-1')))->toHaveCount(1)
        ->and($this->operator->refresh()->operator_status)->toBe('ready');
});

test('reconciliation bridges an operator who answered while the StasisStart event was lost', function () {
    $this->center->handle(stasis('ch-1', ['inbound', '712000000']));
    $call = Call::query()->withoutGlobalScopes()->sole();
    $this->travel(30)->seconds();
    $this->ari['channels'] = ['ch-1', ['id' => $call->meta['operator_channel'], 'state' => 'Up']];

    $this->center->reconcile();

    expect($call->refresh()->status)->toBe('answered');
});

// ── Mijoz ilovasidan qo'ng'iroq (qaror #72) ──────────────────────────

/**
 * Mijoz faol safarda: haydovchi biriktirilgan buyurtma.
 *
 * @return array{Client, Driver, Order}
 */
function clientOnTrip(Branch $branch, OrderStatus $status = OrderStatus::Assigned): array
{
    $client = Client::factory()->create(['phone' => '+998901112233', 'name' => 'Ali']);
    $driver = driverNear($branch, 0, ['callsign' => '777', 'status' => DriverStatus::Busy]);
    $order = Order::factory()->create(['branch_id' => $branch->id, 'client_id' => $client->id, 'driver_id' => $driver->id, 'status' => $status]);

    return [$client, $driver, $order];
}

function clientGrant(Client $client, string $target): TestResponse
{
    Sanctum::actingAs($client, ['client']);

    return test()->postJson('/api/client/v1/calls', ['target' => $target]);
}

test('client app gets a temporary extension that can only reach the driver or the dispatcher', function () {
    [$client] = clientOnTrip($this->branch);

    $sip = clientGrant($client, 'driver')->assertOk()->assertJsonPath('target', 'driver')->json('sip');

    expect($sip['extension'])->toBe("cl{$client->id}")
        ->and($sip['uri'])->toBe("sip:cl{$client->id}@localhost")
        ->and($sip['ws'])->toBe('wss://localhost/ws');
    $endpoint = PsEndpoint::query()->findOrFail("cl{$client->id}");
    expect($endpoint->context)->toBe('from-client')
        ->and($endpoint->allow)->toStartWith('opus')
        ->and(PsAuth::query()->findOrFail("cl{$client->id}")->md5_cred)->toBe(md5("cl{$client->id}:asterisk:{$sip['password']}"));

    // Har so'rovda yangi parol
    expect(clientGrant($client, 'driver')->json('sip.password'))->not->toBe($sip['password']);
});

test('client app call rings the assigned driver like an operator call and is bridged on answer', function () {
    [$client, $driver, $order] = clientOnTrip($this->branch);
    clientGrant($client, 'driver')->assertOk();

    $this->center->handle(stasis('ch-cl', ['client', 'driver'], "PJSIP/cl{$client->id}-0001", "cl{$client->id}"));

    $call = Call::query()->withoutGlobalScopes()->sole();
    expect($call)
        ->kind->toBe('driver')
        ->status->toBe('ringing')
        ->caller->toBe('+998901112233')
        ->callee->toBe("dr{$driver->id}")
        ->client_id->toBe($client->id)
        ->order_id->toBe($order->id)
        ->operator_id->toBeNull()
        ->sip_trunk_id->toBeNull();
    // Mijoz gudok eshitadi; haydovchi ilovasida — kiruvchi qo'ng'iroq, mijoz ismi bilan
    expect(ariRequests('/ari/channels/ch-cl/ring'))->toHaveCount(1);
    Event::assertDispatched(IncomingCall::class, fn (IncomingCall $e) => $e->driverId === $driver->id && $e->callId === $call->id && $e->operator === 'Mijoz: Ali');

    $this->center->handle(stasis('ch-dr', ['internal', "answer-{$call->id}"], "PJSIP/dr{$driver->id}-0001", "dr{$driver->id}"));
    expect($call->refresh()->status)->toBe('answered')
        ->and(ariRequests('/ari/channels/ch-cl/answer'))->toHaveCount(1)
        ->and($call->meta['bridge'])->toBe('bridge-1');

    // Suhbat tugadi — vaqtinchalik raqam o'chiriladi
    $this->center->handle(['type' => 'StasisEnd', 'channel' => ['id' => 'ch-cl']]);
    expect($call->refresh()->status)->toBe('completed')
        ->and(PsEndpoint::query()->find("cl{$client->id}"))->toBeNull()
        ->and(PsAuth::query()->find("cl{$client->id}"))->toBeNull();
});

test('driver declining the client app call gives the client a busy signal', function () {
    [$client, $driver] = clientOnTrip($this->branch);
    clientGrant($client, 'driver');
    $this->center->handle(stasis('ch-cl', ['client', 'driver'], "PJSIP/cl{$client->id}-0001", "cl{$client->id}"));
    $call = Call::query()->withoutGlobalScopes()->sole();

    Sanctum::actingAs($driver->user, ['driver']);
    $this->postJson("/api/driver/v1/phone/calls/{$call->id}/decline")->assertOk();

    expect($call->refresh()->status)->toBe('declined')
        ->and(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/ari/channels/ch-cl') && $r['reason'] === 'busy')->count())->toBe(1)
        ->and(PsEndpoint::query()->find("cl{$client->id}"))->toBeNull();
});

test('client app call to the dispatcher queues for operators without using a trunk line', function () {
    $client = Client::factory()->create(['phone' => '+998901112233', 'last_branch_id' => $this->branch->id]);
    clientGrant($client, 'dispatcher')->assertOk();

    $this->center->handle(stasis('ch-cl', ['client', 'dispatcher'], "PJSIP/cl{$client->id}-0001", "cl{$client->id}"));

    $call = Call::query()->withoutGlobalScopes()->sole();
    expect($call)
        ->kind->toBe('client')
        ->direction->toBe('inbound')
        ->status->toBe('waiting')
        ->caller->toBe('+998901112233')
        ->client_id->toBe($client->id)
        ->branch_id->toBe($this->branch->id)
        ->sip_trunk_id->toBeNull()
        ->operator_id->toBe($this->operator->id);
    // Salomlashuv emas — gudok; operator kartochkasida mijoz telefoni
    expect(ariRequests('media=tone:ring;tonezone=ru'))->toHaveCount(1)
        ->and(app(ChannelLimits::class)->active($this->trunk)['total'])->toBe(0);
    Event::assertDispatched(OperatorCallEvent::class, fn ($e) => $e->type === 'incoming' && $e->payload['phone'] === '+998901112233');

    Sanctum::actingAs($client, ['client']);
    $this->getJson('/api/client/v1/calls/current')->assertJsonPath('status', 'waiting');
});

test('the client app grant is single-use and bound to its target', function () {
    [$client] = clientOnTrip($this->branch);
    clientGrant($client, 'driver');

    // Haydovchiga ruxsat bilan dispetcherga emas
    $this->center->handle(stasis('ch-1', ['client', 'dispatcher'], "PJSIP/cl{$client->id}-0001", "cl{$client->id}"));
    // Ruxsat ishlatildi — qayta qo'ng'iroq ham yo'q
    $this->center->handle(stasis('ch-2', ['client', 'driver'], "PJSIP/cl{$client->id}-0002", "cl{$client->id}"));

    expect(Call::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/ari/channels/ch-'))->count())->toBe(2)
        ->and(PsEndpoint::query()->find("cl{$client->id}"))->toBeNull();
    Event::assertNotDispatched(IncomingCall::class);
});

test('the driver can be called from the app only during an active trip', function () {
    [$client, , $order] = clientOnTrip($this->branch, OrderStatus::Searching);
    clientGrant($client, 'driver')->assertUnprocessable()->assertJsonValidationErrors('target');

    $order->forceFill(['status' => OrderStatus::Completed])->save();
    clientGrant($client, 'driver')->assertUnprocessable();

    // Ruxsatdan keyin safar tugasa — Asterisk'dan kelgan qo'ng'iroq rad etiladi
    $order->forceFill(['status' => OrderStatus::Arrived])->save();
    clientGrant($client, 'driver')->assertOk();
    $order->forceFill(['status' => OrderStatus::Completed])->save();
    $this->center->handle(stasis('ch-cl', ['client', 'driver'], "PJSIP/cl{$client->id}-0001", "cl{$client->id}"));

    expect(Call::query()->withoutGlobalScopes()->count())->toBe(0);
    Event::assertNotDispatched(IncomingCall::class);
});

test('client app calls can be turned off and only one runs at a time', function () {
    [$client] = clientOnTrip($this->branch);
    app(Settings::class)->set('calls.client_app_calls', false);
    clientGrant($client, 'dispatcher')->assertUnprocessable();
    Sanctum::actingAs($client, ['client']);
    $this->getJson('/api/client/v1/me')->assertJsonPath('config.app_calls', false);

    app(Settings::class)->set('calls.client_app_calls', true);
    clientGrant($client, 'driver')->assertOk();
    $this->center->handle(stasis('ch-cl', ['client', 'driver'], "PJSIP/cl{$client->id}-0001", "cl{$client->id}"));
    clientGrant($client, 'dispatcher')->assertUnprocessable();
});

test('unused temporary client extensions are pruned after the grant expires', function () {
    [$client] = clientOnTrip($this->branch);
    clientGrant($client, 'driver')->assertOk();
    $this->ari['channels'] = [];

    $this->center->reconcile();
    expect(PsEndpoint::query()->find("cl{$client->id}"))->not->toBeNull();

    $this->travel(61)->seconds();
    $this->center->reconcile();
    expect(PsEndpoint::query()->find("cl{$client->id}"))->toBeNull();
});
