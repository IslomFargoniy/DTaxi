<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Branch;
use App\Models\Driver;
use App\Models\Order;
use App\Models\TariffClass;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // Xizmat hududi xaritada chiziladi (filialda belgilangan radius yoki shahar chegarasi)
        $areas = DB::table('branches')->whereIn('id', $branches->pluck('id'))->whereNotNull('service_area')
            ->selectRaw('id, ST_AsGeoJSON(ST_SimplifyPreserveTopology(service_area::geometry, 0.0003), 5) as geojson')
            ->pluck('geojson', 'id')
            ->map(fn (mixed $json) => is_string($json) ? json_decode($json, true) : null);

        return Inertia::render('operator/index', [
            'branches' => $branches->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'center' => $branch->center ? ['lat' => $branch->center->getLatitude(), 'lng' => $branch->center->getLongitude()] : null,
                'area' => $areas[$branch->id] ?? null,
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
     * filial hududi markazi, boshqa filial markazi, so'nggi buyurtmalar olib ketish joylari yoki haydovchilar
     * turgan joy. Topilmasa null — panel xarita fayli qamragan hududni ko'rsatadi.
     *
     * @param  list<Branch>  $branches
     * @return array{lat: float, lng: float}|null
     */
    private function fallbackCenter(array $branches): ?array
    {
        $point = collect($branches)->map(fn (Branch $b) => $b->center ?? $b->region()->first()?->center)->filter()->first()
            ?? Branch::query()->whereNotNull('center')->orderBy('id')->first()?->center;
        if ($point instanceof Point) {
            return ['lat' => $point->getLatitude(), 'lng' => $point->getLongitude()];
        }

        $ids = array_map(fn (Branch $b): int => $b->id, $branches);
        // Xarita fayli butun viloyat(lar)ni qamraydi, uning markazi bo'sh dala bo'lishi mumkin — ish qayerda bo'lsa o'sha
        $recent = Order::query()->withoutGlobalScopes()->whereIn('branch_id', $ids)->whereNotNull('pickup_location')
            ->latest('id')->limit(200)->select('pickup_location');
        $seen = Driver::query()->withoutGlobalScopes()->whereIn('branch_id', $ids)->whereNotNull('last_location')
            ->latest('last_seen_at')->limit(50)->select('last_location as pickup_location');

        foreach ([$recent, $seen] as $query) {
            $row = DB::query()->fromSub($query, 'p')
                ->selectRaw('avg(ST_Y(pickup_location::geometry)) as lat, avg(ST_X(pickup_location::geometry)) as lng')
                ->first();
            if ($row !== null && $row->lat !== null) {
                return ['lat' => (float) $row->lat, 'lng' => (float) $row->lng];
            }
        }

        return null;
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
                'vehicle' => $driver->vehicle ? trim($driver->vehicle->color.' '.$driver->vehicle->model.' '.Vehicle::formatPlate($driver->vehicle->plate_number)) : null,
                'last_seen_at' => $driver->last_seen_at?->toIso8601String(),
            ])
            ->all());
    }
}
