<?php

use App\Models\Landmark;
use Clickbar\Magellan\Data\Geometries\Point;

test('map labels come from our landmarks, so a renamed place shows its new name', function () {
    Landmark::query()->create(['name' => 'HanBridge Academy', 'category' => 'amenity:college', 'aliases' => ['Makro'], 'location' => Point::makeGeodetic(40.3911, 71.7864)]);
    Landmark::query()->create(['name' => "Mustaqillik ko'chasi, 5", 'category' => 'address', 'aliases' => [], 'location' => Point::makeGeodetic(40.3912, 71.7865)]);

    // 40.3911, 71.7864 — 16-zoom'dagi bo'lak
    $response = $this->get('/map/landmarks/16/45836/24717.pbf')->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.mapbox-vector-tile');

    expect($response->getContent())->toContain('HanBridge Academy')
        ->and($response->getContent())->toContain('school')
        // Uy manzillari xaritada allaqachon bor — joy qatlamiga kirmaydi
        ->and($response->getContent())->not->toContain('Mustaqillik');

    // Kichik zoom'da yozuv yo'q
    expect($this->get('/map/landmarks/10/716/385.pbf')->assertOk()->getContent())->toBe('');
});

test('map style draws place labels from the landmarks tiles', function () {
    $style = $this->getJson('/map/style.json')->assertOk()->json();
    $pois = collect($style['layers'])->firstWhere('id', 'pois');

    expect($style['sources'])->toHaveKey('dtaxi')
        ->and($style['sources']['dtaxi']['tiles'][0])->toContain('/map/landmarks/{z}/{x}/{y}.pbf')
        ->and($pois['source'])->toBe('dtaxi')
        ->and($pois['layout']['text-field'])->toBe(['get', 'name']);
});
