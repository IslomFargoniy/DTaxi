<?php

use App\Enums\RegionType;
use App\Models\Branch;
use App\Models\Region;
use App\Services\Regions\OsmRegionImporter;

test('imports Uzbek regions with their cities and districts', function () {
    $stats = app(OsmRegionImporter::class)->import(base_path('tests/fixtures/boundaries.geojsonseq'));

    expect($stats)->toBe(['regions' => 1, 'children' => 2]);

    $fergana = Region::query()->where('osm_id', 178018)->firstOrFail();
    expect($fergana->type)->toBe(RegionType::Region)
        ->and($fergana->name_ru)->toBe('Ферганская область')
        ->and($fergana->boundary)->not->toBeNull()
        ->and($fergana->center)->not->toBeNull();

    expect($fergana->children()->orderBy('name_uz')->get()->map(fn (Region $r) => [$r->name_uz, $r->type]))
        ->toEqual(collect([['Fargʻona shahri', RegionType::City], ['Rishton tumani', RegionType::District]]));

    // Qo'shni davlat viloyati va uning tumani import qilinmaydi
    expect(Region::query()->where('osm_id', 178020)->exists())->toBeFalse()
        ->and(Region::query()->where('osm_id', 3)->exists())->toBeFalse();
});

test('importing twice does not duplicate regions', function () {
    $importer = app(OsmRegionImporter::class);
    $importer->import(base_path('tests/fixtures/boundaries.geojsonseq'));
    $importer->import(base_path('tests/fixtures/boundaries.geojsonseq'));

    expect(Region::query()->count())->toBe(3);
});

test('children can be limited to selected regions', function () {
    $stats = app(OsmRegionImporter::class)->import(base_path('tests/fixtures/boundaries.geojsonseq'), ['Andijon']);

    expect($stats)->toBe(['regions' => 1, 'children' => 0]);
});

test('branch service area is filled from the selected district', function () {
    app(OsmRegionImporter::class)->import(base_path('tests/fixtures/boundaries.geojsonseq'));
    $rishton = Region::query()->where('name_uz', 'Rishton tumani')->firstOrFail();

    $branch = Branch::factory()->make();
    $branch->fillAreaFromRegion($rishton);
    $branch->save();

    $covered = DB::selectOne(
        'SELECT ST_Covers(service_area, ST_SetSRID(ST_MakePoint(71.2, 40.2), 4326)::geography) AS inside,
                ST_Covers(service_area, ST_SetSRID(ST_MakePoint(71.9, 40.9), 4326)::geography) AS outside
         FROM branches WHERE id = ?', [$branch->id]);

    expect($covered->inside)->toBeTrue()->and($covered->outside)->toBeFalse();
});

test('karakalpakstan (admin_level 3) is imported as a region with its districts', function () {
    $square = fn (float $x, float $y, float $size) => ['type' => 'MultiPolygon', 'coordinates' => [[[
        [$x, $y], [$x + $size, $y], [$x + $size, $y + $size], [$x, $y + $size], [$x, $y],
    ]]]];
    $line = fn (array $props, array $geometry) => "\x1E".json_encode(['type' => 'Feature', 'geometry' => $geometry, 'properties' => $props]);
    $file = tempnam(sys_get_temp_dir(), 'bnd');
    file_put_contents($file, implode("\n", [
        $line(['@type' => 'relation', '@id' => 196241, 'admin_level' => '3', 'ISO3166-2' => 'UZ-QR', 'name' => 'Qaraqalpaqstan Respublikası', 'name:uz' => 'Qoraqalpogʻiston Respublikasi'], $square(58.0, 42.0, 2.0)),
        $line(['@type' => 'relation', '@id' => 900001, 'admin_level' => '6', 'name' => 'Nókis rayonı'], $square(59.0, 42.5, 0.5)),
        $line(['@type' => 'relation', '@id' => 900002, 'admin_level' => '6', 'name' => 'Nókis'], $square(59.5, 42.3, 0.2)),
    ])."\n");

    $stats = app(OsmRegionImporter::class)->import($file);
    unlink($file);

    $republic = Region::query()->where('osm_id', 196241)->firstOrFail();
    expect($stats)->toBe(['regions' => 1, 'children' => 2])
        ->and($republic->type)->toBe(RegionType::Region)
        ->and($republic->name_uz)->toBe('Qoraqalpogʻiston Respublikasi')
        ->and($republic->children()->orderBy('name_uz')->get()->map(fn (Region $r) => [$r->name_uz, $r->type]))
        ->toEqual(collect([['Nókis', RegionType::City], ['Nókis rayonı', RegionType::District]]));
});
