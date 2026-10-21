<?php

use App\Enums\RegionType;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Landmark;
use App\Models\Region;
use App\Models\StreetRename;
use App\Models\User;
use App\Services\Landmarks\OsmLandmarkImporter;
use App\Services\Regions\OsmRegionImporter;
use Database\Seeders\RolesSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../Orders/helpers.php';

beforeEach(function () {
    Queue::fake();
    $this->seed(RolesSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->admin = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Admin);
});

test('only admins manage street renames', function () {
    $operator = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Operator);

    $this->actingAs($operator)->get(route('admin.street-renames.index'))->assertForbidden();
    $this->actingAs($operator)->post(route('admin.street-renames.store'), ['old_name' => 'Kirov', 'new_name' => 'Navoiy'])->assertForbidden();

    $this->actingAs($this->admin)->get(route('admin.street-renames.index'))->assertOk();
    $this->actingAs($this->admin)->post(route('admin.street-renames.store'), ['old_name' => 'Kirov', 'new_name' => 'Navoiy'])
        ->assertSessionHasNoErrors();
    // Bir xil eski nom (hududsiz) ikki marta kiritilmaydi
    $this->actingAs($this->admin)->post(route('admin.street-renames.store'), ['old_name' => 'Kirov', 'new_name' => 'Boshqa'])
        ->assertSessionHasErrors('old_name');

    $rename = StreetRename::query()->sole();
    expect($rename->created_by)->toBe($this->admin->id);

    $this->actingAs($this->admin)->put(route('admin.street-renames.update', $rename), ['old_name' => 'Kirov', 'new_name' => 'Alisher Navoiy'])
        ->assertSessionHasNoErrors();
    expect($rename->fresh()?->new_name)->toBe('Alisher Navoiy');

    $this->actingAs($this->admin)->delete(route('admin.street-renames.destroy', $rename))->assertRedirect();
    expect(StreetRename::query()->count())->toBe(0);
});

test('old name suggestions come from street landmarks and their aliases', function () {
    Landmark::factory()->create(['name' => "Kirov ko'chasi", 'aliases' => ['улица Кирова'], 'category' => 'street']);
    Landmark::factory()->create(['name' => 'Kirov bozori', 'category' => 'amenity:marketplace']);

    $this->actingAs($this->admin)->getJson(route('admin.street-renames.suggest', ['q' => 'kirov']))
        ->assertOk()->assertExactJson(["Kirov ko'chasi", 'улица Кирова']);
});

test('renaming a street updates street and address landmarks and deleting reverts them', function () {
    $street = Landmark::factory()->create(['name' => "Kirov ko'chasi", 'aliases' => ['улица Кирова'], 'category' => 'street']);
    $house = Landmark::factory()->create(['name' => "Kirov ko'chasi, 12", 'category' => 'address']);
    $other = Landmark::factory()->create(['name' => "Kirovobod ko'chasi, 3", 'category' => 'address']);

    $this->actingAs($this->admin)->post(route('admin.street-renames.store'), ['old_name' => "Kirov ko'chasi", 'new_name' => "Navoiy ko'chasi"])
        ->assertSessionHasNoErrors();

    $street->refresh();
    $house->refresh();
    expect($street->name)->toBe("Navoiy ko'chasi")
        ->and($street->aliases)->toBe(['улица Кирова', "Kirov ko'chasi"])
        ->and($street->edited_at)->not->toBeNull()
        ->and($house->name)->toBe("Navoiy ko'chasi, 12")
        ->and($house->aliases)->toBe(["Kirov ko'chasi, 12"])
        ->and($house->edited_at)->not->toBeNull()
        ->and($other->fresh()?->name)->toBe("Kirovobod ko'chasi, 3")
        // Yangi nom bilan ham, eski nom bilan ham topiladi
        ->and(Landmark::query()->search('navoiy 12')->first()?->id)->toBe($house->id)
        ->and(Landmark::query()->search('kirov 12')->first()?->id)->toBe($house->id);

    $this->actingAs($this->admin)->delete(route('admin.street-renames.destroy', StreetRename::query()->sole()));

    expect($street->fresh()?->name)->toBe("Kirov ko'chasi")
        ->and($street->fresh()?->aliases)->toBe(['улица Кирова'])
        ->and($house->fresh()?->name)->toBe("Kirov ko'chasi, 12")
        ->and($house->fresh()?->aliases)->toBe([]);
});

test('a rename limited to a region leaves the same street name elsewhere untouched', function () {
    $viloyat = Region::query()->create(['type' => RegionType::Region, 'name_uz' => 'Viloyat']);
    $city = Region::query()->create(['type' => RegionType::City, 'name_uz' => 'Shahar', 'parent_id' => $viloyat->id]);
    $other = Region::query()->create(['type' => RegionType::City, 'name_uz' => 'Boshqa', 'parent_id' => null]);
    $here = Landmark::factory()->create(['name' => 'Lenin', 'category' => 'street', 'region_id' => $city->id]);
    $there = Landmark::factory()->create(['name' => 'Lenin', 'category' => 'street', 'region_id' => $other->id]);

    $this->actingAs($this->admin)->post(route('admin.street-renames.store'), ['old_name' => 'Lenin', 'new_name' => 'Mustaqillik', 'region_id' => $viloyat->id])
        ->assertSessionHasNoErrors();

    expect($here->fresh()?->name)->toBe('Mustaqillik')
        ->and($there->fresh()?->name)->toBe('Lenin');
});

test('OSM import applies street renames to newly imported streets and addresses', function () {
    StreetRename::query()->create(['old_name' => "Kirov ko'chasi", 'new_name' => "Navoiy ko'chasi"]);
    app(OsmRegionImporter::class)->import(base_path('tests/fixtures/boundaries.geojsonseq'));
    $city = Region::query()->whereNotNull('parent_id')->orderBy('name_uz')->firstOrFail();
    $lng = $city->center->getLongitude();
    $lat = $city->center->getLatitude();
    $file = storage_path('app/street-renames-test.geojsonseq');
    File::put($file, implode("\n", [
        json_encode(['type' => 'Feature', 'geometry' => ['type' => 'LineString', 'coordinates' => [[$lng, $lat], [$lng + 0.001, $lat]]],
            'properties' => ['@type' => 'way', '@id' => 5, 'name' => "Kirov ko'chasi", 'highway' => 'residential']]),
        json_encode(['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [$lng, $lat]],
            'properties' => ['@type' => 'node', '@id' => 6, 'addr:street' => "Kirov ko'chasi", 'addr:housenumber' => '7']]),
    ]));

    try {
        app(OsmLandmarkImporter::class)->import($file);
        // Qayta import nomni qaytarmaydi
        app(OsmLandmarkImporter::class)->import($file);
    } finally {
        File::delete($file);
    }

    expect(Landmark::query()->where('category', 'street')->sole()->only(['name', 'aliases']))
        ->toBe(['name' => "Navoiy ko'chasi", 'aliases' => ["Kirov ko'chasi"]])
        ->and(Landmark::query()->where('category', 'address')->sole()->only(['name', 'aliases']))
        ->toBe(['name' => "Navoiy ko'chasi, 7", 'aliases' => ["Kirov ko'chasi, 7"]]);
});

test('map style shows the new name in road labels', function () {
    $this->actingAs($this->admin)->post(route('admin.street-renames.store'), ['old_name' => "Kirov ko'chasi", 'new_name' => "Navoiy ko'chasi"]);

    $layers = collect($this->getJson('/map/style.json?lang=ru')->assertOk()->json('layers'))->keyBy('id');
    $field = $layers['roads_labels_minor']['layout']['text-field'];

    expect($field[0])->toBe('match')
        ->and($field[1])->toBe(['coalesce', ['get', 'name:ru'], ['get', 'name'], ''])
        ->and($field[2])->toBe("Kirov ko'chasi")
        ->and($field[3])->toBe(['format', "Navoiy ko'chasi", []])
        ->and($field[4][0])->toBe('match')
        ->and($layers['roads_labels_major']['layout']['text-field'][0])->toBe('match')
        ->and($layers['roads_shields']['layout']['text-field'])->toBe(['get', 'shield_text']);

    $this->getJson('/map/street-renames.json')->assertOk()->assertExactJson(["Kirov ko'chasi" => "Navoiy ko'chasi"]);
});

test('map style keeps original road labels when nothing is renamed', function () {
    $field = collect($this->getJson('/map/style.json')->assertOk()->json('layers'))->keyBy('id')['roads_labels_minor']['layout']['text-field'];

    expect($field[0])->toBe('case');
    $this->getJson('/map/street-renames.json')->assertOk()->assertExactJson([]);
});

test('driver route steps use the new street name', function () {
    StreetRename::query()->create(['old_name' => 'Kirov', 'new_name' => 'Navoiy']);
    Http::swap(new Factory);
    Http::fake(['*/route/v1/driving/*' => Http::response(['code' => 'Ok', 'routes' => [[
        'distance' => 700, 'duration' => 90,
        'geometry' => ['coordinates' => [[71.78, 40.38], [71.79, 40.39]]],
        'legs' => [['steps' => [
            ['name' => 'Mustaqillik', 'distance' => 500, 'maneuver' => ['type' => 'depart', 'location' => [71.78, 40.38]]],
            ['name' => 'Kirov', 'distance' => 200, 'maneuver' => ['type' => 'turn', 'modifier' => 'left', 'location' => [71.785, 40.385]]],
        ]]],
    ]]])]);
    Sanctum::actingAs(driverNear($this->branch, 0)->user, ['driver']);

    $this->getJson('/api/driver/v1/route?from_lat=40.38&from_lng=71.78&to_lat=40.39&to_lng=71.79')->assertOk()
        ->assertJsonPath('steps.0.name', 'Mustaqillik')
        ->assertJsonPath('steps.1.name', 'Navoiy');
});
