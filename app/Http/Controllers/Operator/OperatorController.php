<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Branch;
use App\Models\Driver;
use App\Models\Order;
use App\Models\TariffClass;
use App\Models\User;
use App\Support\Settings;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator paneli (reja §8.1): faol buyurtmalar, xarita, yangi buyurtma formasi.
 */
class OperatorController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $branches = Branch::query()
            ->when(! $user->isSuperadmin(), fn ($q) => $q->whereIn('id', $user->branchIds()))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return Inertia::render('operator/index', [
            'branches' => $branches->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'center' => $branch->center ? ['lat' => $branch->center->getLatitude(), 'lng' => $branch->center->getLongitude()] : null,
            ]),
            'tariffClasses' => TariffClass::query()->whereIn('branch_id', $branches->pluck('id'))->where('is_active', true)
                ->orderBy('sort')->get(['id', 'branch_id', 'name', 'icon', 'is_delivery', 'is_default']),
            'orders' => fn () => OrderResource::collection(
                Order::query()->active()->whereIn('branch_id', $branches->pluck('id'))
                    ->with(['client', 'driver.user', 'vehicle', 'tariffClass'])
                    ->latest()->limit(200)->get(),
            )->resolve(),
            'drivers' => fn () => $this->drivers(array_values($branches->map(fn (Branch $branch): int => $branch->id)->all())),
            'map' => [
                'tiles' => config('services.tiles.url'),
                'center' => $this->fallbackCenter(array_values($branches->all())),
            ],
            // Shundan eski GPS'li haydovchi "bo'sh" deb sanalmaydi va tayinlash ro'yxatida chiqmaydi
            'locationTtlSeconds' => (int) app(Settings::class)->get('dispatch.location_ttl_seconds'),
        ]);
    }

    /**
     * Xarita markazi filial markazi yo'q bo'lsa: koddagi biror shahar emas (mahsulot har firmaga sotiladi) —
     * filial hududi (viloyat/shahar) markazi yoki boshqa filial markazi. Topilmasa null — panel xarita fayli
     * qamragan hududni ko'rsatadi (istalgan import qilingan viloyat xarita tayyorlanmagan joy bo'lishi mumkin).
     *
     * @param  list<Branch>  $branches
     * @return array{lat: float, lng: float}|null
     */
    private function fallbackCenter(array $branches): ?array
    {
        $point = collect($branches)->map(fn (Branch $b) => $b->center ?? $b->region()->first()?->center)->filter()->first()
            ?? Branch::query()->whereNotNull('center')->orderBy('id')->first()?->center;

        return $point instanceof Point ? ['lat' => $point->getLatitude(), 'lng' => $point->getLongitude()] : null;
    }

    /**
     * Xarita uchun haydovchilar: bo'sh / band / oflayn.
     *
     * @param  list<int>  $branchIds
     * @return list<array<string, mixed>>
     */
    private function drivers(array $branchIds): array
    {
        return array_values(Driver::query()->whereIn('branch_id', $branchIds)
            ->whereNotNull('last_location')
            ->where('last_seen_at', '>=', now()->subHours(2))
            ->with(['user:id,name,phone', 'vehicle'])
            ->get()
            ->map(fn (Driver $driver) => [
                'id' => $driver->id,
                'callsign' => $driver->callsign,
                'name' => $driver->user->name,
                'status' => $driver->status->value,
                'lat' => $driver->last_location?->getLatitude(),
                'lng' => $driver->last_location?->getLongitude(),
                'vehicle' => $driver->vehicle ? trim("{$driver->vehicle->color} {$driver->vehicle->model} {$driver->vehicle->plate_number}") : null,
                'last_seen_at' => $driver->last_seen_at?->toIso8601String(),
            ])
            ->all());
    }
}
