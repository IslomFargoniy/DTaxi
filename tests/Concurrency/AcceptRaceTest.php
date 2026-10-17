<?php

use App\Enums\OrderStatus;
use App\Events\OfferWithdrawn;
use App\Events\OrderOffered;
use App\Events\OrderUpdated;
use App\Models\Branch;
use App\Models\Order;
use App\Services\Dispatch\Dispatcher;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

require_once __DIR__.'/../Feature/Orders/helpers.php';

uses(TestCase::class, DatabaseTruncation::class);

/*
 * Reja §6: "parallel 10 ta qabul qilish so'rovi → faqat 1 tasi yutadi" — haqiqiy alohida jarayonlar
 * va alohida baza ulanishlari bilan.
 */
test('ten drivers accepting at the same moment produce exactly one winner', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl kerak');
    }

    Queue::fake();
    // Model hodisalari (filial yaratilganda tarif klasslari) ishlashi kerak — faqat broadcast'larni o'chiramiz
    Event::fake([OrderOffered::class, OrderUpdated::class, OfferWithdrawn::class]);
    Http::fake();

    $branch = Branch::factory()->create();
    $drivers = collect(range(1, 10))->map(fn ($i) => driverNear($branch, 100 * $i));
    $order = app(OrderService::class)->create(orderData($branch));
    expect($order->offers()->count())->toBe(10);

    DB::disconnect();
    $pids = [];
    foreach ($drivers as $driver) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::reconnect();
            $won = app(Dispatcher::class)->accept(Order::query()->withoutGlobalScopes()->find($order->id), $driver);
            exit($won ? 0 : 1);
        }
        $pids[] = $pid;
    }

    $winners = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $winners += pcntl_wexitstatus($status) === 0 ? 1 : 0;
    }
    DB::reconnect();

    $order = Order::query()->withoutGlobalScopes()->find($order->id);
    expect($winners)->toBe(1)
        ->and($order->status)->toBe(OrderStatus::Assigned)
        ->and(DB::table('drivers')->where('status', 'busy')->count())->toBe(1)
        ->and(DB::table('drivers')->where('status', 'busy')->value('id'))->toBe($order->driver_id);
});
