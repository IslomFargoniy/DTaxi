<?php

use App\Enums\DriverStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Events\ClientDriverMoved;
use App\Events\ClientOrderUpdated;
use App\Events\DriverLocationUpdated;
use App\Events\OfferWithdrawn;
use App\Events\OrderOffered;
use App\Events\OrderUpdated;
use App\Jobs\PlaceAutoCall;
use App\Jobs\PlaceCodeCall;
use App\Jobs\SendClientPush;
use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\ClientDeviceToken;
use App\Models\Order;
use App\Models\User;
use App\Services\Drivers\DriverLocator;
use App\Services\Orders\OrderEvents;
use App\Services\Orders\OrderService;
use App\Services\Push\FcmPushNotifier;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../Orders/helpers.php';

beforeEach(function () {
    Queue::fake();
    Event::fake([OrderOffered::class, OrderUpdated::class, OfferWithdrawn::class, DriverLocationUpdated::class, ClientOrderUpdated::class, ClientDriverMoved::class]);
    Http::fake();
    $this->branch = Branch::factory()->create(['center' => Point::makeGeodetic(40.3864, 71.7864)]);
    // Xizmat hududi — markazdan 10 km
    DB::update('UPDATE branches SET service_area = ST_Multi(ST_Buffer(center, 10000)::geometry)::geography WHERE id = ?', [$this->branch->id]);
    $this->pickup = ['lat' => 40.3870, 'lng' => 71.7870, 'text' => 'Mustaqillik maydoni'];
});

function actingClient(?Client $client = null): Client
{
    $client ??= Client::factory()->create(['phone' => '+998901112233', 'name' => 'Ali']);
    Sanctum::actingAs($client, ['client']);

    return $client;
}

/** Kod qo'ng'iroq navbatiga tushadi — test uni shu yerdan oladi */
function requestedCode(string $phone): string
{
    $code = null;
    Queue::assertPushed(PlaceCodeCall::class, function (PlaceCodeCall $job) use ($phone, &$code) {
        if ($job->phone === $phone) {
            $code = $job->code;
        }

        return $job->phone === $phone;
    });

    return (string) $code;
}

test('client logs in with the code said in a phone call and gets a client-only token', function () {
    $this->postJson('/api/client/v1/auth/request-code', ['phone' => '90 111 22 33'])->assertStatus(202)->assertJsonPath('expires_in', 300);
    $code = requestedCode('+998901112233');
    expect($code)->toMatch('/^\d{4}$/');

    $this->postJson('/api/client/v1/auth/verify', ['phone' => '90 111 22 33', 'code' => $code === '0000' ? '1111' : '0000', 'device_name' => 'Redmi'])
        ->assertStatus(422)->assertJsonValidationErrors('code');
    $token = $this->postJson('/api/client/v1/auth/verify', ['phone' => '+998901112233', 'code' => $code, 'device_name' => 'Redmi'])
        ->assertOk()->assertJsonPath('client.phone', '+998901112233')->assertJsonPath('client.needs_name', true)->assertJsonPath('client.locale', 'uz')
        ->json('token');

    // Kod bir martalik
    $this->postJson('/api/client/v1/auth/verify', ['phone' => '+998901112233', 'code' => $code, 'device_name' => 'Redmi'])->assertStatus(422);

    $this->withToken($token)->getJson('/api/client/v1/me')->assertOk()->assertJsonPath('phone', '+998901112233');
    // Mijoz tokeni haydovchi API'siga kirmaydi
    $this->withToken($token)->getJson('/api/driver/v1/me')->assertForbidden();
    expect(Client::query()->where('phone', '+998901112233')->sole()->app_login_at)->not->toBeNull();
});

test('a code is cancelled after five wrong attempts and requests are rate limited per phone', function () {
    $this->postJson('/api/client/v1/auth/request-code', ['phone' => '901112233'])->assertStatus(202);
    $code = requestedCode('+998901112233');
    $wrong = $code === '1234' ? '4321' : '1234';
    foreach (range(1, 5) as $_) {
        $this->postJson('/api/client/v1/auth/verify', ['phone' => '901112233', 'code' => $wrong, 'device_name' => 'x'])->assertStatus(422);
    }
    $this->postJson('/api/client/v1/auth/verify', ['phone' => '901112233', 'code' => $code, 'device_name' => 'x'])->assertStatus(422);

    $this->postJson('/api/client/v1/auth/request-code', ['phone' => '901112233'])->assertStatus(202);
    $this->postJson('/api/client/v1/auth/request-code', ['phone' => '901112233'])->assertStatus(202);
    $this->postJson('/api/client/v1/auth/request-code', ['phone' => '901112233'])->assertStatus(429);
    RateLimiter::clear('phone:+998901112233');
});

test('demo test code works only when configured', function () {
    $this->postJson('/api/client/v1/auth/verify', ['phone' => '901112233', 'code' => '7777', 'device_name' => 'x'])->assertStatus(422);

    config(['services.client_login.test_code' => '7777']);
    $this->postJson('/api/client/v1/auth/verify', ['phone' => '901112233', 'code' => '7777', 'device_name' => 'x'])->assertOk();
});

test('quote lists tariff prices for the branch serving the pickup, outside the area is refused', function () {
    actingClient();

    $this->postJson('/api/client/v1/quote', ['pickup' => $this->pickup, 'dropoff' => ['lat' => 40.40, 'lng' => 71.80]])
        ->assertOk()->assertJsonPath('branch_id', $this->branch->id)
        ->assertJsonPath('tariffs.0.price', fn ($price) => is_int($price) && $price > 0)
        ->assertJsonPath('tariffs.0.min_price', fn ($price) => is_int($price) && $price > 0);
    // "Qayerga" yo'q — narx yo'q, faqat eng kami
    $this->postJson('/api/client/v1/quote', ['pickup' => $this->pickup])->assertOk()->assertJsonPath('tariffs.0.price', null);

    $this->postJson('/api/client/v1/quote', ['pickup' => ['lat' => 41.3, 'lng' => 69.2]])->assertStatus(422)->assertJsonValidationErrors('pickup');
});

test('client orders a taxi from the app: one active order at a time, no voice calls, own channel updates', function () {
    $client = actingClient();

    $id = $this->postJson('/api/client/v1/orders', ['pickup' => $this->pickup, 'comment' => 'Podyezd 2'])
        ->assertCreated()->assertJsonPath('status', fn ($s) => in_array($s, ['new', 'searching'], true))
        ->assertJsonPath('can_cancel', true)->assertJsonPath('driver', null)
        ->json('id');

    $order = Order::query()->withoutGlobalScopes()->findOrFail($id);
    expect($order->source)->toBe(OrderSource::App)
        ->and($order->client_id)->toBe($client->id)
        ->and($order->branch_id)->toBe($this->branch->id)
        ->and($order->comment)->toBe('Podyezd 2');

    $this->postJson('/api/client/v1/orders', ['pickup' => $this->pickup])->assertStatus(409)->assertJsonPath('order.id', $id);
    $this->getJson('/api/client/v1/orders/current')->assertOk()->assertJsonPath('order.id', $id);

    // Haydovchi biriktirildi — mijoz kanaliga holat, ovozli qo'ng'iroq yo'q
    $driver = driverNear($this->branch, 100);
    $order->forceFill(['status' => OrderStatus::Assigned, 'driver_id' => $driver->id, 'vehicle_id' => $driver->vehicle?->id, 'assigned_at' => now()])->save();
    OrderEvents::record($order, 'assigned');
    Event::assertDispatched(ClientOrderUpdated::class, fn (ClientOrderUpdated $e) => $e->order->id === $id
        && $e->broadcastOn()[0]->name === "private-client.{$client->id}"
        && ($e->broadcastWith()['order']['driver']['callsign'] ?? null) === $driver->callsign
        && ! array_key_exists('commission', $e->broadcastWith()['order']));
    Queue::assertNotPushed(PlaceAutoCall::class);
});

test('blacklisted client cannot order from the app', function () {
    actingClient(Client::factory()->create(['is_blacklisted' => true]));

    $this->postJson('/api/client/v1/orders', ['pickup' => $this->pickup])->assertForbidden();
    expect(Order::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('client cancels own order, cannot touch others, and rates a completed trip', function () {
    $client = actingClient();
    $other = Order::factory()->create(['branch_id' => $this->branch->id, 'status' => OrderStatus::Searching]);
    $mine = Order::factory()->create(['branch_id' => $this->branch->id, 'client_id' => $client->id, 'status' => OrderStatus::Searching, 'source' => OrderSource::App]);

    $this->postJson("/api/client/v1/orders/{$other->id}/cancel")->assertNotFound();
    $this->postJson("/api/client/v1/orders/{$mine->id}/cancel", ['reason' => 'Rejalar o\'zgardi'])->assertOk()->assertJsonPath('status', 'cancelled_by_client');
    $this->postJson("/api/client/v1/orders/{$mine->id}/cancel")->assertStatus(422);

    $driver = driverNear($this->branch, 0);
    $done = Order::factory()->create([
        'branch_id' => $this->branch->id, 'client_id' => $client->id, 'driver_id' => $driver->id, 'source' => OrderSource::App,
        'status' => OrderStatus::Completed, 'completed_at' => now(),
    ]);
    $this->getJson('/api/client/v1/orders/current')->assertJsonPath('order', null)->assertJsonPath('to_rate.id', $done->id);
    $this->postJson("/api/client/v1/orders/{$done->id}/rate", ['rating' => 5])->assertOk()->assertJsonPath('rating', 5);
    $this->getJson('/api/client/v1/orders/current')->assertJsonPath('to_rate', null);
    $this->postJson("/api/client/v1/orders/{$other->id}/rate", ['rating' => 1])->assertNotFound();

    $this->getJson('/api/client/v1/orders')->assertOk()->assertJsonCount(2);
});

test('saved addresses belong to the client', function () {
    $client = actingClient();
    $foreign = ClientAddress::query()->create(['client_id' => Client::factory()->create()->id, 'text' => 'Begona', 'location' => Point::makeGeodetic(40.38, 71.78)]);

    $id = $this->postJson('/api/client/v1/addresses', ['label' => 'Uy', 'text' => 'Yangi Asr ko\'chasi, 100', 'lat' => 40.39, 'lng' => 71.79])
        ->assertCreated()->assertJsonPath('label', 'Uy')->json('id');
    $this->getJson('/api/client/v1/addresses')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $id);

    $this->deleteJson("/api/client/v1/addresses/{$foreign->id}")->assertNoContent();
    expect(ClientAddress::query()->whereKey($foreign->id)->exists())->toBeTrue();
    $this->deleteJson("/api/client/v1/addresses/{$id}")->assertNoContent();
    expect(ClientAddress::query()->where('client_id', $client->id)->count())->toBe(0);
});

test('client may join only its own private channel', function () {
    $client = Client::factory()->create();
    // Testda broadcast drayveri "null" (har kanalga ruxsat) — kanal sharti to'g'ridan-to'g'ri tekshiriladi
    $authorize = Broadcast::driver()->getChannels()->get('client.{clientId}');

    expect($authorize($client, $client->id))->toBeTrue()
        ->and($authorize($client, $client->id + 1))->toBeFalse()
        ->and($authorize(User::factory()->create(), $client->id))->toBeFalse();
});

test('the assigned driver moving is sent to the client of an app order only', function () {
    $client = Client::factory()->create();
    $driver = driverNear($this->branch, 0, ['status' => DriverStatus::Busy]);
    $order = Order::factory()->create([
        'branch_id' => $this->branch->id, 'client_id' => $client->id, 'driver_id' => $driver->id,
        'status' => OrderStatus::Assigned, 'source' => OrderSource::App,
    ]);

    app(DriverLocator::class)->update($driver, 40.39, 71.79, heading: 90);
    Event::assertDispatched(ClientDriverMoved::class, fn (ClientDriverMoved $e) => $e->clientId === $client->id && $e->orderId === $order->id && $e->heading === 90);

    $order->forceFill(['source' => OrderSource::Phone])->save();
    Event::fake([ClientDriverMoved::class]);
    app(DriverLocator::class)->update($driver, 40.391, 71.791);
    Event::assertNotDispatched(ClientDriverMoved::class);
});

test('order service quote matches the price of the created order', function () {
    $client = Client::factory()->create();
    $pickup = Point::makeGeodetic(40.3870, 71.7870);
    $dropoff = Point::makeGeodetic(40.40, 71.80);
    $quote = app(OrderService::class)->quote($this->branch, $client, $pickup, [], $dropoff);

    $order = app(OrderService::class)->create([
        'branch_id' => $this->branch->id, 'phone' => $client->phone, 'tariff_class_id' => $quote[0]['tariff_class_id'],
        'pickup_text' => 'A', 'pickup_lat' => 40.3870, 'pickup_lng' => 71.7870, 'dropoff_text' => 'B', 'dropoff_lat' => 40.40, 'dropoff_lng' => 71.80,
    ], dispatch: false);

    expect($order->price_estimate)->toBe($quote[0]['price']);
});

test('app order events send a push in the client language, phone orders do not', function () {
    $client = Client::factory()->create(['locale' => 'ru']);
    $driver = driverNear($this->branch, 0);
    $app = Order::factory()->create([
        'branch_id' => $this->branch->id, 'client_id' => $client->id, 'driver_id' => $driver->id, 'vehicle_id' => $driver->vehicle?->id,
        'status' => OrderStatus::Arrived, 'source' => OrderSource::App,
    ]);
    $phone = Order::factory()->create(['branch_id' => $this->branch->id, 'status' => OrderStatus::Arrived]);

    OrderEvents::record($app, 'arrived');
    OrderEvents::record($phone, 'arrived');
    OrderEvents::record($app, 'started');

    Queue::assertPushed(SendClientPush::class, 1);
    Queue::assertPushed(SendClientPush::class, fn (SendClientPush $job) => $job->orderId === $app->id && $job->type === 'arrived');

    $this->mock(FcmPushNotifier::class)->shouldReceive('sendToClient')->once()
        ->withArgs(fn (int $id, string $title, string $body, array $data) => $id === $client->id && $title === 'Водитель на месте'
            && str_contains($body, $driver->vehicle->plate_number) && $data === ['order_id' => $app->id, 'type' => 'arrived'])
        ->andReturn(1);
    (new SendClientPush($app->id, 'arrived'))->handle(app(FcmPushNotifier::class));
});

test('client registers a push token, a phone moving to another client takes the token along', function () {
    $first = actingClient();
    $this->postJson('/api/client/v1/device-token', ['token' => 'fcm-1'])->assertNoContent();

    $second = actingClient(Client::factory()->create());
    $this->postJson('/api/client/v1/device-token', ['token' => 'fcm-1'])->assertNoContent();

    expect(ClientDeviceToken::query()->where('token', 'fcm-1')->sole()->client_id)->toBe($second->id)
        ->and(ClientDeviceToken::query()->where('client_id', $first->id)->exists())->toBeFalse();
});
