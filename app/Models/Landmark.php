<?php

namespace App\Models;

use App\Support\Transliterator;
use Clickbar\Magellan\Data\Geometries\Point;
use Database\Factories\LandmarkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mo'ljal: mijozlar aytadigan joy nomi ("Chorsu bozori", "3-maktab") va uning nuqtasi (reja §4).
 *
 * @property int $id
 * @property int|null $region_id
 * @property string $name
 * @property list<string> $aliases
 * @property string $search
 * @property Point $location
 * @property string|null $category
 * @property int|null $osm_id
 * @property int $usage_count
 */
#[Fillable(['region_id', 'name', 'aliases', 'location', 'category', 'osm_id', 'usage_count', 'created_by'])]
class Landmark extends Model
{
    /** @use HasFactory<LandmarkFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['aliases' => 'array', 'location' => Point::class, 'usage_count' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (Landmark $landmark) {
            $landmark->search = Transliterator::forSearch(implode(' ', [$landmark->name, ...($landmark->aliases ?? [])]));
        });
    }

    /**
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * Noaniq qidiruv: lotin/kirill/rus yozuvi bir xil topiladi, o'xshashlik va ishlatilish soni bo'yicha saralanadi.
     *
     * @param  Builder<Landmark>  $query
     */
    public function scopeSearch(Builder $query, string $term, ?Point $near = null): void
    {
        $needle = Transliterator::forSearch($term);
        // "Mustaqillik 12" → ko'cha va uy raqami alohida so'z: hammasi uchrasa ham topiladi
        $words = array_values(array_filter(preg_split('/[\s,]+/', $needle) ?: [], fn (string $w) => $w !== ''));
        $wholeWords = count($words) > 1
            ? implode(' AND ', array_fill(0, count($words), 'search ~* ?'))
            : 'FALSE';
        $wordPatterns = array_map(fn (string $w) => '(^|[^0-9a-z])'.preg_quote($w).'($|[^0-9a-z])', $words);

        $query->where(fn (Builder $q) => $q
            ->whereRaw('search ILIKE ?', ['%'.$needle.'%'])
            ->orWhereRaw('word_similarity(?, search) > 0.4', [$needle])
            ->when(count($words) > 1, fn (Builder $q) => $q->orWhere(function (Builder $all) use ($words) {
                foreach ($words as $word) {
                    $all->whereRaw('search ILIKE ?', ['%'.$word.'%']);
                }
            })))
            // Avval: nom so'z bilan boshlanadi → so'z ichida bor → barcha so'zlar aniq (uy raqami) → o'xshash;
            // keyin operatorlar ko'p tanlaganlari
            ->orderByRaw(
                "CASE WHEN search ILIKE ? THEN 0 WHEN search ~* ? THEN 1 WHEN {$wholeWords} THEN 2 WHEN search ILIKE ? THEN 3 ELSE 4 END",
                [$needle.'%', '(^|[^0-9a-z])'.preg_quote($needle), ...(count($words) > 1 ? $wordPatterns : []), '%'.$needle.'%'],
            )
            // Bir xil darajada — nomli joylar (bozor, maktab) oddiy uy manzili va ko'chadan oldin
            ->orderByRaw("CASE WHEN category IN ('address', 'street') THEN 1 ELSE 0 END")
            ->orderByDesc('usage_count')
            // Bir xil nomlilar ("3-maktab") — filialga yaqinlari oldinda
            ->when($near, fn (Builder $q) => $q->orderByRaw(
                'ST_Distance(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)',
                [$near?->getLongitude(), $near?->getLatitude()],
            ))
            ->orderByRaw('word_similarity(?, search) DESC', [$needle]);
    }
}
