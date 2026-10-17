<?php

namespace App\Services\Sounds;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Yandex SpeechKit (qaror #17): o'zbekcha ayol ovozi (zamira / yulduz / nigora). Kalit — firmaning o'zi (Integratsiyalar).
 * Natija: 8 kHz, 16-bit, mono PCM — Asterisk uni `.sln` sifatida to'g'ridan-to'g'ri ijro etadi.
 */
class SpeechKit
{
    private const URL = 'https://tts.api.cloud.yandex.net/tts/v3/utteranceSynthesis';

    public function isConfigured(): bool
    {
        return Integration::credential(IntegrationProvider::SpeechKit, 'api_key') !== null;
    }

    public function synthesize(string $text): string
    {
        $apiKey = Integration::credential(IntegrationProvider::SpeechKit, 'api_key')
            ?? throw new RuntimeException('SpeechKit sozlanmagan (Integratsiyalar).');
        $folder = Integration::credential(IntegrationProvider::SpeechKit, 'folder_id');
        $voice = Integration::credential(IntegrationProvider::SpeechKit, 'voice') ?: 'zamira';

        $response = Http::withHeaders(array_filter([
            'Authorization' => "Api-Key {$apiKey}",
            'x-folder-id' => $folder,
        ]))->timeout(30)->post(self::URL, [
            'text' => $text,
            'hints' => [['voice' => $voice]],
            'outputAudioSpec' => ['rawAudio' => ['audioEncoding' => 'LINEAR16_PCM', 'sampleRateHertz' => 8000]],
            'loudnessNormalizationType' => 'LUFS',
        ]);

        if ($response->failed()) {
            throw new RuntimeException("SpeechKit xatosi {$response->status()}: {$response->body()}");
        }

        // Javob — JSON qatorlar oqimi, har birida audio bo'lagi (base64)
        $audio = '';
        foreach (preg_split('/\r?\n/', trim($response->body())) ?: [] as $line) {
            $chunk = json_decode($line, true)['result']['audioChunk']['data'] ?? null;
            if (is_string($chunk)) {
                $audio .= base64_decode($chunk);
            }
        }

        if ($audio === '') {
            throw new RuntimeException("SpeechKit bo'sh javob qaytardi.");
        }

        return $audio;
    }
}
