<?php

use App\Models\Landmark;
use App\Services\Landmarks\OpenPlacesImporter;
use App\Services\Regions\OsmRegionImporter;
use Clickbar\Magellan\Data\Geometries\Point;

beforeEach(function () {
    app(OsmRegionImporter::class)->import(base_path('tests/fixtures/boundaries.geojsonseq'));
    // OSM'dagi mo'ljal — Overture'dagi "Chorsu Bozori" undan ~45 m narida (takror)
    $osm = Landmark::query()->create(['name' => 'Chorsu bozori', 'aliases' => ['Чорсу базари'], 'location' => Point::makeGeodetic(40.2050, 71.2050)]);
    $osm->forceFill(['source' => 'osm', 'osm_id' => 10])->save();
});

test('open places are imported with mapped categories and aliases, duplicates of OSM are skipped', function () {
    $stats = app(OpenPlacesImporter::class)->import('overture', base_path('tests/fixtures/places-overture.geojsonseq'));

    // ov-5 — shahar/tumandan tashqarida, ov-6 — nomsiz
    expect($stats)->toBe(['read' => 5, 'imported' => 3, 'duplicates' => 1]);

    $places = Landmark::query()->where('source', 'overture')->get()->keyBy('external_id');
    expect($places->keys()->sort()->values()->all())->toBe(['ov-1', 'ov-2', 'ov-4'])
        ->and($places['ov-1']->category)->toBe('shop:supermarket')
        ->and($places['ov-2']->category)->toBe('amenity:restaurant')
        ->and($places['ov-2']->aliases)->toBe(['Белла Пицца'])
        ->and($places['ov-4']->category)->toBe('overture:home_service')
        ->and($places['ov-1']->region_id)->not->toBeNull()
        ->and($places['ov-1']->osm_id)->toBeNull();

    // Ruscha nom bilan ham topiladi
    expect(Landmark::query()->search('белла пицца')->first()?->external_id)->toBe('ov-2')
        ->and(Landmark::query()->where('name', 'like', 'Chorsu%')->count())->toBe(1);
});

test('reimport updates by external id and keeps admin corrections', function () {
    $importer = app(OpenPlacesImporter::class);
    $importer->import('overture', base_path('tests/fixtures/places-overture.geojsonseq'));

    $edited = Landmark::query()->where('external_id', 'ov-1')->sole();
    $edited->forceFill(['name' => 'Korzinka (Mustaqillik)', 'edited_at' => now()])->save();
    Landmark::query()->where('external_id', 'ov-2')->update(['name' => 'Eski nom']);

    expect($importer->import('overture', base_path('tests/fixtures/places-overture.geojsonseq')))
        ->toBe(['read' => 5, 'imported' => 3, 'duplicates' => 1])
        ->and(Landmark::query()->where('source', 'overture')->count())->toBe(3)
        ->and($edited->refresh()->name)->toBe('Korzinka (Mustaqillik)')
        ->and(Landmark::query()->where('external_id', 'ov-2')->value('name'))->toBe('Bella Pizza');
});

test('categories map to the OSM style or fall back to the source category', function () {
    expect(OpenPlacesImporter::category('overture', ['italian_restaurant', 'restaurant']))->toBe('amenity:restaurant')
        ->and(OpenPlacesImporter::category('wikidata', ['muslim_place_of_worship']))->toBe('amenity:place_of_worship')
        ->and(OpenPlacesImporter::category('wikidata', ['madrasa']))->toBe('wikidata:madrasa')
        ->and(OpenPlacesImporter::category('overture', []))->toBe('overture:place');
});

test('manually added landmarks are marked as manual, unknown sources are rejected', function () {
    expect(Landmark::factory()->create()->refresh()->source)->toBe('manual');

    app(OpenPlacesImporter::class)->import('yandex', base_path('tests/fixtures/places-overture.geojsonseq'));
})->throws(InvalidArgumentException::class);
