<?php

namespace App\Services\Regions;

use App\Enums\RegionType;
use App\Models\Region;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileObject;

/**
 * O'zbekiston ma'muriy chegaralarini import qiladi. Manba — `deploy/osm-prepare.sh` OSM PBF
 * faylidan chiqargan GeoJSON qatorlari: admin_level=4 — viloyatlar, admin_level=6 — shahar va tumanlar.
 */
class OsmRegionImporter
{
    /**
     * @param  list<string>  $onlyRegions  faqat shu viloyatlarning shahar/tumanlari (nomi bo'yicha, bo'sh — hammasi)
     * @return array{regions: int, children: int}
     */
    public function import(string $path, array $onlyRegions = []): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Fayl topilmadi: {$path}. Avval deploy/osm-prepare.sh ni ishga tushiring.");
        }

        $stats = ['regions' => 0, 'children' => 0];

        // 1-o'tish: viloyatlar — faqat O'zbekistonniki (fayl ichida qo'shni davlatlar ham bor)
        foreach ($this->features($path, '4') as $feature) {
            if (str_starts_with($feature['properties']['ISO3166-2'] ?? '', 'UZ-')) {
                $this->store($feature, RegionType::Region, null);
                $stats['regions']++;
            }
        }

        $parentIds = array_values(Region::query()->whereNull('parent_id')->get()
            ->when($onlyRegions, fn ($regions) => $regions->filter(fn (Region $r) => $this->matches($r, $onlyRegions)))
            ->map(fn (Region $region): int => $region->id)
            ->all());

        // 2-o'tish: shahar va tumanlar — tanlangan viloyatlar ichidagilari
        foreach ($this->features($path, '6') as $feature) {
            $parent = $this->containingRegion($feature, $parentIds);
            if ($parent) {
                $this->store($feature, $this->childType($feature['properties']), $parent);
                $stats['children']++;
            }
        }

        return $stats;
    }

    /**
     * @return \Generator<array{type: string, geometry: array<string, mixed>, properties: array<string, mixed>}>
     */
    private function features(string $path, string $adminLevel): \Generator
    {
        $file = new SplFileObject($path);

        while (! $file->eof()) {
            // geojsonseq qatorlari RS (0x1E) belgisi bilan boshlanadi
            $line = trim((string) $file->fgets(), "\x1E \n\r\t");
            if ($line === '') {
                continue;
            }
            // Katta geometriyani faqat kerakli darajadagi qatorlar uchun parse qilamiz
            if (! preg_match('/"admin_level":\s*"'.$adminLevel.'"/', $line)) {
                continue;
            }
            $feature = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (($feature['properties']['admin_level'] ?? null) === $adminLevel
                && ($feature['geometry']['type'] ?? null) === 'MultiPolygon'
                && ($feature['properties']['@type'] ?? null) === 'relation') {
                yield $feature;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $feature
     */
    private function store(array $feature, RegionType $type, ?int $parentId): void
    {
        $tags = $feature['properties'];

        $region = Region::query()->updateOrCreate(['osm_id' => $tags['@id']], [
            'parent_id' => $parentId,
            'type' => $type,
            'name_uz' => $tags['name:uz'] ?? $tags['name'],
            'name_ru' => $tags['name:ru'] ?? null,
        ]);

        DB::update(<<<'SQL'
            UPDATE regions SET
                boundary = g.geom::geography,
                center = ST_PointOnSurface(g.geom)::geography
            FROM (SELECT ST_Multi(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))) AS geom) AS g
            WHERE regions.id = ?
            SQL, [json_encode($feature['geometry']), $region->id]);
    }

    /**
     * Shahar/tuman qaysi viloyat ichida joylashgan.
     *
     * @param  array<string, mixed>  $feature
     * @param  list<int>  $parentIds
     */
    private function containingRegion(array $feature, array $parentIds): ?int
    {
        if ($parentIds === []) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
        $row = DB::selectOne(<<<SQL
            SELECT id FROM regions
            WHERE id IN ({$placeholders})
              AND ST_Covers(boundary::geometry, ST_PointOnSurface(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))))
            LIMIT 1
            SQL, [...$parentIds, json_encode($feature['geometry'])]);

        return $row?->id;
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    private function childType(array $tags): RegionType
    {
        $names = Str::lower(($tags['name:uz'] ?? '').' '.($tags['name'] ?? '').' '.($tags['name:ru'] ?? ''));

        return Str::contains($names, ['tuman', 'район', 'district']) ? RegionType::District : RegionType::City;
    }

    /**
     * @param  list<string>  $needles
     */
    private function matches(Region $region, array $needles): bool
    {
        $haystack = $this->normalize($region->name_uz.' '.$region->name_ru);

        foreach ($needles as $needle) {
            if (Str::contains($haystack, $this->normalize($needle))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        // Farg'ona / Fargʻona / Farg‘ona — bir xil deb hisoblanadi
        return Str::lower(str_replace(["'", 'ʻ', 'ʼ', '‘', '’', '`'], '', Str::ascii($value)));
    }
}
