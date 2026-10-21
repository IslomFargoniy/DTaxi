<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Setting;
use InvalidArgumentException;

/**
 * Panelda o'zgartiriladigan sozlamalar. Qidirish tartibi: filial qiymati → umumiy qiymat → standart.
 */
class Settings
{
    /**
     * Standart qiymatlar (qaror #34). Yangi sozlama shu ro'yxatga qo'shiladi.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'company.name' => 'Dtaxi',
        'company.default_locale' => 'uz',
        'drivers.commission_percent' => 10,
        'drivers.min_balance' => 5000,
        'tariffs.rounding_step' => 500,
        'calls.recordings_retention_days' => 90,
        // Bitta yozuvning eng uzun davomiyligi (soniya): ko'prik yopilmay qolsa ham fayl cheksiz o'smaydi
        'calls.max_recording_seconds' => 10800,
        // Qo'ng'iroq markazi (§5): kutish, operator jiringlashi
        'calls.max_wait_seconds' => 180,
        'calls.operator_ring_seconds' => 20,
        'calls.pause_on_missed_ring' => true,
        // IVR avto-buyurtma: doimiy mijoz "1" ni bosib oxirgi manziliga taksi chaqiradi (Bosqich 5)
        'calls.ivr_auto_order' => false,
        // Avto-qo'ng'iroqlar (§5.4)
        'calls.auto_calls_enabled' => true,
        'calls.auto_call_attempts' => 3,
        'calls.auto_call_retry_seconds' => 30,
        'calls.still_waiting_minutes' => 3,
        // Safardan keyin mijozga qo'ng'iroq: 1–5 tugmasi bilan baho (qo'ng'iroq pulli — standart o'chiq)
        'calls.rating_call_enabled' => false,
        'calls.rating_call_delay_minutes' => 2,
        // Efir (qaror #28)
        'dispatch.radius_steps_m' => [3000, 5000, 8000],
        'dispatch.step_seconds' => 20,
        'dispatch.direct_offer_seconds' => 15,
        'dispatch.location_ttl_seconds' => 60,
        // Oldindan buyurtma necha daqiqa oldin efirga chiqadi
        'dispatch.preorder_lead_minutes' => 15,
        // Reytingi shundan past haydovchi eng yaqin radiusda taklif olmaydi (keyingi bosqichda oladi; 0 — o'chiq)
        'dispatch.low_rating_threshold' => 60,
        // Tig'iz soat: koeffitsient (1 — o'chiq) va filial vaqti bo'yicha soatlar (boshi = oxiri — kun bo'yi)
        'pricing.surge_coeff' => 1,
        'pricing.surge_from_hour' => 0,
        'pricing.surge_to_hour' => 0,
        // GPS treki necha kun saqlanadi (masofa bahslari uchun)
        'drivers.locations_retention_days' => 30,
        // Jarima va reyting (qaror #29)
        'drivers.cancel_penalty' => 2000,
        'drivers.rating_cancel_penalty' => 5,
        'drivers.rating_complete_bonus' => 1,
        'drivers.max_cancels_per_shift' => 3,
        'drivers.block_minutes' => 30,
    ];

    /** @var array<string, mixed> */
    private array $cache = [];

    public function get(string $key, Branch|int|null $branch = null): mixed
    {
        $this->ensureKnown($key);
        $branchId = $branch instanceof Branch ? $branch->id : $branch;
        $cacheKey = $key.'@'.($branchId ?? 'global');

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $rows = Setting::query()
            ->where('key', $key)
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($q) => $q->orWhere('branch_id', $branchId)))
            ->get()
            ->keyBy(fn (Setting $s) => $s->branch_id ?? 'global');

        $value = $branchId && $rows->has($branchId)
            ? $rows[$branchId]->value
            : ($rows->has('global') ? $rows['global']->value : self::DEFAULTS[$key]);

        return $this->cache[$cacheKey] = $value;
    }

    public function set(string $key, mixed $value, Branch|int|null $branch = null): void
    {
        $this->ensureKnown($key);
        $branchId = $branch instanceof Branch ? $branch->id : $branch;

        Setting::query()->updateOrCreate(['branch_id' => $branchId, 'key' => $key], ['value' => $value]);
        $this->cache = [];
    }

    private function ensureKnown(string $key): void
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            throw new InvalidArgumentException("Noma'lum sozlama: {$key}");
        }
    }
}
