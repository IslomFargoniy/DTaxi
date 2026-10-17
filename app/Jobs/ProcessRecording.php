<?php

namespace App\Jobs;

use App\Models\Call;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Suhbat yozuvi (qaror #11): Asterisk WAV → MP3 mono (~0.12 MB/daqiqa) → storage/app/private/recordings/{filial}/{Y}/{m}/{d}/.
 * Fayl faqat avtorizatsiyadan o'tgan admin uchun Laravel orqali beriladi.
 */
class ProcessRecording implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public int $callId)
    {
        $this->onQueue('calls');
    }

    public function handle(): void
    {
        $call = Call::query()->withoutGlobalScopes()->find($this->callId);
        $name = $call ? data_get($call->meta, 'recording') : null;
        if (! $call || ! is_string($name)) {
            return;
        }

        $source = rtrim((string) config('services.asterisk.recordings_dir'), '/')."/{$name}.wav";
        if (! is_file($source)) {
            // Asterisk faylni hali yopmagan bo'lishi mumkin — qayta urinish; oxirida jim chiqiladi
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff);
            } else {
                Log::warning('Yozuv topilmadi', ['call' => $call->id, 'file' => $source]);
            }

            return;
        }

        $relative = sprintf('recordings/%s/%s/%d.mp3', $call->branch_id ?? 'common', $call->started_at->format('Y/m/d'), $call->id);
        $target = Storage::path($relative);
        @mkdir(dirname($target), 0775, true);

        $result = Process::timeout(120)->run(['ffmpeg', '-y', '-loglevel', 'error', '-i', $source, '-ac', '1', '-codec:a', 'libmp3lame', '-b:a', '16k', $target]);
        if (! $result->successful()) {
            throw new \RuntimeException('ffmpeg: '.$result->errorOutput());
        }

        $call->forceFill(['recording_path' => $relative])->save();
        @unlink($source);
    }
}
