<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Region;
use App\Models\Setting;
use App\Support\Settings;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Filiallar (superadmin): hudud viloyat → shahar/tuman bo'yicha tanlanadi (qaror #31).
 */
class BranchController extends Controller
{
    /** Filial o'zi o'zgartira oladigan sozlamalar (bo'sh — umumiy qiymat ishlatiladi) */
    public const BRANCH_SETTINGS = [
        'drivers.commission_percent' => ['numeric', 'min:0', 'max:100'],
        'drivers.min_balance' => ['integer', 'min:0'],
        'drivers.cancel_penalty' => ['integer', 'min:0'],
        'drivers.max_cancels_per_shift' => ['integer', 'min:0', 'max:100'],
        'drivers.block_minutes' => ['integer', 'min:0', 'max:1440'],
        'dispatch.step_seconds' => ['integer', 'min:5', 'max:120'],
        'dispatch.direct_offer_seconds' => ['integer', 'min:5', 'max:120'],
        'dispatch.low_rating_threshold' => ['integer', 'min:0', 'max:100'],
        'pricing.surge_coeff' => ['numeric', 'min:1', 'max:5'],
        'pricing.surge_from_hour' => ['integer', 'min:0', 'max:23'],
        'pricing.surge_to_hour' => ['integer', 'min:0', 'max:24'],
    ];

    public function index(Settings $settings): Response
    {
        return Inertia::render('admin/branches', [
            'branches' => Branch::query()->with('region.parent')->orderBy('name')->get()->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'service_name' => $branch->service_name,
                'greeting_text' => $branch->greeting_text,
                'timezone' => $branch->timezone,
                'is_active' => $branch->is_active,
                'work_schedule' => $branch->work_schedule,
                'holidays' => $branch->holidays ?? [],
                'region_id' => $branch->region_id,
                'center' => $branch->center ? ['lat' => $branch->center->getLatitude(), 'lng' => $branch->center->getLongitude()] : null,
                'service_radius_km' => $branch->service_radius_m !== null ? $branch->service_radius_m / 1000 : null,
                // Hudud viloyatning o'zi bo'lsa — viloyat tanlangan, shahar/tuman bo'sh
                'parent_region_id' => $branch->region->parent_id ?? $branch->region_id,
                'region' => $branch->region ? trim(($branch->region->parent?->name() ?? '').' / '.$branch->region->name(), ' /') : null,
                'settings' => collect(array_keys(self::BRANCH_SETTINGS))->mapWithKeys(fn (string $key) => [
                    $key => Setting::query()->where('branch_id', $branch->id)->where('key', $key)->value('value'),
                ]),
            ]),
            'defaults' => collect(array_keys(self::BRANCH_SETTINGS))->mapWithKeys(fn (string $key) => [$key => $settings->get($key)]),
            'tiles' => config('services.tiles.url'),
            'regions' => Region::query()->whereNull('parent_id')->orderBy('name_uz')->get()
                ->map(fn (Region $r) => ['id' => $r->id, 'name' => $r->name()]),
        ]);
    }

    public function children(Region $region): JsonResponse
    {
        return response()->json($region->children()->orderBy('type')->orderBy('name_uz')->get()
            ->map(fn (Region $r) => ['id' => $r->id, 'name' => $r->name(), 'type' => $r->type->value]));
    }

    public function store(Request $request): RedirectResponse
    {
        $branch = new Branch;
        $this->save($request, $branch);

        return to_route('admin.branches.index');
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $this->save($request, $branch);

        return to_route('admin.branches.index');
    }

    private function save(Request $request, Branch $branch): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'service_name' => ['nullable', 'string', 'max:100'],
            'greeting_text' => ['nullable', 'string', 'max:500'],
            // Shahar/tuman yoki (tanlanmasa) butun viloyat
            'region_id' => ['nullable', Rule::exists('regions', 'id')],
            'center_lat' => ['nullable', 'required_with:center_lng,service_radius_km', 'numeric', 'between:-90,90'],
            'center_lng' => ['nullable', 'required_with:center_lat', 'numeric', 'between:-180,180'],
            'service_radius_km' => ['nullable', 'numeric', 'min:0.5', 'max:300'],
            'timezone' => ['required', 'timezone'],
            'is_active' => ['boolean'],
            'work_schedule' => ['nullable', 'array:'.implode(',', Branch::WEEKDAYS)],
            'work_schedule.*' => ['nullable', 'array:from,to'],
            'work_schedule.*.from' => ['required', 'date_format:H:i'],
            'work_schedule.*.to' => ['required', 'date_format:H:i', 'different:work_schedule.*.from'],
            'holidays' => ['array', 'max:100'],
            'holidays.*' => ['date_format:Y-m-d'],
            'settings' => ['array'],
            ...collect(self::BRANCH_SETTINGS)->mapWithKeys(fn (array $rules, string $key) => [
                'settings.'.str_replace('.', '__', $key) => ['nullable', ...$rules],
            ])->all(),
        ]);

        $branch->fill([
            'name' => $data['name'],
            'service_name' => $data['service_name'] ?? null,
            'greeting_text' => $data['greeting_text'] ?? null,
            'timezone' => $data['timezone'],
            'is_active' => $data['is_active'] ?? true,
            'work_schedule' => $data['work_schedule'] ?? null,
            'holidays' => array_values(array_unique($data['holidays'] ?? [])) ?: null,
        ]);

        if (! empty($data['region_id']) && $branch->region_id !== (int) $data['region_id']) {
            $branch->fillAreaFromRegion(Region::query()->whereKey($data['region_id'])->firstOrFail());
        }
        // Xaritada belgilangan markaz shahar markazidan ustun (operator xaritasi va buyurtma shu yerdan boshlanadi)
        if (isset($data['center_lat'], $data['center_lng'])) {
            $branch->center = Point::makeGeodetic((float) $data['center_lat'], (float) $data['center_lng']);
        }
        $radius = isset($data['service_radius_km']) ? (int) round((float) $data['service_radius_km'] * 1000) : null;
        if ($radius === null && $branch->service_radius_m !== null) {
            // Radius olib tashlandi — hudud yana shahar/tuman (yoki viloyat) chegarasidan
            $branch->service_area = $branch->region()->first()?->boundary;
        }
        $branch->service_radius_m = $radius;
        $branch->save();

        // Radius berilsa xizmat hududi — markaz atrofidagi aylana (shahar chegarasi o'rniga)
        if ($radius !== null && $branch->center !== null) {
            DB::update(
                'UPDATE branches SET service_area = ST_Multi(ST_Buffer(center, ?)::geometry)::geography WHERE id = ?',
                [$radius, $branch->id],
            );
        }

        foreach (array_keys(self::BRANCH_SETTINGS) as $key) {
            $value = $data['settings'][str_replace('.', '__', $key)] ?? null;
            if ($value === null || $value === '') {
                Setting::query()->where('branch_id', $branch->id)->where('key', $key)->delete();
            } else {
                app(Settings::class)->set($key, $value + 0, $branch);
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved')]);
    }
}
