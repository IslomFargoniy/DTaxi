<?php

namespace App\Services\Clients;

use App\Jobs\PlaceCodeCall;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Mijoz ilovasiga kirish kodi (qaror #71): SMS yo'q (#4) — 4 xonali kod avtomatik qo'ng'iroqda aytiladi.
 * Keshda faqat xeshi; 5 daqiqa, 5 ta noto'g'ri urinishdan keyin kod bekor.
 */
class LoginCodes
{
    public const TTL_MINUTES = 5;

    private const MAX_ATTEMPTS = 5;

    public function send(string $phone): void
    {
        $code = (string) random_int(1000, 9999);
        Cache::put(self::key($phone), ['hash' => Hash::make($code), 'attempts' => 0, 'expires' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp()], now()->addMinutes(self::TTL_MINUTES));

        PlaceCodeCall::dispatch($phone, $code);
    }

    public function verify(string $phone, string $code): bool
    {
        // Faqat demo server (.env CLIENT_TEST_CODE): trunk yo'q — qo'ng'iroq qilib bo'lmaydi
        $test = config('services.client_login.test_code');
        if (is_string($test) && $test !== '' && hash_equals($test, $code)) {
            return true;
        }

        /** @var array{hash: string, attempts: int, expires: int}|null $entry */
        $entry = Cache::get(self::key($phone));
        if ($entry === null) {
            return false;
        }

        if (Hash::check($code, $entry['hash'])) {
            Cache::forget(self::key($phone));

            return true;
        }

        $entry['attempts']++;
        if ($entry['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget(self::key($phone));
        } else {
            Cache::put(self::key($phone), $entry, max(1, $entry['expires'] - now()->getTimestamp()));
        }

        return false;
    }

    private static function key(string $phone): string
    {
        return "client-login:{$phone}";
    }
}
