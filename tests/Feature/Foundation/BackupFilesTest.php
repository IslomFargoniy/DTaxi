<?php

use App\Support\Backup;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

test('files backup contains .env and private uploads but not older backups', function () {
    $marker = storage_path('app/private/backup-test-marker.txt');
    File::put($marker, 'hujjat');

    $archive = app(Backup::class)->createFiles('test');

    try {
        $list = Process::run(['tar', '--list', '--gzip', '--file='.$archive])->output();

        expect($list)->toContain('.env')
            ->and($list)->toContain('storage/app/private/backup-test-marker.txt')
            ->and($list)->not->toContain('storage/app/private/backups/')
            ->and(substr(sprintf('%o', fileperms($archive)), -3))->toBe('600');
    } finally {
        File::delete([$archive, $marker]);
    }
});

test('map bundle holds tiles, OSM exports and OSRM data, keeps only the latest locally', function () {
    $root = storage_path('framework/testing/map-root');
    $osrm = storage_path('framework/testing/var/osrm');
    File::ensureDirectoryExists($root.'/public/map/fonts');
    File::ensureDirectoryExists($root.'/storage/app/osm');
    File::ensureDirectoryExists($osrm);
    File::put($root.'/public/map/region.pmtiles', 'tiles');
    File::put($root.'/public/map/region.pmtiles.bak', 'old tiles');
    File::put($root.'/public/map/fonts/a.pbf', 'font');
    File::put($root.'/storage/app/osm/pois.geojsonseq', '{}');
    File::put($osrm.'/area.osrm.mldgr', 'graph');
    config(['services.osrm.data_dir' => $osrm]);
    // pg_dump Mac'da yo'q — faylni o'zi yozadi; tar haqiqiy
    $dumps = [];
    Process::fake(['*pg_dump*' => function (PendingProcess $process) use (&$dumps) {
        $dumps[] = $process->command;
        $file = substr((string) collect($process->command)->first(fn (string $arg) => str_starts_with($arg, '--file=')), 7);
        File::put($file, 'PGDMP');

        return Process::result();
    }]);

    // Alohida papka: createMap eski *_map.tar'larni o'chiradi — haqiqiy backups/ dagi to'plam test tufayli yo'qolardi
    $directory = storage_path('framework/testing/map-backups');
    $backup = Mockery::mock(Backup::class)->makePartial();
    $backup->shouldReceive('directory')->andReturn($directory);
    $real = storage_path('app/private/backups/2000-01-01_000000_map.tar');
    File::ensureDirectoryExists(dirname($real));
    File::put($real, 'firma to\'plami');

    $first = $backup->createMap($root);
    $this->travel(1)->seconds();
    $second = $backup->createMap($root);

    try {
        $list = Process::run(['tar', '--list', '--file='.$second])->output();

        expect($list)->toContain('public/map/region.pmtiles')
            ->and($list)->toContain('public/map/fonts/a.pbf')
            ->and($list)->toContain('storage/app/osm/pois.geojsonseq')
            ->and($list)->toContain('osrm/area.osrm.mldgr')
            ->and(explode("\n", $list))->toContain('map.dump')
            ->and($dumps[0])->toContain('--data-only', '--table=public.regions', '--table=public.landmarks', '--table=public.street_renames')
            ->and(File::isDirectory($backup->directory().'/map-export-tmp'))->toBeFalse()
            ->and($list)->not->toContain('.bak')
            ->and(File::exists($first))->toBeFalse()
            ->and(dirname($second))->toBe($directory)
            ->and(File::exists($real))->toBeTrue()
            ->and(substr(sprintf('%o', fileperms($second)), -3))->toBe('600');
    } finally {
        File::delete([$second, $real]);
        File::deleteDirectory($directory);
        File::deleteDirectory(storage_path('framework/testing/map-root'));
        File::deleteDirectory(storage_path('framework/testing/var'));
    }
});

test('no map bundle without prepared tiles', function () {
    expect(app(Backup::class)->createMap(storage_path('framework/testing/empty-root')))->toBeNull();
});
