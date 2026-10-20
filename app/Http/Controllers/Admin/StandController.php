<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Stand;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Navbat joylari (aeroport, vokzal, bozor): admin xaritada belgilaydi.
 */
class StandController extends Controller
{
    public function index(): Response
    {
        $queues = DB::table('stand_queue')->selectRaw('stand_id, count(*) AS n')->groupBy('stand_id')->pluck('n', 'stand_id');

        return Inertia::render('admin/stands', [
            'stands' => Stand::query()->orderBy('name')->get()->map(fn (Stand $s) => [
                'id' => $s->id,
                'branch_id' => $s->branch_id,
                'name' => $s->name,
                'lat' => $s->location->getLatitude(),
                'lng' => $s->location->getLongitude(),
                'radius_m' => $s->radius_m,
                'is_active' => $s->is_active,
                'queue' => (int) ($queues[$s->id] ?? 0),
            ]),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name'])->map(fn (Branch $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'center' => $b->center ? ['lat' => $b->center->getLatitude(), 'lng' => $b->center->getLongitude()] : null,
            ]),
            'tiles' => config('services.tiles.url'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Stand::query()->create($this->validated($request));

        return back();
    }

    public function update(Request $request, Stand $stand): RedirectResponse
    {
        $stand->update($this->validated($request));

        return back();
    }

    public function destroy(Stand $stand): RedirectResponse
    {
        $stand->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::in($user->isSuperadmin() ? Branch::query()->pluck('id')->all() : $user->branchIds())],
            'name' => ['required', 'string', 'max:100'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_m' => ['required', 'integer', 'min:30', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        return [
            'branch_id' => $data['branch_id'],
            'name' => $data['name'],
            'location' => Point::makeGeodetic((float) $data['lat'], (float) $data['lng']),
            'radius_m' => $data['radius_m'],
            'is_active' => $data['is_active'] ?? true,
        ];
    }
}
