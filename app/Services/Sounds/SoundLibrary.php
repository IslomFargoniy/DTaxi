<?php

namespace App\Services\Sounds;

use App\Models\Branch;
use App\Models\SoundClip;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Ovozli bo'laklar kutubxonasi: storage/app/private/sounds/uz/<kategoriya>/<kalit>.sln
 * (Asterisk'da /usr/share/asterisk/sounds/dtaxi → shu papka, install.sh).
 */
class SoundLibrary
{
    public const DISK_DIR = 'sounds/uz';

    public function __construct(private SpeechKit $tts) {}

    /**
     * Kerakli barcha bo'laklar ro'yxati: katalog + mashina modellari/ranglari + filial salomlashuvi va nomi.
     *
     * @return list<array{string, string, string}> [kategoriya, kalit, matn]
     */
    public function required(): array
    {
        $items = [];
        foreach (SoundCatalog::all() as $category => $keys) {
            foreach ($keys as $key => $text) {
                $items[] = [$category, (string) $key, $text];
            }
        }

        foreach (Vehicle::query()->distinct()->pluck('model') as $model) {
            $items[] = ['car', SoundCatalog::key((string) $model), (string) $model];
        }
        foreach (Vehicle::query()->distinct()->pluck('color') as $color) {
            $items[] = ['color', SoundCatalog::key((string) $color), (string) $color];
        }
        foreach (Branch::query()->withoutGlobalScopes()->get() as $branch) {
            $items[] = ['greeting', "branch-{$branch->id}", $this->greeting($branch)];
            $items[] = ['branch', "branch-{$branch->id}", $branch->name];
        }

        return $items;
    }

    /** Filial salomlashuvi (qaror #19): admin matni yoki xizmat nomi bilan standart */
    public function greeting(Branch $branch): string
    {
        return $branch->greeting_text
            ?: 'Assalomu alaykum! '.($branch->service_name ?: $branch->name).' xizmatiga xush kelibsiz.';
    }

    /**
     * @return int yaratilgan bo'laklar soni
     */
    public function generateMissing(bool $force = false, ?callable $progress = null): int
    {
        $count = 0;
        foreach ($this->required() as [$category, $key, $text]) {
            $clip = SoundClip::query()->firstOrNew(['category' => $category, 'key' => $key]);
            // Matn o'zgarmagan va fayl bor — qayta generatsiya qilinmaydi
            if (! $force && $clip->exists && $clip->text === $text && $clip->file_path && Storage::exists($this->path((string) $clip->file_path))) {
                continue;
            }
            $this->generate($clip->fill(['text' => $text]));
            $progress && $progress("{$category}/{$key}: {$text}");
            $count++;
        }

        return $count;
    }

    public function generate(SoundClip $clip): SoundClip
    {
        $this->store($clip, $this->tts->synthesize($clip->text));

        return $clip;
    }

    /**
     * Admin o'z faylini yuklaydi (diktor yozuvi): ffmpeg bilan 8 kHz mono PCM'ga o'giriladi.
     */
    public function upload(SoundClip $clip, UploadedFile $file): SoundClip
    {
        $result = Process::run(['ffmpeg', '-v', 'error', '-i', $file->getRealPath(), '-ar', '8000', '-ac', '1', '-f', 's16le', '-']);
        if ($result->failed() || $result->output() === '') {
            throw new RuntimeException('Audio faylni o\'girib bo\'lmadi: '.$result->errorOutput());
        }
        $this->store($clip, $result->output());

        return $clip;
    }

    public function path(string $relative): string
    {
        return self::DISK_DIR.'/'.$relative.'.sln';
    }

    private function store(SoundClip $clip, string $pcm): void
    {
        $relative = "{$clip->category}/{$clip->key}";
        Storage::put($this->path($relative), $pcm);
        // Local disk 0600/0700 yaratadi — Asterisk (ilova guruhida) bo'lakni o'qiy olmasdi; boshqa private fayllar yopiq qoladi
        $file = Storage::path($this->path($relative));
        @chmod(dirname($file), 0750);
        @chmod($file, 0640);

        $clip->fill([
            'file_path' => $relative,
            // 8000 namuna/s × 2 bayt
            'duration_ms' => (int) round(strlen($pcm) / 16),
            'generated_at' => now(),
        ])->save();
    }
}
