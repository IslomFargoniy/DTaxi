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
