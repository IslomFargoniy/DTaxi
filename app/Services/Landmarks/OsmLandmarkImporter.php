<?php

namespace App\Services\Landmarks;

use App\Support\Transliterator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SplFileObject;

/**
 * OSM'dagi nomli joylarni (bozor, maktab, kasalxona, mahalla…) mo'ljallar bazasiga boshlang'ich import
 * qilish (reja §4). Faqat bazadagi shahar/tumanlar ichidagilar olinadi; qayta ishga tushirilsa yangilanadi.
 */
class OsmLandmarkImporter
{
    private const CATEGORY_TAGS = ['amenity', 'shop', 'tourism', 'leisure', 'railway', 'place'];

    private const BATCH = 500;

    /**
     * @return array{read: int, imported: int}
     */
    public function import(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Fayl topilmadi: {$path}. Avval deploy/osm-prepare.sh ni ishga tushiring.");
        }

        $file = new SplFileObject($path);
        $batch = [];
        $stats = ['read' => 0, 'imported' => 0];

        while (! $file->eof()) {
            $line = trim((string) $file->fgets(), "\x1E \n\r\t");
            if ($line === '') {
                continue;
            }
            $row = $this->row(json_decode($line, true, flags: JSON_THROW_ON_ERROR));
            if ($row) {
                $batch[] = $row;
                $stats['read']++;
            }
            if (count($batch) >= self::BATCH) {
                $stats['imported'] += $this->flush($batch);
                $batch = [];
            }
        }

        return ['read' => $stats['read'], 'imported' => $stats['imported'] + $this->flush($batch)];
    }

    /**
     * @param  array{geometry: array<string, mixed>, properties: array<string, mixed>}  $feature
     * @return array{osm_id: int, name: string, aliases: string, search: string, category: string|null, lng: float, lat: float}|null
     */
    private function row(array $feature): ?array
    {
        $tags = $feature['properties'];
        $name = $tags['name:uz'] ?? $tags['name'] ?? null;
        $point = $this->point($feature['geometry']);
        $category = null;
        $aliasSources = [$tags['name'] ?? null, $tags['name:ru'] ?? null, $tags['name:en'] ?? null, $tags['alt_name'] ?? null, $tags['old_name'] ?? null];

        // Uy manzili: "Mustaqillik ko'chasi, 12" — operator ko'cha va uy raqami bilan qidiradi
        $house = $tags['addr:housenumber'] ?? null;
        $street = $tags['addr:street'] ?? $tags['addr:place'] ?? null;
        if (is_string($house) && is_string($street) && $house !== '' && $street !== '') {
            $address = "{$street}, {$house}";
            $aliasSources[] = is_string($name) ? $address : null;
            $name = is_string($name) && trim($name) !== '' ? $name : $address;
            $category = 'address';
        } elseif (isset($tags['highway']) && is_string($name)) {
            $category = 'street';
        }

        if (! is_string($name) || trim($name) === '' || ! $point) {
            return null;
        }

        $aliases = array_values(array_unique(array_filter(
            $aliasSources,
            fn ($alias) => is_string($alias) && $alias !== '' && $alias !== $name,
        )));

        if ($category === null) {
            foreach (self::CATEGORY_TAGS as $tag) {
                if (isset($tags[$tag]) && is_string($tags[$tag])) {
                    $category = "{$tag}:{$tags[$tag]}";
                    break;
                }
            }
        }

        // OSM id'lar turlari bo'yicha takrorlanishi mumkin — yagona kalit uchun tur kodlanadi
        $typeOffset = ['node' => 0, 'way' => 1, 'relation' => 2][$tags['@type'] ?? 'node'] ?? 0;

        return [
            'osm_id' => (int) $tags['@id'] * 10 + $typeOffset,
            'name' => mb_substr($name, 0, 255),
            'aliases' => json_encode($aliases, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'search' => Transliterator::forSearch(implode(' ', [$name, ...$aliases])),
            'category' => $category ? mb_substr($category, 0, 32) : null,
            'lng' => $point[0],
            'lat' => $point[1],
        ];
    }

    /**
     * Nuqta yoki poligon markazi (taxminiy — mo'ljal uchun yetarli).
     *
     * @param  array<string, mixed>  $geometry
     * @return array{float, float}|null
     */
    private function point(array $geometry): ?array
    {
        $coordinates = $geometry['coordinates'] ?? null;

        $ring = match ($geometry['type'] ?? null) {
            'Point' => [$coordinates],
            'LineString' => $coordinates,
            'Polygon' => $coordinates[0] ?? null,
            'MultiPolygon' => $coordinates[0][0] ?? null,
            default => null,
        };

        if (! is_array($ring) || $ring === []) {
            return null;
        }

        $lng = array_sum(array_column($ring, 0)) / count($ring);
        $lat = array_sum(array_column($ring, 1)) / count($ring);

        return [(float) $lng, (float) $lat];
    }

    /**
     * @param  list<array{osm_id: int, name: string, aliases: string, search: string, category: string|null, lng: float, lat: float}>  $rows
     */
    private function flush(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        // Eksportda bitta obyekt ikki marta kelishi mumkin — bitta INSERT ... ON CONFLICT ichida bu xato
        $rows = array_values(array_column($rows, null, 'osm_id'));

        $values = [];
        $bindings = [];
        foreach ($rows as $row) {
            $values[] = '(?::bigint, ?, ?::jsonb, ?, ?, ?::float8, ?::float8)';
            array_push($bindings, $row['osm_id'], $row['name'], $row['aliases'], $row['search'], $row['category'], $row['lng'], $row['lat']);
        }

        // Shahar/tuman ichidagilarigina (qo'shni davlatlar tashlab ketiladi)
        $sql = 'INSERT INTO landmarks (osm_id, name, aliases, search, category, location, region_id, created_at, updated_at)
            SELECT v.osm_id, v.name, v.aliases, v.search, v.category, p.geog, r.id, NOW(), NOW()
            FROM (VALUES '.implode(',', $values).') AS v(osm_id, name, aliases, search, category, lng, lat)
            CROSS JOIN LATERAL (SELECT ST_SetSRID(ST_MakePoint(v.lng, v.lat), 4326)::geography AS geog) AS p
            JOIN LATERAL (
                SELECT id FROM regions WHERE parent_id IS NOT NULL AND ST_Covers(boundary, p.geog) LIMIT 1
            ) AS r ON TRUE
            ON CONFLICT (osm_id) DO UPDATE SET
                name = CASE WHEN landmarks.edited_at IS NULL THEN EXCLUDED.name ELSE landmarks.name END,
                aliases = CASE WHEN landmarks.edited_at IS NULL THEN EXCLUDED.aliases ELSE landmarks.aliases END,
                search = CASE WHEN landmarks.edited_at IS NULL THEN EXCLUDED.search ELSE landmarks.search END,
                category = CASE WHEN landmarks.edited_at IS NULL THEN EXCLUDED.category ELSE landmarks.category END,
                location = CASE WHEN landmarks.edited_at IS NULL THEN EXCLUDED.location ELSE landmarks.location END,
                region_id = CASE WHEN landmarks.edited_at IS NULL THEN EXCLUDED.region_id ELSE landmarks.region_id END,
                updated_at = NOW()';

        // Admin qo'lda tuzatgan mo'ljallar (edited_at) importda o'zgarmaydi
        return DB::affectingStatement($sql, $bindings);
    }
}
