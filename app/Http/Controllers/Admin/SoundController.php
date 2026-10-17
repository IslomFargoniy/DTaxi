<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SoundClip;
use App\Services\Sounds\SoundLibrary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Ovozli bo'laklar (§5.4): tinglash, qayta generatsiya (SpeechKit), o'z faylini yuklash.
 */
class SoundController extends Controller
{
    public function index(SoundLibrary $library): Response
    {
        $clips = SoundClip::query()->get()->keyBy(fn (SoundClip $c) => "{$c->category}/{$c->key}");

        // Katalogdagi va bazadagi bo'laklar birga: yetishmayotganlari ⚠️ bilan
        $rows = collect($library->required())->map(function (array $item) use ($clips) {
            [$category, $key, $text] = $item;
            $clip = $clips->get("{$category}/{$key}");

            return [
                'id' => $clip?->id,
                'category' => $category,
                'key' => $key,
                'text' => $text,
                'ready' => (bool) $clip?->file_path,
                'outdated' => $clip !== null && $clip->text !== $text,
                'duration_ms' => $clip?->duration_ms,
                'generated_at' => $clip?->generated_at?->toIso8601String(),
            ];
        })->values();

        return Inertia::render('admin/sounds', ['clips' => $rows]);
    }

    public function generate(Request $request, SoundLibrary $library): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:16'],
            'key' => ['required', 'string', 'max:64'],
            'text' => ['required', 'string', 'max:255'],
        ]);

        $clip = SoundClip::query()->firstOrNew(['category' => $data['category'], 'key' => $data['key']]);

        return $this->attempt(fn () => $library->generate($clip->fill(['text' => $data['text']])));
    }

    public function generateMissing(SoundLibrary $library): RedirectResponse
    {
        return $this->attempt(function () use ($library) {
            $count = $library->generateMissing();
            Inertia::flash('toast', ['type' => 'success', 'message' => __(':count clips generated.', ['count' => $count])]);
        });
    }

    public function upload(Request $request, SoundLibrary $library): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:16'],
            'key' => ['required', 'string', 'max:64'],
            'text' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:5120', 'mimes:wav,mp3,ogg,m4a,webm'],
        ]);

        $clip = SoundClip::query()->firstOrNew(['category' => $data['category'], 'key' => $data['key']]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        return $this->attempt(fn () => $library->upload($clip->fill(['text' => $data['text']]), $file));
    }

    /**
     * Brauzerda tinglash: .sln (8 kHz 16-bit mono PCM) ga WAV sarlavhasi qo'shiladi.
     */
    public function play(SoundClip $clip, SoundLibrary $library): HttpResponse
    {
        abort_unless($clip->file_path && Storage::exists($library->path($clip->file_path)), 404);
        $pcm = (string) Storage::get($library->path($clip->file_path));

        $header = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '
            .pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
            .'data'.pack('V', strlen($pcm));

        return response($header.$pcm, 200, ['Content-Type' => 'audio/wav', 'Cache-Control' => 'no-cache']);
    }

    /**
     * SpeechKit sozlanmagan yoki ffmpeg xatosi — sahifada tushunarli xabar.
     */
    private function attempt(callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (\RuntimeException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back();
    }
}
