<?php

namespace App\Models;

use App\Enums\RegionType;
use Clickbar\Magellan\Data\Geometries\MultiPolygon;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property RegionType $type
 * @property string $name_uz
 * @property string|null $name_ru
 * @property int|null $osm_id
 * @property Point|null $center
 * @property MultiPolygon|null $boundary
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['parent_id', 'type', 'name_uz', 'name_ru', 'osm_id', 'center', 'boundary'])]
class Region extends Model
{
    protected function casts(): array
    {
        return [
            'type' => RegionType::class,
            'center' => Point::class,
            'boundary' => MultiPolygon::class,
        ];
    }

    /**
     * @return BelongsTo<Region, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'parent_id');
    }

    /**
     * @return HasMany<Region, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Region::class, 'parent_id');
    }

    /**
     * @param  Builder<Region>  $query
     */
    public function scopeTopLevel(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'ru' && $this->name_ru ? $this->name_ru : $this->name_uz;
    }
}
