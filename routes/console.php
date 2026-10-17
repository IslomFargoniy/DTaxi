<?php

use App\Services\Landmarks\OsmLandmarkImporter;
use App\Services\Regions\OsmRegionImporter;
use Illuminate\Support\Facades\Artisan;

Artisan::command('regions:import
    {--file= : GeoJSON fayl (standart: storage/app/osm/boundaries.geojsonseq)}
    {--region=* : Faqat shu viloyat(lar)ning shahar va tumanlari, masalan --region=Fargona}', function (OsmRegionImporter $importer) {
    $file = $this->option('file');
    $regions = $this->option('region');

    $stats = $importer->import(
        is_string($file) && $file !== '' ? $file : storage_path('app/osm/boundaries.geojsonseq'),
        array_values(array_filter((array) $regions, 'is_string')),
    );

    $this->info("Tayyor: {$stats['regions']} viloyat, {$stats['children']} shahar/tuman.");
})->purpose("O'zbekiston viloyat, shahar va tumanlarini OSM ma'lumotidan import qilish");

Artisan::command('landmarks:import {--file= : GeoJSON fayl (standart: storage/app/osm/pois.geojsonseq)}', function (OsmLandmarkImporter $importer) {
    $file = $this->option('file');
    $stats = $importer->import(is_string($file) && $file !== '' ? $file : storage_path('app/osm/pois.geojsonseq'));

    $this->info("Tayyor: {$stats['read']} ta o'qildi, {$stats['imported']} ta mo'ljal (bazadagi shahar/tumanlar ichida).");
})->purpose("OSM'dagi nomli joylarni mo'ljallar bazasiga import qilish");
