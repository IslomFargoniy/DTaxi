<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MapLibre uslubi: tile, shrift va belgilar shu serverda (qaror #2). Panel va Android ilova bir xil uslubni oladi.
 */
class MapStyleController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $flavor = $request->query('flavor') === 'dark' ? 'dark' : 'light';
        // Kirill o'zbekcha (oz) — OSM'dagi o'zbekcha nomlar
        $lang = match ($request->query('lang')) {
            'ru' => 'ru',
            'en' => 'en',
            default => 'uz',
        };
        $base = rtrim($request->getSchemeAndHttpHost(), '/');
        $tiles = (string) config('services.tiles.url');

        // Obyekt sifatida o'qiladi: assoc=true bo'lsa "format" ichidagi {} bo'sh massivga ([]) aylanadi va
        // Android'dagi MapLibre Native yozuv qatlamlarini o'qimaydi (joy nomlari ko'rinmay qoladi)
        $layers = json_decode((string) file_get_contents(resource_path("map/layers-{$flavor}-{$lang}.json")));
        foreach (is_array($layers) ? $layers : [] as $layer) {
            // Joy yozuvlari — bizning bazadan (LandmarkTileController): admin tuzatgan nomlar darhol ko'rinadi
            if ($layer instanceof \stdClass && ($layer->id ?? null) === 'pois' && isset($layer->layout)) {
                $layer->source = 'dtaxi';
                $layer->filter = ['>=', ['zoom'], ['get', 'min_zoom']];
                $layer->layout->{'text-field'} = ['get', 'name'];
            }
        }

        return response()->json([
            'version' => 8,
            'glyphs' => "{$base}/map/fonts/{fontstack}/{range}.pbf",
            'sprite' => "{$base}/map/sprites/v4/{$flavor}",
            'sources' => [
                'protomaps' => [
                    'type' => 'vector',
                    'url' => 'pmtiles://'.(str_starts_with($tiles, 'http') ? $tiles : $base.$tiles),
                    // Mo'ljallar qidiruvi Overture ma'lumotidan ham foydalanadi (qaror #60, litsenziya talabi)
                    'attribution' => '© OpenStreetMap · Overture Maps Foundation · Foursquare',
                ],
                'dtaxi' => [
                    'type' => 'vector',
                    'tiles' => ["{$base}/map/landmarks/{z}/{x}/{y}.pbf"],
                    'minzoom' => LandmarkTileController::MIN_ZOOM,
                    'maxzoom' => 16,
                ],
            ],
            'layers' => $layers,
        ])->header('Cache-Control', 'public, max-age=300');
    }
}
