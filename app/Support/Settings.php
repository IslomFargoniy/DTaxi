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
        'company.name' => 'ibTaxi',
        'company.default_locale' => 'uz',
        'drivers.commission_percent' => 10,
        'drivers.min_balance' => 5000,
        'tariffs.rounding_step' => 500,
        'calls.recordings_retention_days' => 90,
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
