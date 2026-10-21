<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Xaritadagi joy yozuvlari bizning bazadan (Mapbox Vector Tile, PostGIS ST_AsMVT): admin tuzatgan nom,
 * operator qo'shgan va ochiq manbalardan kelgan joylar darhol ko'rinadi. Xarita faylidagi OSM yozuvlari
 * bir marta "chizilgan" — ularda eski nomlar qolardi (masalan Makro o'rnidagi yangi joy).
 */
class LandmarkTileController extends Controller
{
    // `kind` — Protomaps uslubidagi turlar (ikonka va yozuv rangi shunga qarab tanlanadi)

    /** Bundan kichik zoom'da shahar umumiy ko'rinadi — joy yozuvlari kerak emas */
    public const MIN_ZOOM = 13;

    public function __invoke(int $z, int $x, int $y): Response
    {
        abort_if($z > 22 || $x < 0 || $y < 0 || $x >= 2 ** $z || $y >= 2 ** $z, 404);

        $tile = $z < self::MIN_ZOOM ? '' : DB::scalar(<<<'SQL'
            WITH bounds AS (SELECT ST_TileEnvelope(?, ?, ?) AS geom),
            rows AS (
                SELECT l.id, l.name,
                    CASE
                        WHEN l.category = 'railway:station' THEN 'station'
                        WHEN l.category = 'amenity:bus_station' THEN 'bus_stop'
                        WHEN l.category IN ('amenity:school', 'amenity:college', 'amenity:kindergarten', 'amenity:training') THEN 'school'
                        WHEN l.category IN ('shop:supermarket', 'shop:mall', 'shop:department_store', 'amenity:marketplace', 'shop:convenience') THEN 'supermarket'
                        WHEN l.category IN ('amenity:place_of_worship', 'historic:monument', 'historic:yes', 'wikidata:architectural_landmark', 'tourism:attraction') THEN 'attraction'
                        WHEN l.category = 'tourism:hotel' THEN 'building'
                        WHEN split_part(l.category, ':', 2) IN ('university', 'cafe', 'restaurant', 'fast_food', 'bar', 'stadium', 'park',
                            'museum', 'theatre', 'post_office', 'aerodrome', 'clothes', 'electronics', 'beauty', 'books', 'library', 'zoo')
                            THEN split_part(l.category, ':', 2)
                        -- Mos ikonka yo'q — kichik nuqta, yozuv neytral rangda (MapStyleController::LABEL_*)
                        ELSE 'townspot'
                    END AS kind,
                    CASE
                        WHEN l.category IN ('railway:station', 'amenity:bus_station', 'amenity:university', 'leisure:stadium',
                            'aeroway:aerodrome', 'amenity:marketplace', 'shop:mall', 'amenity:hospital') THEN 14
                        WHEN l.category IN ('amenity:school', 'shop:supermarket', 'amenity:place_of_worship', 'tourism:hotel',
                            'tourism:museum', 'amenity:bank', 'amenity:fuel', 'amenity:college', 'historic:monument') THEN 15
                        ELSE 16
                    END AS min_zoom,
                    ST_AsMVTGeom(ST_Transform(l.location::geometry, 3857), bounds.geom, 4096, 64, true) AS geom
                FROM landmarks l, bounds
                WHERE l.location::geometry && ST_Transform(bounds.geom, 4326)
                    AND l.category NOT IN ('address', 'street')
                    AND l.category NOT LIKE 'place:%'
                ORDER BY l.usage_count DESC, l.id
                LIMIT 400
            )
            SELECT ST_AsMVT(rows, 'pois', 4096, 'geom') FROM rows
            SQL, [$z, $x, $y]);

        // PostgreSQL bytea PDO'da oqim (resource) bo'lib keladi
        $bytes = is_resource($tile) ? (string) stream_get_contents($tile) : (is_string($tile) ? $tile : '');

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.mapbox-vector-tile',
            // Tuzatish bir necha daqiqada ko'rinsin, har siljishda baza zo'riqmasin
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
