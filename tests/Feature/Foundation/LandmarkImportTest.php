<?php

use App\Models\Landmark;
use App\Models\Region;
use App\Services\Landmarks\OsmLandmarkImporter;
use App\Services\Regions\OsmRegionImporter;
use Illuminate\Support\Facades\File;

test('house addresses and streets are imported and found by street and house number', function () {
    app(OsmRegionImporter::class)->import(base_path('tests/fixtures/boundaries.geojsonseq'));
    $city = Region::query()->whereNotNull('parent_id')->orderBy('name_uz')->firstOrFail();
    $lng = $city->center->getLongitude();
    $lat = $city->center->getLatitude();

    $feature = fn (int $id, array $tags, string $type = 'Point', array $coordinates = []) => json_encode([
        'type' => 'Feature',
        'geometry' => ['type' => $type, 'coordinates' => $coordinates ?: [$lng, $lat]],
        'properties' => ['@type' => 'node', '@id' => $id, ...$tags],
    ]);
    $file = storage_path('app/addresses-test.geojsonseq');
    File::put($file, implode("\n", [
        $feature(1, ['addr:street' => "Mustaqillik ko'chasi", 'addr:housenumber' => '12']),
        $feature(2, ['addr:street' => "Mustaqillik ko'chasi", 'addr:housenumber' => '112']),
        $feature(3, ['name' => 'Ahmad al-Farg\'oniy ko\'chasi', 'highway' => 'residential'], 'LineString', [[$lng, $lat], [$lng + 0.001, $lat]]),
        $feature(4, ['name' => 'Makro', 'shop' => 'supermarket', 'addr:street' => "Mustaqillik ko'chasi", 'addr:housenumber' => '5']),
    ]));

    try {
        expect(app(OsmLandmarkImporter::class)->import($file))->toBe(['read' => 4, 'imported' => 4]);
    } finally {
        File::delete($file);
    }

    expect(Landmark::query()->where('category', 'address')->pluck('name')->sort()->values()->all())
        ->toBe(['Makro', "Mustaqillik ko'chasi, 112", "Mustaqillik ko'chasi, 12"])
        ->and(Landmark::query()->where('name', 'Makro')->value('aliases'))->toContain("Mustaqillik ko'chasi, 5")
        ->and(Landmark::query()->where('category', 'street')->count())->toBe(1);

    // Ko'cha va uy raqami bilan, kirillda ham — aniq uy birinchi
    expect(Landmark::query()->search('mustaqillik 12')->first()?->name)->toBe("Mustaqillik ko'chasi, 12")
        ->and(Landmark::query()->search('Мустакиллик 12')->first()?->name)->toBe("Mustaqillik ko'chasi, 12");
});
