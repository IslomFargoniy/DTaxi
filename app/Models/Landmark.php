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
    public function scopeSearch(Builder $query, string $term): void
    {
        $needle = Transliterator::forSearch($term);

        $query->where(fn (Builder $q) => $q
            ->whereRaw('search ILIKE ?', ['%'.$needle.'%'])
            ->orWhereRaw('word_similarity(?, search) > 0.4', [$needle]))
            ->orderByRaw('word_similarity(?, search) DESC', [$needle])
            ->orderByDesc('usage_count');
    }
}
