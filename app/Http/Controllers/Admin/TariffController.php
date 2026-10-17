<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Tariff;
use App\Models\TariffClass;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tarif klasslari va narxlar (qarorlar #21, #27, #33).
 */
class TariffController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $branches = Branch::query()->when(! $actor->isSuperadmin(), fn ($q) => $q->whereIn('id', $actor->branchIds()))->orderBy('name')->get(['id', 'name']);

        return Inertia::render('admin/tariffs', [
            'branches' => $branches,
            'classes' => TariffClass::query()->whereIn('branch_id', $branches->pluck('id'))->with('tariff')->orderBy('sort')->get()
                ->map(fn (TariffClass $c) => [
                    'id' => $c->id,
                    'branch_id' => $c->branch_id,
                    'name' => $c->name,
                    'icon' => $c->icon,
                    'sort' => $c->sort,
                    'is_delivery' => $c->is_delivery,
                    'is_default' => $c->is_default,
                    'is_active' => $c->is_active,
                    'tariff' => $c->tariff?->only([
                        'base_fare', 'base_km', 'per_km', 'rounding_step', 'waiting_enabled', 'waiting_free_min',
                        'waiting_per_min', 'night_enabled', 'night_from', 'night_to', 'night_coeff',
                        'outside_enabled', 'outside_per_km',
                    ]),
                ]),
        ]);
    }

    public function store(Request $request, Settings $settings): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validate([
            'branch_id' => ['required', $actor->isSuperadmin() ? Rule::exists('branches', 'id') : Rule::in($actor->branchIds())],
            'name' => ['required', 'string', 'max:50'],
        ]);

        $class = TariffClass::query()->create([...$data, 'sort' => 100]);
        $class->tariff()->create([
            'branch_id' => $class->branch_id,
            'base_fare' => 10000,
            'base_km' => 3,
            'per_km' => 2000,
            'rounding_step' => (int) $settings->get('tariffs.rounding_step', $class->branch_id),
        ]);

        return back();
    }

    public function update(Request $request, TariffClass $tariffClass): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'sort' => ['required', 'integer', 'min:0', 'max:1000'],
            'is_delivery' => ['boolean'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'tariff.base_fare' => ['required', 'integer', 'min:0'],
            'tariff.base_km' => ['required', 'numeric', 'min:0', 'max:100'],
            'tariff.per_km' => ['required', 'integer', 'min:0'],
            'tariff.rounding_step' => ['required', 'integer', Rule::in([1, 100, 500, 1000])],
            'tariff.waiting_enabled' => ['boolean'],
            'tariff.waiting_free_min' => ['required', 'integer', 'min:0', 'max:60'],
            'tariff.waiting_per_min' => ['required', 'integer', 'min:0'],
            'tariff.night_enabled' => ['boolean'],
            'tariff.night_from' => ['required', 'date_format:H:i'],
            'tariff.night_to' => ['required', 'date_format:H:i'],
            'tariff.night_coeff' => ['required', 'numeric', 'min:1', 'max:5'],
            'tariff.outside_enabled' => ['boolean'],
            'tariff.outside_per_km' => ['required', 'integer', 'min:0'],
        ]);

        if (! empty($data['is_default'])) {
            TariffClass::query()->where('branch_id', $tariffClass->branch_id)->whereKeyNot($tariffClass->id)->update(['is_default' => false]);
        }

        $tariffClass->update(Arr::except($data, 'tariff'));
        Tariff::query()->updateOrCreate(
            ['tariff_class_id' => $tariffClass->id],
            [...$data['tariff'], 'branch_id' => $tariffClass->branch_id],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved')]);

        return back();
    }
}
