<?php

use App\Console\Commands\AriListen;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Events\CallUpdated;
use App\Events\OperatorCallEvent;
use App\Events\OrderUpdated;
use App\Jobs\PlaceAutoCall;
use App\Jobs\ProcessRecording;
use App\Models\Branch;
use App\Models\Call;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\PhoneNumber;
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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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
            $get && str_ends_with($url, '/ari/channels') => $ari['channels'] === null ? Http::response(null, 500) : Http::response(array_map(fn ($id) => ['id' => $id], $ari['channels'])),
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
    Queue::fake([ProcessRecording::class, PlaceAutoCall::class]);
    Event::fake([OperatorCallEvent::class, CallUpdated::class, OrderUpdated::class]);
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

    expect(Call::query()->withoutGlobalScopes()->where('channel_id', 'ch-2')->sole())
        ->status->toBe('rejected')
        ->meta->toMatchArray(['reason' => 'channels']);
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
        ->and(ariRequests('media=tone:ring;tonezone=ru'))->toHaveCount(1);
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
        ->and($call->meta['media'])->toBe(['sound:ibtaxi/phrase/cancelled'])
        ->and(ariRequests('appArgs=tts,'.$call->id))->toHaveCount(1);

    $this->center->handle(['type' => 'ChannelDestroyed', 'channel' => ['id' => $call->channel_id], 'cause' => 19]);

    expect($call->refresh()->status)->toBe('no_answer');
    Queue::assertPushed(PlaceAutoCall::class, fn ($job) => $job->orderId === $order->id && $job->attempt === 2);
});

test('answered auto call plays the message twice and hangs up', function () {
    $order = Order::factory()->create(['branch_id' => $this->branch->id]);
    $call = Call::query()->create([
        'channel_id' => 'ch-tts', 'branch_id' => $this->branch->id, 'direction' => 'outbound', 'kind' => 'tts',
        'order_id' => $order->id, 'status' => 'ringing', 'started_at' => now(), 'meta' => ['message' => 'arrived', 'media' => ['sound:ibtaxi/phrase/driver-waiting']],
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
        'order_id' => $order->id, 'status' => 'ringing', 'started_at' => now(), 'meta' => ['message' => 'rate', 'media' => ['sound:ibtaxi/phrase/rate-trip']],
    ]);
    $this->center->remember('ch-rate', $call, 'client');
    $this->center->handle(stasis('ch-rate', ['tts', (string) $call->id]));

    $this->center->handle(['type' => 'ChannelDtmfReceived', 'channel' => ['id' => 'ch-rate'], 'digit' => '2']);
    $this->center->handle(['type' => 'ChannelDtmfReceived', 'channel' => ['id' => 'ch-rate'], 'digit' => '5']);

    expect($order->fresh()->client_rating)->toBe(2)
        ->and($driver->fresh()->rating)->toBe(85);
});

test('operator calls a driver by typing the callsign', function () {
    $driver = driverNear($this->branch, 0, ['callsign' => '777']);

    $this->center->handle(stasis('ch-op', ['internal', '777'], "PJSIP/{$this->operator->sip_extension}-0001", $this->operator->sip_extension));

    $call = Call::query()->withoutGlobalScopes()->sole();
    expect($call->kind)->toBe('driver')
        ->and($call->driver_id)->toBe($driver->id)
        ->and(ariRequests("endpoint=PJSIP/dr{$driver->id}"))->toHaveCount(1);
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

test('the listener loop survives any exception', function () {
    app(AriListen::class)->guard(fn () => throw new RuntimeException('boom'));

    expect(true)->toBeTrue();
});
