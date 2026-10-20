<?php

use App\Support\Backup;
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
