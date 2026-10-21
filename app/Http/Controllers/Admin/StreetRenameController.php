<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Landmark;
use App\Models\Region;
use App\Models\StreetRename;
use App\Services\Landmarks\StreetRenamer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Qayta nomlangan ko'chalar: OSM'da eski nom qolgan — xarita, navigatsiya va qidiruvda yangi nom.
 */
class StreetRenameController extends Controller
{
    public function __construct(private readonly StreetRenamer $renamer) {}

    public function index(): Response
    {
        return Inertia::render('admin/street-renames', [
            'renames' => StreetRename::query()->with('region')->orderBy('old_name')->get()->map(fn (StreetRename $r) => [
                'id' => $r->id,
                'old_name' => $r->old_name,
                'new_name' => $r->new_name,
                'region_id' => $r->region_id,
                'region' => $r->region?->name_uz,
            ]),
            'regions' => Region::query()->with('parent')->orderByRaw('parent_id IS NOT NULL')->orderBy('name_uz')->get()
                ->map(fn (Region $r) => [
                    'id' => $r->id,
                    'name' => $r->parent ? "{$r->parent->name_uz} — {$r->name_uz}" : $r->name_uz,
                ]),
        ]);
    }

    /**
     * Eski nom uchun takliflar: xaritadagi yozuv OSM'dagi nom bilan bir xil yozilishi kerak —
     * ko'chalar nomi va muqobil nomlari (name, name:ru…) taklif qilinadi.
     */
    public function suggest(Request $request): JsonResponse
    {
        $q = $request->string('q')->trim()->toString();
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $names = [];
        $streets = Landmark::query()->where('category', 'street')->search($q)->limit(10)->get(['name', 'aliases']);
        foreach ($streets as $street) {
            foreach ([$street->name, ...$street->aliases] as $name) {
                $names[$name] = true;
            }
        }

        return response()->json(array_slice(array_keys($names), 0, 15));
    }

    public function store(Request $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $rename = new StreetRename($this->validated($request));
            $rename->created_by = $request->user()?->id;
            $rename->save();
            $this->renamer->apply($rename);
        });
        $this->renamer->forget();

        return back();
    }

    public function update(Request $request, StreetRename $streetRename): RedirectResponse
    {
        DB::transaction(function () use ($request, $streetRename) {
            $data = $this->validated($request, $streetRename);
            // Avval eski qoida bekor qilinadi, keyin yangisi qo'llanadi
            $this->renamer->revert($streetRename);
            $streetRename->update($data);
            $this->renamer->apply($streetRename);
        });
        $this->renamer->forget();

        return back();
    }

    public function destroy(StreetRename $streetRename): RedirectResponse
    {
        DB::transaction(function () use ($streetRename) {
            $this->renamer->revert($streetRename);
            $streetRename->delete();
        });
        $this->renamer->forget();

        return back();
    }

    /**
     * @return array{old_name: string, new_name: string, region_id: int|null}
     */
    private function validated(Request $request, ?StreetRename $rename = null): array
    {
        $data = $request->validate([
            'old_name' => ['required', 'string', 'max:150', 'different:new_name',
                Rule::unique('street_renames')->where('region_id', $request->input('region_id'))->ignore($rename?->id)],
            'new_name' => ['required', 'string', 'max:150'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
        ]);

        return [
            'old_name' => trim($data['old_name']),
            'new_name' => trim($data['new_name']),
            'region_id' => isset($data['region_id']) ? (int) $data['region_id'] : null,
        ];
    }
}
