<?php

namespace App\Services\Clients;

use App\Jobs\PlaceCodeCall;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Mijoz ilovasiga kirish kodi (qaror #71): SMS yo'q (#4) — 4 xonali kod avtomatik qo'ng'iroqda aytiladi.
 * Keshda faqat xeshi; 5 daqiqa, 5 ta noto'g'ri urinishdan keyin kod bekor.
 */
class LoginCodes
{
    public const TTL_MINUTES = 5;

    private const MAX_ATTEMPTS = 5;

    /** Demo serverdagi umumiy kod faqat shu raqamlarga (qaror #73): +998900000001 … +998900000009 */
    private const TEST_PHONES = '/^\+99890000000[1-9]$/';

    public function __construct(private readonly Settings $settings) {}

    /**
     * false — firma bo'yicha soatlik qo'ng'iroqlar limiti tugagan (trunk orqali pullik avto-qo'ng'iroqlar
     * ko'p IP'dan suiiste'mol qilinmasin); kod yaratilmaydi.
     */
    public function send(string $phone): bool
    {
        $limit = (int) $this->settings->get('calls.login_code_calls_per_hour');
        $hour = 'client-login-calls:'.now()->format('YmdH');
        Cache::add($hour, 0, now()->addHours(2));
        if (Cache::increment($hour) > $limit) {
            Log::warning("Kirish kodi qo'ng'iroqlari soatlik limiti tugadi", ['limit' => $limit]);

            return false;
        }

        $code = (string) random_int(1000, 9999);
        $ttl = now()->addMinutes(self::TTL_MINUTES);
        Cache::put(self::key($phone), Hash::make($code), $ttl);
        // Urinishlar alohida hisoblagichda: increment atomik — parallel so'rovlar bitta qiymatni o'qib chegarani chetlab o'tmaydi
        Cache::put(self::attemptsKey($phone), 0, $ttl);

        PlaceCodeCall::dispatch($phone, $code);

        return true;
    }

    public function verify(string $phone, string $code): bool
    {
        if ($this->isTestLogin($phone, $code)) {
            return true;
        }

        $hash = Cache::get(self::key($phone));
        if (! is_string($hash)) {
            return false;
        }

        if ((int) Cache::increment(self::attemptsKey($phone)) > self::MAX_ATTEMPTS) {
            $this->forget($phone);

            return false;
        }

        if (Hash::check($code, $hash)) {
            $this->forget($phone);

            return true;
        }

        return false;
    }

    /** Faqat demo server (.env DTAXI_DEMO=true, trunk yo'q) va faqat test raqamlari — firma serverida hech qachon */
    private function isTestLogin(string $phone, string $code): bool
    {
        $test = config('services.client_login.test_code');

        return config('app.demo') === true
            && is_string($test) && $test !== ''
            && preg_match(self::TEST_PHONES, $phone) === 1
            && hash_equals($test, $code);
    }

    private function forget(string $phone): void
    {
        Cache::forget(self::key($phone));
        Cache::forget(self::attemptsKey($phone));
    }

    private static function key(string $phone): string
    {
        return "client-login:{$phone}";
    }

    private static function attemptsKey(string $phone): string
    {
        return "client-login-attempts:{$phone}";
    }
}
