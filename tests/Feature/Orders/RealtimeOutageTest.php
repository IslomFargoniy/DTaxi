<?php

use App\Enums\OrderStatus;
use App\Jobs\ExpandOrderSearch;
use App\Models\Branch;
use App\Services\Dispatch\Dispatcher;
use App\Services\Orders\OrderService;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

// Reverb o'chiq: har bir realtime xabar xato beradi
beforeEach(function () {
    Queue::fake();
    Http::fake();
    Broadcast::extend('down', fn () => new class extends Broadcaster
    {
        public function auth($request): mixed
        {
            return null;
        }

        public function validAuthenticationResponse($request, $result): mixed
        {
            return null;
        }

        /**
         * @param  array<mixed>  $channels
         * @param  array<mixed>  $payload
         */
        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new BroadcastException('Reverb is down');
        }
    });
    config(['broadcasting.connections.down' => ['driver' => 'down'], 'broadcasting.default' => 'down']);
    $this->branch = Branch::factory()->create();
});

test('with realtime down an order still goes on air, keeps expanding and can be accepted', function () {
    $driver = driverNear($this->branch, 300, ['balance' => 50000]);

    $order = app(OrderService::class)->create(orderData($this->branch));

    expect($order->fresh()->status)->toBe(OrderStatus::Searching)
        ->and($order->offers()->where('driver_id', $driver->id)->exists())->toBeTrue();
    Queue::assertPushed(ExpandOrderSearch::class);

    expect(app(Dispatcher::class)->accept($order->fresh(), $driver->fresh()))->toBeTrue()
        ->and($order->fresh()->status)->toBe(OrderStatus::Assigned);
});
