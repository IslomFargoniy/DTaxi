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
        $lang = $request->query('lang') === 'ru' ? 'ru' : 'uz';
        $base = rtrim($request->getSchemeAndHttpHost(), '/');
        $tiles = (string) config('services.tiles.url');

        return response()->json([
            'version' => 8,
            'glyphs' => "{$base}/map/fonts/{fontstack}/{range}.pbf",
            'sprite' => "{$base}/map/sprites/v4/{$flavor}",
            'sources' => [
                'protomaps' => [
                    'type' => 'vector',
                    'url' => 'pmtiles://'.(str_starts_with($tiles, 'http') ? $tiles : $base.$tiles),
                    'attribution' => '© OpenStreetMap',
                ],
            ],
            'layers' => json_decode((string) file_get_contents(resource_path("map/layers-{$flavor}-{$lang}.json")), true),
        ])->header('Cache-Control', 'public, max-age=3600');
    }
}
