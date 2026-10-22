<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Landmark;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mo'ljallar bazasi (reja §4): tahrirlash, sinonim qo'shish, o'chirish.
 */
class LandmarkController extends Controller
{
    public function index(Request $request): Response
    {
        $q = $request->string('q')->toString();

        $landmarks = Landmark::query()
            ->when($q !== '', fn ($query) => $query->search($q), fn ($query) => $query->orderByDesc('usage_count')->orderBy('name'))
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('admin/landmarks', [
            'landmarks' => $landmarks->through(fn (Landmark $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'aliases' => $l->aliases,
                'category' => $l->category,
                'usage_count' => $l->usage_count,
                'lat' => $l->location->getLatitude(),
                'lng' => $l->location->getLongitude(),
            ]),
            'filters' => ['q' => $q],
            'canManage' => $request->user()?->can('landmarks.manage') ?? false,
            'map' => ['tiles' => config('services.tiles.url')],
        ]);
    }

    public function update(Request $request, Landmark $landmark): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'aliases' => ['array', 'max:20'],
            'aliases.*' => ['string', 'max:150'],
            'category' => ['nullable', 'string', 'max:32'],
            'lat' => ['required', 'numeric', 'between:37,46'],
            'lng' => ['required', 'numeric', 'between:55,74'],
        ]);

        $aliases = array_values(array_filter($data['aliases'] ?? []));
        // Eski (OSM) nom muqobil nom bo'lib qoladi — mijozlar ko'pincha eski nom bilan aytadi
        if ($landmark->name !== $data['name'] && ! in_array($landmark->name, $aliases, true)) {
            $aliases[] = $landmark->name;
        }

        $landmark->forceFill(['edited_at' => now()]);
        $landmark->update([
            'name' => $data['name'],
            'aliases' => array_slice($aliases, 0, 20),
            'category' => $data['category'] ?? null,
            'location' => Point::makeGeodetic((float) $data['lat'], (float) $data['lng']),
        ]);

        return back();
    }

    public function destroy(Landmark $landmark): RedirectResponse
    {
        $landmark->delete();

        return back();
    }
}
