<?php

namespace App\Services\Landmarks;

use App\Support\Transliterator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use SplFileObject;

/**
 * Ochiq litsenziyali joylar ma'lumotini (Overture Maps, Wikidata — qaror #60; GeoNames — #64) mo'ljallar bazasiga import qilish.
 * Fayl deploy/places-prepare.sh yasagan GeoJSON qatorlari: properties = {id, name, names: {til: nom}, categories}.
 * OSM yoki boshqa manbada yaqin joyda (150 m, qishloq/mahalla uchun 2 km) shu nomli mo'ljal bo'lsa — takror sifatida o'tkazib yuboriladi.
 */
class OpenPlacesImporter
{
    public const SOURCES = ['overture', 'wikidata', 'geonames'];

    /** Takror: shu masofa ichida (metr) va nomi o'xshash boshqa manbadagi mo'ljal */
    private const DUPLICATE_METERS = 150;

    /** Qishloq/mahalla nuqtasi manbalarda markazning turli joyida (GeoNames'da ~1 km gacha farq) — faqat boshqa qishloq/mahallaga solishtiriladi */
    private const DUPLICATE_SETTLEMENT_METERS = 2000;

    private const BATCH = 500;

    /**
     * Manba turkumlari → bizdagi OSM uslubidagi turkum (qolganlari "<manba>:<turkum>").
     *
     * @var array<string, string>
     */
    private const CATEGORIES = [
        'restaurant' => 'amenity:restaurant',
        'fast_food_restaurant' => 'amenity:fast_food',
        'cafe' => 'amenity:cafe',
        'coffee_shop' => 'amenity:cafe',
        'tea_room' => 'amenity:cafe',
        'bar' => 'amenity:bar',
        'hotel' => 'tourism:hotel',
        'hostel' => 'tourism:hostel',
        'college_university' => 'amenity:university',
        'school' => 'amenity:school',
        'public_school' => 'amenity:school',
        'private_school' => 'amenity:school',
        'high_school' => 'amenity:school',
        'middle_school' => 'amenity:school',
        'elementary_school' => 'amenity:school',
        'preschool' => 'amenity:kindergarten',
        'day_care_preschool' => 'amenity:kindergarten',
        'hospital' => 'amenity:hospital',
        'outpatient_care_facility' => 'amenity:clinic',
        'dental_clinic' => 'amenity:dentist',
        'pharmacy' => 'amenity:pharmacy',
        'bank' => 'amenity:bank',
        'bank_or_credit_union' => 'amenity:bank',
        'gas_station' => 'amenity:fuel',
        'fueling_station' => 'amenity:fuel',
        'shopping_mall' => 'shop:mall',
        'department_store' => 'shop:department_store',
        'grocery_store' => 'shop:supermarket',
        'supermarket' => 'shop:supermarket',
        'convenience_store' => 'shop:convenience',
        'market' => 'amenity:marketplace',
        'farmers_market' => 'amenity:marketplace',
        'muslim_place_of_worship' => 'amenity:place_of_worship',
        'church' => 'amenity:place_of_worship',
        'museum' => 'tourism:museum',
        'park' => 'leisure:park',
        'stadium' => 'leisure:stadium',
        'stadium_arena' => 'leisure:stadium',
        'movie_theater' => 'amenity:cinema',
        'theatre_venue' => 'amenity:theatre',
        'train_station' => 'railway:station',
        'bus_station' => 'amenity:bus_station',
        'airport' => 'aeroway:aerodrome',
        'police_station' => 'amenity:police',
        'courthouse' => 'amenity:courthouse',
        'library' => 'amenity:library',
        'gym' => 'leisure:fitness_centre',
        'government_office' => 'office:government',
        'town_hall' => 'amenity:townhall',
        'monument' => 'historic:monument',
        'mausoleum' => 'historic:tomb',
        'border_checkpoint' => 'barrier:border_control',
        'lodging' => 'tourism:hotel',
        'health_care' => 'amenity:clinic',
        'education' => 'amenity:training',
        'specialty_school' => 'amenity:training',
        'historic_site' => 'historic:yes',
        'fashion_and_apparel_store' => 'shop:clothes',
        'hardware_home_and_garden_store' => 'shop:hardware',
        'electronics_store' => 'shop:electronics',
        'food_and_beverage_store' => 'shop:food',
        'personal_or_beauty_service' => 'shop:beauty',
        'corporate_or_business_office' => 'office:company',
        'professional_service' => 'office:company',
        'financial_service' => 'office:financial',
        'real_estate_service' => 'office:estate_agent',
        'travel_service' => 'shop:travel_agency',
        'manufacturer' => 'man_made:works',
        // GeoNames turlari (https://www.geonames.org/export/codes.html)
        'PPL' => 'place:village',
        'PPLX' => 'place:neighbourhood',
        'PPLL' => 'place:hamlet',
        'ADMF' => 'office:government',
        'AIRP' => 'aeroway:aerodrome',
        'ATHF' => 'leisure:sports_centre',
        'BLDG' => 'building:yes',
        'BUSTN' => 'amenity:bus_station',
        'CH' => 'amenity:place_of_worship',
        'MSQE' => 'amenity:place_of_worship',
        'SHRN' => 'historic:wayside_shrine',
        'TMB' => 'historic:tomb',
        'HSP' => 'amenity:hospital',
        'HSPC' => 'amenity:clinic',
        'HSPD' => 'amenity:clinic',
        'HTL' => 'tourism:hotel',
        'MKT' => 'amenity:marketplace',
        'MALL' => 'shop:mall',
        'MUS' => 'tourism:museum',
        'MNMT' => 'historic:monument',
        'PO' => 'amenity:post_office',
        'PRK' => 'leisure:park',
        'RSTN' => 'railway:station',
        'SCH' => 'amenity:school',
        'SCHM' => 'amenity:school',
        'SCHC' => 'amenity:university',
        'UNIV' => 'amenity:university',
        'SCHT' => 'amenity:college',
        'STDM' => 'leisure:stadium',
        'THTR' => 'amenity:theatre',
        'LIBR' => 'amenity:library',
        'RSRT' => 'leisure:resort',
        'BANK' => 'amenity:bank',
    ];

    /**
     * @return array{read: int, imported: int, duplicates: int}
     */
    public function import(string $source, string $path): array
    {
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException("Noma'lum manba: {$source}. Mumkin: ".implode(', ', self::SOURCES));
        }
        if (! is_file($path)) {
            throw new RuntimeException("Fayl topilmadi: {$path}. Avval deploy/places-prepare.sh ni ishga tushiring.");
        }

        $file = new SplFileObject($path);
        $batch = [];
        $stats = ['read' => 0, 'imported' => 0, 'duplicates' => 0];

        while (! $file->eof()) {
            $line = trim((string) $file->fgets(), "\x1E \n\r\t");
            if ($line === '') {
                continue;
            }
            $row = $this->row($source, json_decode($line, true, flags: JSON_THROW_ON_ERROR));
            if ($row) {
                $batch[] = $row;
                $stats['read']++;
            }
            if (count($batch) >= self::BATCH) {
                $stats = $this->add($stats, $this->flush($source, $batch));
                $batch = [];
            }
        }

        return $this->add($stats, $this->flush($source, $batch));
    }

    /**
     * Manba turkumlari ro'yxatidan (aniqrog'i birinchi) bizdagi turkum.
     *
     * @param  list<mixed>  $categories
     */
    public static function category(string $source, array $categories): string
    {
        $categories = array_values(array_filter($categories, fn ($c) => is_string($c) && $c !== ''));
        foreach ($categories as $category) {
            if (isset(self::CATEGORIES[$category])) {
                return self::CATEGORIES[$category];
            }
            // Overture: "italian_restaurant", "art_museum" — asosiy turi bo'yicha
            foreach (['restaurant', 'museum', 'school'] as $suffix) {
                if (str_ends_with($category, "_{$suffix}")) {
                    return self::CATEGORIES[$suffix];
                }
            }
        }

        return mb_substr("{$source}:".($categories[count($categories) - 1] ?? 'place'), 0, 32);
    }

    /**
     * @param  array<string, mixed>  $feature
     * @return array{external_id: string, name: string, key: string, aliases: string, search: string, category: string, lng: float, lat: float}|null
     */
    private function row(string $source, array $feature): ?array
    {
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $coordinates = is_array($feature['geometry'] ?? null) ? ($feature['geometry']['coordinates'] ?? null) : null;
        $id = $properties['id'] ?? null;
        $names = is_array($properties['names'] ?? null) ? $properties['names'] : [];
        $name = $names['uz'] ?? $properties['name'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($name) || trim($name) === ''
            || ! is_array($coordinates) || ! is_numeric($coordinates[0] ?? null) || ! is_numeric($coordinates[1] ?? null)) {
            return null;
        }
        $name = trim($name);

        // Boshqa tillardagi nomlar (rus, ingliz) — sinonim: operator qaysi tilda yozsa ham topiladi
        $aliases = array_values(array_unique(array_filter(
            [$properties['name'] ?? null, ...array_values($names)],
            fn ($alias) => is_string($alias) && trim($alias) !== '' && $alias !== $name,
        )));
        $categories = is_array($properties['categories'] ?? null) ? array_values($properties['categories']) : [];

        return [
            'external_id' => mb_substr($id, 0, 64),
            'name' => mb_substr($name, 0, 255),
            'key' => Transliterator::forSearch($name),
            'aliases' => json_encode($aliases, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'search' => Transliterator::forSearch(implode(' ', [$name, ...$aliases])),
            'category' => self::category($source, $categories),
            'lng' => (float) $coordinates[0],
            'lat' => (float) $coordinates[1],
        ];
    }

    /**
     * @param  list<array{external_id: string, name: string, key: string, aliases: string, search: string, category: string, lng: float, lat: float}>  $rows
     * @return array{imported: int, duplicates: int}
     */
    private function flush(string $source, array $rows): array
    {
        if ($rows === []) {
            return ['imported' => 0, 'duplicates' => 0];
        }

        $rows = array_values(array_column($rows, null, 'external_id'));

        $values = [];
        $bindings = [];
        foreach ($rows as $row) {
            $values[] = '(?, ?, ?, ?::jsonb, ?, ?, ?::float8, ?::float8)';
            array_push($bindings, $row['external_id'], $row['name'], $row['key'], $row['aliases'], $row['search'], $row['category'], $row['lng'], $row['lat']);
        }
        $bindings[] = $source;

        // Takror: boshqa manbada (OSM, qo'lda qo'shilgan) yaqinda o'xshash nomli mo'ljal bor. Avval import
        // qilinganlari takror hisoblanmaydi — yangilanadi. Shahar/tumandan tashqaridagilar olinmaydi.
        // Qishloq/mahalla faqat boshqa qishloq/mahallaga (2 km ichida) solishtiriladi: "Guliston ko'chasi" uni yashirmasin.
        $sql = 'WITH v(external_id, name, key, aliases, search, category, lng, lat) AS (VALUES '.implode(',', $values).'),
            src AS (SELECT ?::varchar AS source),
            p AS (
                SELECT v.*, src.source, g.geog, r.id AS region_id, s.settlement,
                    CASE WHEN s.settlement THEN '.self::DUPLICATE_SETTLEMENT_METERS.' ELSE '.self::DUPLICATE_METERS.' END AS radius
                FROM v CROSS JOIN src
                CROSS JOIN LATERAL (SELECT v.category LIKE \'place:%\' AS settlement) AS s
                CROSS JOIN LATERAL (SELECT ST_SetSRID(ST_MakePoint(v.lng, v.lat), 4326)::geography AS geog) AS g
                JOIN LATERAL (
                    SELECT id FROM regions WHERE parent_id IS NOT NULL AND ST_Covers(boundary, g.geog) LIMIT 1
                ) AS r ON TRUE
            ),
            d AS (
                SELECT p.*, (
                    NOT EXISTS (SELECT 1 FROM landmarks e WHERE e.source = p.source AND e.external_id = p.external_id)
                    AND EXISTS (
                        SELECT 1 FROM landmarks l
                        WHERE l.source <> p.source
                          AND ST_DWithin(l.location, p.geog, p.radius)
                          AND (NOT p.settlement OR l.category LIKE \'place:%\')
                          AND (similarity(l.search, p.key) > 0.5 OR word_similarity(p.key, l.search) > 0.7)
                    )
                ) AS duplicate
                FROM p
            ),
            ins AS (
                INSERT INTO landmarks (source, external_id, name, aliases, search, category, location, region_id, created_at, updated_at)
                SELECT source, external_id, name, aliases, search, category, geog, region_id, NOW(), NOW()
                FROM d WHERE NOT duplicate
                ON CONFLICT (source, external_id) DO UPDATE SET
                    name = CASE WHEN landmarks.edited_at IS NULL THEN EXCLUDED.name ELSE landmarks.name END,
                    aliases = CASE WHEN landmarks.edited_at IS NULL THEN EXCLUDED.aliases ELSE landmarks.aliases END,
                    search = CASE WHEN landmarks.edited_at IS NULL THEN EXCLUDED.search ELSE landmarks.search END,
                    category = CASE WHEN landmarks.edited_at IS NULL THEN EXCLUDED.category ELSE landmarks.category END,
                    location = CASE WHEN landmarks.edited_at IS NULL THEN EXCLUDED.location ELSE landmarks.location END,
                    region_id = CASE WHEN landmarks.edited_at IS NULL THEN EXCLUDED.region_id ELSE landmarks.region_id END,
                    updated_at = NOW()
                RETURNING 1
            )
            SELECT (SELECT count(*) FROM ins) AS imported, (SELECT count(*) FROM d WHERE duplicate) AS duplicates';

        // Admin qo'lda tuzatgan mo'ljallar (edited_at) importda o'zgarmaydi
        $result = DB::selectOne($sql, $bindings);

        return ['imported' => (int) $result->imported, 'duplicates' => (int) $result->duplicates];
    }

    /**
     * @param  array{read: int, imported: int, duplicates: int}  $stats
     * @param  array{imported: int, duplicates: int}  $batch
     * @return array{read: int, imported: int, duplicates: int}
     */
    private function add(array $stats, array $batch): array
    {
        return [
            'read' => $stats['read'],
            'imported' => $stats['imported'] + $batch['imported'],
            'duplicates' => $stats['duplicates'] + $batch['duplicates'],
        ];
    }
}
