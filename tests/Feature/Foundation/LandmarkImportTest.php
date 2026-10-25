<?php

use App\Enums\Role;
use App\Models\Landmark;
use App\Models\Region;
use App\Models\User;
use App\Services\Landmarks\OsmLandmarkImporter;
use App\Services\Regions\OsmRegionImporter;
use Clickbar\Magellan\Data\Geometries\Point;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
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
        ->toBe(["Mustaqillik ko'chasi, 112", "Mustaqillik ko'chasi, 12"])
        // Nomli joy o'z turida (xaritadagi joy yozuvlarida ko'rinadi), manzili — qidiruv uchun muqobil nom
        ->and(Landmark::query()->where('name', 'Makro')->value('category'))->toBe('shop:supermarket')
        ->and(Landmark::query()->where('name', 'Makro')->value('aliases'))->toContain("Mustaqillik ko'chasi, 5")
        ->and(Landmark::query()->where('category', 'street')->count())->toBe(1);

    // Ko'cha va uy raqami bilan, kirillda ham — aniq uy birinchi
    expect(Landmark::query()->search('mustaqillik 12')->first()?->name)->toBe("Mustaqillik ko'chasi, 12")
        ->and(Landmark::query()->search('Мустакиллик 12')->first()?->name)->toBe("Mustaqillik ko'chasi, 12");
});

test('a landmark renamed by the admin keeps its new name after the next OSM import', function () {
    app(OsmRegionImporter::class)->import(base_path('tests/fixtures/boundaries.geojsonseq'));
    $city = Region::query()->whereNotNull('parent_id')->orderBy('name_uz')->firstOrFail();
    $file = storage_path('app/rename-test.geojsonseq');
    File::put($file, json_encode([
        'type' => 'Feature',
        'geometry' => ['type' => 'Point', 'coordinates' => [$city->center->getLongitude(), $city->center->getLatitude()]],
        'properties' => ['@type' => 'node', '@id' => 77, 'name' => 'Kirov bozori', 'amenity' => 'marketplace'],
    ]));

    try {
        app(OsmLandmarkImporter::class)->import($file);
        $landmark = Landmark::query()->where('name', 'Kirov bozori')->sole();

        $admin = User::factory()->create();
        $this->seed(RolesSeeder::class);
        $admin->assignRole(Role::Superadmin);
        $this->actingAs($admin)->put(route('admin.landmarks.update', $landmark), [
            'name' => 'Yangi bozor', 'aliases' => [], 'lat' => $landmark->location->getLatitude(), 'lng' => $landmark->location->getLongitude(),
        ])->assertSessionHasNoErrors();

        app(OsmLandmarkImporter::class)->import($file);
    } finally {
        File::delete($file);
    }

    $landmark->refresh();
    expect($landmark->name)->toBe('Yangi bozor')
        ->and($landmark->aliases)->toContain('Kirov bozori')
        ->and($landmark->edited_at)->not->toBeNull()
        // Eski nom bilan qidirganda ham topiladi
        ->and(Landmark::query()->search('kirov bozori')->first()?->id)->toBe($landmark->id);
});

test('typo search uses the 0.4 word similarity threshold set on every connection, with JIT off', function () {
    $landmark = Landmark::query()->create(['name' => 'Mustaqillik maydoni', 'location' => Point::makeGeodetic(40.3864, 71.7864)]);
    $similarity = (float) DB::scalar("SELECT word_similarity('mustakilik', search) FROM landmarks WHERE id = ?", [$landmark->id]);

    // Standart chegara (0.6) bilan topilmasdi — sozlama haqiqatan qo'llangan
    expect($similarity)->toBeGreaterThan(0.4)->toBeLessThan(0.6)
        ->and(DB::scalar('SHOW pg_trgm.word_similarity_threshold'))->toBe('0.4')
        ->and(DB::scalar('SHOW jit'))->toBe('off')
        ->and(Landmark::query()->search('mustakilik')->first()?->id)->toBe($landmark->id);
});
