<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

/**
 * Haydovchi "online"mi (Telegram kabi, chatda): ilovaning oxirgi so'rovi — keshda, bazaga yozilmaydi.
 * Ilova ochiq turganda har daqiqada ping yuboradi, liniyada esa GPS so'rovlari ham hisoblanadi.
 */
class DriverPresence
{
    /** Shundan yangi faollik — online (ping 60 s + tarmoq kechikishi) */
    public const ONLINE_SECONDS = 90;

    /** Har so'rovda emas — 30 soniyada bir marta yoziladi (GPS har 4 soniyada keladi) */
    private const WRITE_EVERY = 30;

    public function touch(int $userId): void
    {
        $key = self::key($userId);
        $last = Cache::get($key);
        if (! is_int($last) || time() - $last >= self::WRITE_EVERY) {
            Cache::put($key, time(), now()->addDays(7));
        }
    }

    /**
     * @param  array<int>  $userIds
     * @return array<int, CarbonInterface> user_id → oxirgi faollik
     */
    public function lastActive(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $values = Cache::many(array_map(self::key(...), $userIds));
        $result = [];
        foreach ($userIds as $id) {
            $value = $values[self::key($id)] ?? null;
            if (is_int($value)) {
                $result[$id] = Date::createFromTimestamp($value);
            }
        }

        return $result;
    }

    public static function isOnline(?CarbonInterface $lastActive): bool
    {
        return $lastActive !== null && $lastActive->gte(now()->subSeconds(self::ONLINE_SECONDS));
    }

    private static function key(int $userId): string
    {
        return "driver-active:{$userId}";
    }
}
