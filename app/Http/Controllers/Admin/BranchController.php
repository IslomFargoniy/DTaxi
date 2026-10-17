<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Region;
use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                'region_id' => $branch->region_id,
                'parent_region_id' => $branch->region?->parent_id,
                'region' => $branch->region ? trim(($branch->region->parent?->name() ?? '').' / '.$branch->region->name(), ' /') : null,
                'settings' => collect(array_keys(self::BRANCH_SETTINGS))->mapWithKeys(fn (string $key) => [
                    $key => Setting::query()->where('branch_id', $branch->id)->where('key', $key)->value('value'),
                ]),
            ]),
            'defaults' => collect(array_keys(self::BRANCH_SETTINGS))->mapWithKeys(fn (string $key) => [$key => $settings->get($key)]),
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
            'region_id' => ['nullable', Rule::exists('regions', 'id')->whereNotNull('parent_id')],
            'timezone' => ['required', 'timezone'],
            'is_active' => ['boolean'],
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
        ]);

        if (! empty($data['region_id']) && $branch->region_id !== (int) $data['region_id']) {
            $branch->fillAreaFromRegion(Region::query()->whereKey($data['region_id'])->firstOrFail());
        }
        $branch->save();

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
