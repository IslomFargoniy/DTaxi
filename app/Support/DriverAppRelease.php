<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Haydovchi ilovasining joriy versiyasi (qaror #37): APK firma serverida, Google Play'siz.
 * Har protsessor turi (ABI) uchun alohida APK.
 */
class DriverAppRelease
{
    public const ABIS = ['arm64-v8a', 'armeabi-v7a'];

    private const DIR = 'driver-app';

    /**
     * @return array{version_code: int, version_name: string, min_version_code: int, released_at: string|null, files: array<string, bool>}
     */
    public function current(): array
    {
        $manifest = Storage::exists(self::DIR.'/version.json')
            ? json_decode((string) Storage::get(self::DIR.'/version.json'), true)
            : [];

        return [
            'version_code' => (int) ($manifest['version_code'] ?? 0),
            'version_name' => (string) ($manifest['version_name'] ?? ''),
            'min_version_code' => (int) ($manifest['min_version_code'] ?? 0),
            'released_at' => $manifest['released_at'] ?? null,
            'files' => array_combine(self::ABIS, array_map(fn (string $abi) => Storage::exists($this->path($abi)), self::ABIS)),
        ];
    }

    /**
     * @param  array<string, UploadedFile>  $apks  abi → fayl
     */
    public function publish(int $versionCode, string $versionName, int $minVersionCode, array $apks): void
    {
        foreach ($apks as $abi => $file) {
            Storage::putFileAs(self::DIR, $file, "{$abi}.apk");
        }

        Storage::put(self::DIR.'/version.json', (string) json_encode([
            'version_code' => $versionCode,
            'version_name' => $versionName,
            'min_version_code' => $minVersionCode,
            'released_at' => now()->toIso8601String(),
        ]));
    }

    public function path(string $abi): string
    {
        return self::DIR."/{$abi}.apk";
    }

    /**
     * Telefon protsessoriga mos ABI (ilova Build.SUPPORTED_ABIS[0] ni yuboradi).
     */
    public function abiFor(?string $requested): string
    {
        return in_array($requested, self::ABIS, true) ? $requested : self::ABIS[0];
    }
}
