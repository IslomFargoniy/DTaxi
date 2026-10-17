<?php

namespace App\Console\Commands;

use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderOffer;
use App\Services\Dispatch\Dispatcher;
use App\Services\Drivers\DriverLocator;
use App\Services\Orders\OrderService;
use App\Services\Routing\Router;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;

/**
 * Lokal sinov (reja "Ish uslubi"): soxta haydovchilar OSRM marshrutlari bo'yicha yuradi, efirdan
 * buyurtma oladi va safarni yakunlaydi — operator panelini bitta odam to'liq sinay oladi.
 * Production'da ishlamaydi.
 */
class SimulateDrivers extends Command
{
    protected $signature = 'simulate:drivers
        {--branch= : Filial ID (standart — birinchi faol filial)}
        {--tick=4 : GPS yuborish oralig\'i, soniya}
        {--accept=70 : Taklifni qabul qilish ehtimoli, %}
        {--orders=0 : Har daqiqada soxta buyurtmalar soni (yuklama sinovi)}';

    protected $description = 'Soxta haydovchilar harakati va buyurtmalarni bajarish (faqat lokal sinov)';

    private bool $running = true;

    /** @var array<int, list<array{float, float}>> haydovchi → qolgan marshrut nuqtalari [lng, lat] */
    private array $paths = [];

    public function handle(DriverLocator $locator, Dispatcher $dispatcher, OrderService $orders, Router $router): int
    {
        if (App::isProduction()) {
            $this->error("Production'da ishlatilmaydi.");

            return self::FAILURE;
        }

        $branch = Branch::query()->when($this->option('branch'), fn ($q, $id) => $q->whereKey($id))->where('is_active', true)->firstOrFail();
        $center = $branch->center ?? Point::makeGeodetic(40.3864, 71.7864);
        $tick = max(1, (int) $this->option('tick'));
        $acceptChance = (int) $this->option('accept');
        $ordersPerMinute = (int) $this->option('orders');

        Driver::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('is_approved', true)
            ->where('status', DriverStatus::Offline->value)->update(['status' => DriverStatus::Free->value]);

        $this->info("Filial: {$branch->name}. To'xtatish: Ctrl+C");
        $lastOrderAt = 0;
        $this->trap([SIGINT, SIGTERM], function (): void {
            $this->running = false;
        });

        while ($this->running) {
            $drivers = Driver::query()->withoutGlobalScopes()->where('branch_id', $branch->id)
                ->whereIn('status', [DriverStatus::Free->value, DriverStatus::Busy->value])->get();

            foreach ($drivers as $driver) {
                $this->move($driver, $locator, $router, $center);
                $this->handleOffers($driver, $dispatcher, $acceptChance);
                $this->advanceOrder($driver, $orders, $router);
            }

            if ($ordersPerMinute > 0 && time() - $lastOrderAt >= 60 / $ordersPerMinute) {
                $lastOrderAt = time();
                $this->fakeOrder($branch, $center, $orders);
            }

            sleep($tick);
        }

        return self::SUCCESS;
    }

    private function move(Driver $driver, DriverLocator $locator, Router $router, Point $center): void
    {
        $current = $driver->last_location ?? $this->randomPoint($center, 2000);

        if (empty($this->paths[$driver->id])) {
            $route = $router->route($current, $this->randomPoint($center, 4000), withGeometry: true);
            $this->paths[$driver->id] = $route->geometry ?? [[$center->getLongitude(), $center->getLatitude()]];
        }

        // Har qadamda ~3 nuqta oldinga (shahar ichida ~30–40 km/soat)
        $next = array_splice($this->paths[$driver->id], 0, 3);
        $point = end($next) ?: [$current->getLongitude(), $current->getLatitude()];
        $locator->update($driver, $point[1], $point[0], speed: 35);
    }

    private function handleOffers(Driver $driver, Dispatcher $dispatcher, int $acceptChance): void
    {
        if ($driver->status !== DriverStatus::Free) {
            return;
        }

        $offer = OrderOffer::query()->where('driver_id', $driver->id)->whereNull('response')->latest('offered_at')->first();
        if (! $offer) {
            return;
        }

        $order = Order::query()->withoutGlobalScopes()->findOrFail($offer->order_id);
        if (random_int(1, 100) <= $acceptChance && $dispatcher->accept($order, $driver)) {
            $this->line("  {$driver->callsign} → #{$order->id} qabul qildi");
            // Mijozga qarab yo'l olish
            unset($this->paths[$driver->id]);
        }
    }

    private function advanceOrder(Driver $driver, OrderService $orders, Router $router): void
    {
        $order = Order::query()->withoutGlobalScopes()->where('driver_id', $driver->id)
            ->whereIn('status', [OrderStatus::Assigned->value, OrderStatus::Arrived->value, OrderStatus::InProgress->value])->first();
        if (! $order || ! $driver->last_location) {
            return;
        }

        $target = $order->status === OrderStatus::InProgress ? ($order->dropoff_location ?? $order->pickup_location) : $order->pickup_location;
        $distance = Router::haversine($driver->last_location, $target);

        if (empty($this->paths[$driver->id])) {
            $this->paths[$driver->id] = $router->route($driver->last_location, $target, withGeometry: true)->geometry ?? [];
        }

        match (true) {
            $order->status === OrderStatus::Assigned && $distance < 150 => $orders->arrive($order, $driver),
            $order->status === OrderStatus::Arrived && $order->arrived_at?->diffInSeconds(now()) > 20 => $orders->start($order, $driver),
            $order->status === OrderStatus::InProgress && $distance < 150 => $orders->complete(
                $order,
                $driver,
                $order->distance_m ?? (int) ($distance + 3000),
            ),
            default => null,
        };
    }

    private function fakeOrder(Branch $branch, Point $center, OrderService $orders): void
    {
        $pickup = $this->randomPoint($center, 3000);
        $dropoff = $this->randomPoint($center, 5000);

        $order = $orders->create([
            'branch_id' => $branch->id,
            'phone' => '9'.random_int(10000000, 99999999),
            'pickup_text' => 'Sinov manzili',
            'pickup_lat' => $pickup->getLatitude(),
            'pickup_lng' => $pickup->getLongitude(),
            'dropoff_text' => 'Sinov manzili 2',
            'dropoff_lat' => $dropoff->getLatitude(),
            'dropoff_lng' => $dropoff->getLongitude(),
        ]);
        $this->line("+ soxta buyurtma #{$order->id}");
    }

    private function randomPoint(Point $center, int $radiusMeters): Point
    {
        $angle = random_int(0, 359) * M_PI / 180;
        $distance = random_int(100, $radiusMeters) / 111_000;

        return Point::makeGeodetic(
            $center->getLatitude() + $distance * cos($angle),
            $center->getLongitude() + $distance * sin($angle) / cos(deg2rad($center->getLatitude())),
        );
    }
}
