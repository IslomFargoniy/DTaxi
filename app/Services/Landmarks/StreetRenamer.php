<?php

namespace App\Services\Landmarks;

use App\Models\Landmark;
use App\Models\Region;
use App\Models\StreetRename;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Qayta nomlangan ko'chalar: OSM'da eski nom qolgan, firma esa yangi nomni ko'rsatmoqchi.
 * Mo'ljallar bazasidagi ko'cha va uy manzillari yangilanadi (eski nom muqobil nom bo'lib qoladi —
 * mijozlar ko'pincha eski nom bilan aytadi), xarita yozuvlari va navigatsiya esa map() orqali almashtiriladi.
 */
class StreetRenamer
{
    private const CACHE_KEY = 'street_renames';

    /**
     * Eski nom → yangi nom. Xarita va OSRM qadamlarida hudud ma'lum emas — hududsizlari ustun.
     *
     * @return array<string, string>
     */
    public function map(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $map = [];
            foreach (StreetRename::query()->orderByRaw('region_id IS NOT NULL')->orderBy('id')->get() as $rename) {
                $map[$rename->old_name] ??= $rename->new_name;
            }

            return $map;
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Eski nomli ko'cha va "<eski>, <uy>" manzillarini yangi nomga o'tkazadi.
     */
    public function apply(StreetRename $rename): int
    {
        $count = 0;
        foreach ($this->landmarks($rename->old_name, $rename->region_id)->get() as $landmark) {
            $name = $rename->new_name.substr($landmark->name, strlen($rename->old_name));
            $aliases = array_values(array_diff($landmark->aliases, [$name]));
            if (! in_array($landmark->name, $aliases, true)) {
                $aliases[] = $landmark->name;
            }
            // edited_at — keyingi OSM importi eski nomni qaytarmasin
            $landmark->forceFill(['name' => $name, 'aliases' => $aliases, 'edited_at' => now()])->save();
            $count++;
        }

        return $count;
    }

    /**
     * O'chirilganda: shu qayta nomlash orqali o'zgarganlari (eski nom muqobil nomlar ichida) eski nomga qaytadi.
     */
    public function revert(StreetRename $rename): int
    {
        $count = 0;
        foreach ($this->landmarks($rename->new_name, $rename->region_id)->get() as $landmark) {
            $old = $rename->old_name.substr($landmark->name, strlen($rename->new_name));
            if (! in_array($old, $landmark->aliases, true)) {
                continue;
            }
            $landmark->forceFill(['name' => $old, 'aliases' => array_values(array_diff($landmark->aliases, [$old]))])->save();
            $count++;
        }

        return $count;
    }

    /**
     * OSM importidan keyin: yangi kelgan eski nomli yozuvlarga barcha qayta nomlashlar qo'llanadi.
     */
    public function applyAll(): int
    {
        $count = 0;
        foreach (StreetRename::query()->orderBy('id')->get() as $rename) {
            $count += $this->apply($rename);
        }

        return $count;
    }

    /**
     * @return Builder<Landmark>
     */
    private function landmarks(string $street, ?int $regionId): Builder
    {
        return Landmark::query()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('category', 'street')->where('name', $street))
                ->orWhere(fn (Builder $q) => $q->where('category', 'address')->whereRaw('starts_with(name, ?)', [$street.', '])))
            // Viloyat tanlansa — uning shahar/tumanlari ham (mo'ljallar shahar/tumanga bog'langan)
            ->when($regionId !== null, fn (Builder $q) => $q->whereIn(
                'region_id',
                Region::query()->where('id', $regionId)->orWhere('parent_id', $regionId)->select('id'),
            ));
    }
}
