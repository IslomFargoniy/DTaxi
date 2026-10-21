<?php

namespace App\Http\Controllers;

use App\Services\Landmarks\StreetRenamer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MapLibre uslubi: tile, shrift va belgilar shu serverda (qaror #2). Panel va Android ilova bir xil uslubni oladi.
 */
class MapStyleController extends Controller
{
    /** Ikonkasiz joy yozuvlari rangi (taxi-map.tsx bilan bir xil) */
    public const LABEL_LIGHT = '#5c5853';

    public const LABEL_DARK = '#c2bdb5';

    public function __invoke(Request $request, StreetRenamer $renamer): JsonResponse
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
        $renames = $renamer->map();
        foreach (is_array($layers) ? $layers : [] as $layer) {
            // Joy yozuvlari — bizning bazadan (LandmarkTileController): admin tuzatgan nomlar darhol ko'rinadi
            if ($layer instanceof \stdClass && ($layer->id ?? null) === 'pois' && isset($layer->layout)) {
                $layer->source = 'dtaxi';
                $layer->filter = ['>=', ['zoom'], ['get', 'min_zoom']];
                $layer->layout->{'text-field'} = ['get', 'name'];
                // Ikonkasi yo'q turlar (karaoke, dorixona...) uslubda fon rangida yozilardi — o'qib bo'lmasdi
                if (isset($layer->paint->{'text-color'}) && is_array($layer->paint->{'text-color'})) {
                    $color = $layer->paint->{'text-color'};
                    $color[count($color) - 1] = $flavor === 'dark' ? self::LABEL_DARK : self::LABEL_LIGHT;
                    $layer->paint->{'text-color'} = $color;
                }
            }
            // Ko'cha yozuvlari — OSM'dagi eski nom o'rniga admin kiritgan yangi nom (taxi-map.tsx bilan bir xil)
            if ($renames !== [] && $layer instanceof \stdClass && ($layer->{'source-layer'} ?? null) === 'roads'
                && ($layer->id ?? null) !== 'roads_shields' && isset($layer->layout->{'text-field'})) {
                $layer->layout->{'text-field'} = self::renamed($layer->layout->{'text-field'}, $lang, $renames);
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

    /**
     * Panel xaritasi uchun qayta nomlangan ko'chalar (eski → yangi); uslubni brauzer o'zi yig'adi.
     */
    public function renames(StreetRenamer $renamer): JsonResponse
    {
        return response()->json((object) $renamer->map())->header('Cache-Control', 'public, max-age=300');
    }

    /**
     * Yozuvdagi nom (tildagi yoki asosiy) eski nomga teng bo'lsa — yangi nom, aks holda asl ifoda.
     *
     * @param  array<string, string>  $renames
     * @return list<mixed>
     */
    public static function renamed(mixed $textField, string $lang, array $renames): array
    {
        $pairs = [];
        foreach ($renames as $old => $new) {
            // "format" — asl ifoda formatted turida; Android'dagi MapLibre Native ham bir xil turni kutadi
            array_push($pairs, (string) $old, ['format', $new, new \stdClass]);
        }

        return [
            'match', ['coalesce', ['get', "name:{$lang}"], ['get', 'name'], ''], ...$pairs,
            // Tildagi nom (name:uz) bor, admin esa asosiy nomni (name) tanlagan bo'lsa ham
            ['match', ['coalesce', ['get', 'name'], ''], ...$pairs, $textField],
        ];
    }
}
