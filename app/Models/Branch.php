<?php

namespace App\Models;

use Clickbar\Magellan\Data\Geometries\MultiPolygon;
use Clickbar\Magellan\Data\Geometries\Point;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property int|null $region_id
 * @property string|null $service_name
 * @property string|null $greeting_text
 * @property Point|null $center
 * @property MultiPolygon|null $service_area
 * @property string $timezone
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'region_id', 'service_name', 'greeting_text', 'center', 'service_area', 'timezone', 'is_active'])]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'center' => Point::class,
            'service_area' => MultiPolygon::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Filial hududini tanlangan shahar/tuman chegarasidan to'ldiradi (qaror #31).
     */
    public function fillAreaFromRegion(Region $region): void
    {
        $this->region_id = $region->id;
        $this->center = $region->center;
        $this->service_area = $region->boundary;
    }
}
