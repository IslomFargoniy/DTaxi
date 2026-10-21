<?php

use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Events\DriverLocationUpdated;
use App\Events\OfferWithdrawn;
use App\Events\OrderOffered;
use App\Events\OrderUpdated;
use App\Jobs\ExpireDirectOffer;
use App\Models\Branch;
use App\Models\CashShift;
use App\Models\DriverTransaction;
use App\Models\Order;
use App\Models\User;
use App\Services\Dispatch\Dispatcher;
use App\Services\Dispatch\DriverDiscipline;
use App\Services\Drivers\DriverBalance;
use App\Services\Orders\OrderService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/../Feature/Orders/helpers.php';
require_once __DIR__.'/helpers.php';

uses(TestCase::class, DatabaseTruncation::class);

/*
 * Pul va holat o'zgarishlari haqiqiy parallel jarayonlarda ham faqat bir marta bajarilishi (reja §6).
 * Har testda oxirida balans tarixi bilan mos ekani (DriverBalance::mismatches) ham tekshiriladi.
 */
beforeEach(function () {
    Queue::fake();
    // Model hodisalari ishlashi kerak — faqat broadcast'larni o'chiramiz
    Event::fake([OrderOffered::class, OrderUpdated::class, OfferWithdrawn::class, DriverLocationUpdated::class]);
    Http::fake();
    $this->branch = Branch::factory()->create();
});

test('excusing the same penalty from several admins at once refunds exactly once', function () {
    $driver = driverNear($this->branch, 300, ['balance' => 50000]);
    $order = app(OrderService::class)->create(orderData($this->branch));
    app(Dispatcher::class)->accept($order, $driver);
    app(OrderService::class)->cancelByDriver($order->fresh(), $driver->fresh(), 'test');
    $penalty = $driver->transactions()->where('type', TransactionType::Penalty)->sole();
    $admin = User::factory()->create();
    expect($driver->fresh()->balance)->toBe(48000);

    $codes = inParallel(array_fill(0, 6, function () use ($penalty, $admin) {
        app(DriverDiscipline::class)->excuse(DriverTransaction::query()->findOrFail($penalty->id), $admin);

        return true;
    }));

    sort($codes);
    expect($codes)->toBe([0, 1, 1, 1, 1, 1])
        ->and($driver->fresh()->balance)->toBe(50000)
        ->and($driver->fresh()->rating)->toBe(100)
        ->and($driver->transactions()->where('type', TransactionType::Bonus)->count())->toBe(1)
        ->and(app(DriverBalance::class)->mismatches())->toBe([]);
});

test('driver rejecting a direct offer while the expiry job runs is penalised exactly once', function () {
    $operator = User::factory()->create();
    driverNear($this->branch, 2000); // efirga qaytgan buyurtma kimgadir yetsin

    foreach (range(1, 5) as $round) {
        $driver = driverNear($this->branch, 300, ['balance' => 50000]);
        $order = app(OrderService::class)->create(orderData($this->branch), dispatch: false);
        $offer = app(Dispatcher::class)->offerDirect($order->fresh(), $driver, $operator);

        $codes = inParallel([
            function () use ($order, $driver, $round) {
                // Raund sayin siljitiladi — rad etish muddatdan oldin ham, keyin ham tushadi
                usleep(($round - 1) * 2000);
                app(Dispatcher::class)->reject(Order::query()->withoutGlobalScopes()->findOrFail($order->id), $driver->fresh());

                return true;
            },
            function () use ($offer) {
                (new ExpireDirectOffer($offer->id))->handle(app(Dispatcher::class), app(DriverDiscipline::class));

                return true;
            },
        ]);

        expect($codes)->toBe([0, 0])
            ->and($driver->transactions()->where('type', TransactionType::Penalty)->count())->toBe(1, "raund {$round}")
            ->and($driver->fresh()->balance)->toBe(48000)
            ->and($driver->fresh()->status)->toBe(DriverStatus::Free)
            ->and($order->fresh()->status)->toBe(OrderStatus::Searching)
            // Efir bir marta qayta boshlandi (aks holda ikki raund — haydovchilarga ikki marta taklif)
            ->and($order->fresh()->search_round)->toBe(2)
            ->and($order->offers()->where('driver_id', $driver->id)->whereNull('response')->exists())->toBeFalse();
    }

    expect(app(DriverBalance::class)->mismatches())->toBe([]);
});

test('a double-submitted cash top-up with the same request id is added exactly once', function () {
    // Massiv kesh har jarayonda alohida — idempotentlik kaliti umumiy (atomik add) omborda bo'lishi kerak, prod'da Redis
    config(['cache.default' => 'database']);
    $this->seed(RolesSeeder::class);
    $cashier = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Cashier);
    $driver = driverNear($this->branch, 0, ['balance' => 0]);
    $this->actingAs($cashier)->post(route('cashier.shift.open'));
    $requestId = (string) Str::uuid();

    $codes = inParallel(array_fill(0, 5, function () use ($driver, $requestId) {
        $this->post(route('cashier.topup'), ['driver_id' => $driver->id, 'amount' => 50000, 'request_id' => $requestId])
            ->assertSessionHasNoErrors();

        return true;
    }));

    expect($codes)->each->toBe(0)
        ->and($driver->fresh()->balance)->toBe(50000)
        ->and($driver->transactions()->count())->toBe(1)
        ->and(CashShift::query()->sole()->total_cash)->toBe(50000)
        ->and(app(DriverBalance::class)->mismatches())->toBe([]);
});

test('operator cancelling a pre-order while the scheduler dispatches it leaves it cancelled with no live offers', function () {
    $operator = User::factory()->create();
    $drivers = collect(range(1, 3))->map(fn ($i) => driverNear($this->branch, 200 * $i));

    foreach (range(1, 10) as $round) {
        $order = app(OrderService::class)->create(orderData($this->branch, ['scheduled_at' => now()->addHour()]));
        expect($order->fresh()->status)->toBe(OrderStatus::New);
        // Vaqt bolalar jarayonlariga ham o'tadi (fork) — rejalashtiruvchi buyurtmani endi efirga chiqaradi
        $this->travel(50)->minutes();
        DB::table('drivers')->update(['last_seen_at' => now()]);

        $codes = inParallel([
            function () use ($order, $operator, $round) {
                // Har raundda bekor qilish biroz kechroq — efirga chiqish oldin, o'rtasida va keyin tushadi
                usleep(($round - 1) * 3000);
                app(OrderService::class)->cancelByOperator(Order::query()->withoutGlobalScopes()->findOrFail($order->id), $operator, 'Mijoz qo\'ng\'iroq qildi');

                return true;
            },
            fn () => app(OrderService::class)->dispatchScheduled() >= 0,
        ]);

        $order = $order->fresh();
        expect($codes)->toBe([0, 0])
            ->and($order->status)->toBe(OrderStatus::CancelledByOperator, "raund {$round}")
            ->and($order->offers()->whereNull('response')->count())->toBe(0, "raund {$round}: bekor qilingan buyurtmada ochiq taklif qoldi")
            ->and(DB::table('order_events')->where('order_id', $order->id)->where('type', 'searching')->count())->toBeLessThanOrEqual(1)
            ->and(DB::table('drivers')->whereIn('id', $drivers->pluck('id'))->where('status', '!=', DriverStatus::Free->value)->count())->toBe(0);
    }
});
