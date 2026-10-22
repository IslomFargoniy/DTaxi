<?php

use App\Models\SoundClip;
use App\Services\Sounds\SoundLibrary;
use App\Services\Sounds\SpeechKit;
use Illuminate\Support\Facades\Storage;

test('generated clips are readable by the asterisk group but not by others', function () {
    Storage::fake('local');
    $tts = Mockery::mock(SpeechKit::class);
    $tts->shouldReceive('synthesize')->andReturn(str_repeat("\0", 1600));
    app()->instance(SpeechKit::class, $tts);

    $clip = app(SoundLibrary::class)->generate(new SoundClip(['category' => 'ivr', 'key' => 'welcome', 'text' => 'Salom']));

    $file = Storage::path(app(SoundLibrary::class)->path((string) $clip->file_path));
    expect(fileperms($file) & 0777)->toBe(0640)
        ->and(fileperms(dirname($file)) & 0777)->toBe(0750)
        ->and($clip->duration_ms)->toBe(100);
});
