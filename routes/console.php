<?php

use App\Models\Call;
use App\Models\SipTrunk;
use App\Models\User;
use App\Services\Landmarks\OsmLandmarkImporter;
use App\Services\Regions\OsmRegionImporter;
use App\Services\Sounds\SoundLibrary;
use App\Services\Telephony\Extensions;
use App\Services\Telephony\TrunkSync;
use App\Support\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

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

Artisan::command('sounds:generate {--force : Hammasini qayta generatsiya qilish}', function (SoundLibrary $library) {
    $count = $library->generateMissing((bool) $this->option('force'), fn (string $line) => $this->line($line));

    $this->info("Tayyor: {$count} ta bo'lak generatsiya qilindi.");
})->purpose("Ovozli bo'laklarni SpeechKit orqali generatsiya qilish (faqat yetishmayotganlari)");

Artisan::command('telephony:sync', function (Extensions $extensions, TrunkSync $trunks) {
    $users = 0;
    User::query()->each(function (User $user) use ($extensions, &$users) {
        $extensions->syncFor($user);
        $users++;
    });
    SipTrunk::query()->each(fn (SipTrunk $trunk) => $trunks->sync($trunk));

    $this->info("Tayyor: {$users} foydalanuvchi va trunklar Asterisk Realtime'ga yozildi.");
})->purpose('Ichki raqamlar va SIP trunklarni Asterisk jadvallariga qayta yozish');

Artisan::command('recordings:cleanup', function (Settings $settings) {
    $days = (int) $settings->get('calls.recordings_retention_days');
    $deleted = 0;

    Call::query()->withoutGlobalScopes()
        ->whereNotNull('recording_path')
        ->where('started_at', '<', now()->subDays($days))
        ->chunkById(500, function ($calls) use (&$deleted) {
            foreach ($calls as $call) {
                Storage::delete((string) $call->recording_path);
                $call->forceFill(['recording_path' => null])->save();
                $deleted++;
            }
        });

    $this->info("O'chirildi: {$deleted} ta yozuv ({$days} kundan eski).");
})->purpose("Muddati o'tgan suhbat yozuvlarini o'chirish (qaror #34)");

Schedule::command('recordings:cleanup')->dailyAt('03:30')->withoutOverlapping();
