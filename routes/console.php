<?php

use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Models\Call;
use App\Models\Driver;
use App\Models\SipTrunk;
use App\Models\User;
use App\Services\Dispatch\Dispatcher;
use App\Services\Drivers\DriverBalance;
use App\Services\Drivers\DriverHeartbeat;
use App\Services\Landmarks\OpenPlacesImporter;
use App\Services\Landmarks\OsmLandmarkImporter;
use App\Services\Orders\OrderService;
use App\Services\Regions\OsmRegionImporter;
use App\Services\Sounds\SoundLibrary;
use App\Services\Telephony\Extensions;
use App\Services\Telephony\TrunkSync;
use App\Support\Backup;
use App\Support\HealthCheck;
use App\Support\Settings;
use App\Support\TelegramAlert;
use App\Support\UzbekCyrillic;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
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

Artisan::command('landmarks:import
    {--file= : GeoJSON fayl (standart: storage/app/osm/pois.geojsonseq yoki places-<manba>.geojsonseq)}
    {--source=osm : osm | overture | wikidata (ochiq joylar — deploy/places-prepare.sh)}', function (OsmLandmarkImporter $osm, OpenPlacesImporter $places) {
    $file = $this->option('file');
    $source = $this->option('source');
    $source = is_string($source) ? $source : 'osm';

    if ($source === 'osm') {
        $stats = $osm->import(is_string($file) && $file !== '' ? $file : storage_path('app/osm/pois.geojsonseq'));
        $this->info("Tayyor: {$stats['read']} ta o'qildi, {$stats['imported']} ta mo'ljal (bazadagi shahar/tumanlar ichida).");

        return;
    }

    $stats = $places->import($source, is_string($file) && $file !== '' ? $file : storage_path("app/osm/places-{$source}.geojsonseq"));
    $this->info("Tayyor ({$source}): {$stats['read']} ta o'qildi, {$stats['imported']} ta mo'ljal, {$stats['duplicates']} ta takror (yaqinda shu nomli mo'ljal bor) o'tkazib yuborildi.");
})->purpose("OSM va ochiq manbalardagi (Overture, Wikidata) nomli joylarni mo'ljallar bazasiga import qilish");

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
    $orphans = $extensions->pruneOrphans();

    $this->info("Tayyor: {$users} foydalanuvchi va trunklar Asterisk Realtime'ga yozildi, {$orphans} ta eski ichki raqam o'chirildi.");
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

Artisan::command('ibtaxi:backup
    {--keep=14 : Necha kunlik zaxira saqlanadi}
    {--recordings-from= : Suhbat yozuvlarini shu kundan (Y-m-d) kechagacha zaxira serveriga yuborish (birinchi sozlashda)}', function (Backup $backup) {
    $file = $backup->create();
    $files = $backup->createFiles();
    $pruned = $backup->prune((int) $this->option('keep'));
    $this->info("Zaxira: {$file}, {$files} (o'chirildi: {$pruned} ta eski)");

    try {
        if ($backup->upload($file)) {
            $backup->upload($files);
            $this->info('Zaxira serveriga yuborildi.');

            // Yozuvlar serverning o'zida turibdi — mahalliy nusxa kerak emas, faqat zaxira serveriga (kunma-kun)
            $from = $this->option('recordings-from');
            $day = is_string($from) && $from !== '' ? CarbonImmutable::parse($from)->startOfDay() : CarbonImmutable::today()->subDay();
            // Oldingi kunlarda yuborilmay qolganlari ham qayta urinib ko'riladi
            $pending = File::glob($backup->directory().'/*_recordings.tar');
            for (; $day->lessThan(CarbonImmutable::today()); $day = $day->addDay()) {
                if (($archive = $backup->createRecordings($day)) && ! in_array($archive, $pending, true)) {
                    $pending[] = $archive;
                }
            }
            foreach ($pending as $archive) {
                $backup->upload($archive);
                File::delete($archive);
            }
            if ($pending !== []) {
                $this->info('Suhbat yozuvlari: '.count($pending).' kunlik arxiv yuborildi.');
            }
        }
    } catch (Throwable $e) {
        // Mahalliy zaxira baribir bor; monitoring (ibtaxi:health) ogohlantiradi
        Cache::forever('backup.upload_error', $e->getMessage());
        $this->error('Zaxira serveriga yuborilmadi: '.$e->getMessage());

        return;
    }
    Cache::forget('backup.upload_error');
})->purpose('Baza (pg_dump), fayllar va .env zaxirasi; suhbat yozuvlari — kunma-kun zaxira serveriga');

Artisan::command('ari:restart', function () {
    Cache::forever('ari:restart', now()->getTimestamp());
    $this->info('ari:listen qayta ishga tushadi (supervisor).');
})->purpose('ARI daemonini yangi kod bilan qayta ishga tushirish');

Schedule::command('ibtaxi:backup')->dailyAt('02:30')->withoutOverlapping();

Artisan::command('ibtaxi:map-export {--upload : Zaxira serveriga (SFTP) ham yuborish}', function (Backup $backup) {
    $file = $backup->createMap();
    if ($file === null) {
        $this->warn('Xarita tayyorlanmagan (public/map/region.pmtiles yo\'q) — to\'plam yaratilmadi.');

        return;
    }
    $this->info('Xarita to\'plami: '.$file.' ('.round(File::size($file) / 1048576).' MB)');
    $this->line('Yangi serverda: sudo ./deploy/install.sh --prod ... --map-bundle='.$file);

    if ($this->option('upload')) {
        try {
            if ($backup->upload($file)) {
                $this->info('Zaxira serveriga yuborildi.');
            }
        } catch (Throwable $e) {
            Cache::forever('backup.upload_error', $e->getMessage());
            $this->error('Zaxira serveriga yuborilmadi: '.$e->getMessage());
        }
    }
})->purpose('Xarita to\'plami (tile, OSM, OSRM) — boshqa serverga ko\'chirishda xarita qayta yasalmaydi');

// Har hafta: xarita deyarli o'zgarmaydi, lekin admin tuzatgan nomlar va yangilangan tile'lar ham saqlansin
Schedule::command('ibtaxi:map-export --upload')->weeklyOn(0, '03:00')->withoutOverlapping();

Artisan::command('orders:dispatch-scheduled', function (OrderService $orders) {
    $count = $orders->dispatchScheduled();
    if ($count > 0) {
        $this->info("Efirga chiqarildi: {$count} ta oldindan buyurtma.");
    }
})->purpose('Vaqti yaqinlashgan oldindan buyurtmalarni efirga chiqarish');

Schedule::command('orders:dispatch-scheduled')->everyMinute()->withoutOverlapping();

Artisan::command('ibtaxi:health', function (HealthCheck $health) {
    $problems = $health->run();
    $problems === [] ? $this->info('Hammasi joyida') : array_map(fn ($p) => $this->warn($p), $problems);
})->purpose('Tizim holatini tekshirish (Asterisk, trunk, OSRM, navbatlar, disk, zaxira)');

Schedule::command('ibtaxi:health')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('drivers:release-stuck', function () {
    // Faol buyurtmasi yo'q "band" haydovchi (masalan bekor qilish va qabul qilish bir vaqtga to'g'ri kelgan) —
    // aks holda u liniyadan ham chiqa olmaydi, buyurtma ham ololmaydi
    $released = Driver::query()->withoutGlobalScopes()
        ->where('status', DriverStatus::Busy->value)
        ->whereNotExists(fn ($q) => $q->from('orders')
            ->whereColumn('orders.driver_id', 'drivers.id')
            ->whereIn('orders.status', [OrderStatus::Assigned->value, OrderStatus::Arrived->value, OrderStatus::InProgress->value]))
        ->update(['status' => DriverStatus::Free->value]);

    if ($released > 0) {
        Log::warning('Band bo\'lib qolgan haydovchilar bo\'shatildi', ['count' => $released]);
        $this->info("Bo'shatildi: {$released}");
    }
})->purpose("Faol buyurtmasiz \"band\" qolib ketgan haydovchilarni bo'shatish");

Schedule::command('drivers:release-stuck')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('drivers:mark-offline', function (DriverHeartbeat $heartbeat) {
    $count = $heartbeat->markSilentOffline();
    if ($count > 0) {
        $this->info("Oflayn qilindi: {$count}");
    }
})->purpose("GPS yubormay qo'ygan \"bo'sh\" haydovchilarni oflayn qilish");

Schedule::command('drivers:mark-offline')->everyMinute()->withoutOverlapping();

Artisan::command('drivers:balance-check', function (DriverBalance $balance) {
    $mismatches = $balance->mismatches();
    if ($mismatches === []) {
        $this->info('Balanslar tarix bilan mos');

        return;
    }

    // Balans tarixsiz o'zgargan (qo'lda SQL, xato yoki poyga) — pul masalasi, firma darhol bilishi kerak
    Log::error('Haydovchi balansi tarix bilan mos emas', ['drivers' => $mismatches]);
    $lines = array_map(fn (array $m) => "#{$m['callsign']}: {$m['balance']} ≠ {$m['expected']}", array_slice($mismatches, 0, 20));
    TelegramAlert::send('⚠️ Haydovchi balansi tarix bilan mos emas ('.count($mismatches)." ta):\n".implode("\n", $lines));
    array_map(fn (string $line) => $this->warn($line), $lines);
})->purpose('Haydovchi balanslarini harakatlar tarixi bilan solishtirish');

Schedule::command('drivers:balance-check')->dailyAt('03:00')->withoutOverlapping();

Artisan::command('locations:prune', function (Settings $settings) {
    $days = (int) $settings->get('drivers.locations_retention_days');
    $deleted = 0;

    // Bo'laklab: katta jadvalni bitta so'rovda o'chirish uzoq qulf ushlaydi
    do {
        $batch = DB::delete('DELETE FROM driver_locations WHERE id IN (SELECT id FROM driver_locations WHERE recorded_at < ? LIMIT 20000)', [now()->subDays($days)]);
        $deleted += $batch;
    } while ($batch > 0);

    $this->info("O'chirildi: {$deleted} ta GPS nuqta ({$days} kundan eski).");
})->purpose("Eski GPS nuqtalarini o'chirish");

Schedule::command('locations:prune')->dailyAt('04:00')->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('auth:clear-resets')->daily();

Artisan::command('orders:rebroadcast', function (Dispatcher $dispatcher, OrderService $orders) {
    $expired = $orders->expireUnanswered();
    if ($expired > 0) {
        $this->info("Haydovchi topilmadi: {$expired} ta buyurtma.");
    }

    $offers = $dispatcher->rebroadcastExhausted();
    if ($offers > 0) {
        $this->info("Qayta efir: {$offers} ta taklif.");
    }
})->purpose('Hech kim olmagan buyurtmalarni qayta efirga chiqarish');

Schedule::command('orders:rebroadcast')->everyMinute()->withoutOverlapping();

Artisan::command('telephony:trunk-ips', function () {
    // deploy/sip-firewall.sh (root, cron) shu ro'yxat bo'yicha 5060/udp ni faqat trunk'larga ochadi
    SipTrunk::query()->where('is_active', true)->pluck('host')
        ->flatMap(fn (string $host) => filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []))
        ->unique()->sort()->each(fn (string $ip) => $this->line($ip));
})->purpose("Faol SIP trunk'larning IP manzillari (xavfsizlik devori uchun)");

Artisan::command('lang:cyrillic', function () {
    // Kirill tarjimalari qo'lda yozilmaydi — lotin (uz) dan yasaladi; uz o'zgarsa qayta ishga tushiriladi
    $json = json_decode((string) file_get_contents(lang_path('uz.json')), true, flags: JSON_THROW_ON_ERROR);
    $converted = array_map(fn ($value) => is_string($value) ? UzbekCyrillic::convert($value) : $value, $json);
    file_put_contents(lang_path('oz.json'), json_encode($converted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

    File::ensureDirectoryExists(lang_path('oz'));
    $walk = function (mixed $value) use (&$walk): mixed {
        return is_array($value) ? array_map($walk, $value) : (is_string($value) ? UzbekCyrillic::convert($value) : $value);
    };
    foreach (File::files(lang_path('uz')) as $file) {
        $data = $walk(require $file->getPathname());
        file_put_contents(lang_path('oz/'.$file->getFilename()), "<?php\n\n// lang/uz dan yasalgan: php artisan lang:cyrillic\nreturn ".var_export($data, true).";\n");
    }

    $this->info('Tayyor: lang/oz.json va lang/oz/*.php ('.count($converted).' ta matn)');
})->purpose("O'zbek kirill tarjimalarini lotindan yasash");
