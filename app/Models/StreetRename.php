<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Qayta nomlangan ko'cha: OSM'dagi eski nom → hozirgi rasmiy nom (xarita, navigatsiya, qidiruv).
 *
 * @property int $id
 * @property string $old_name
 * @property string $new_name
 * @property int|null $region_id
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['old_name', 'new_name', 'region_id', 'created_by'])]
class StreetRename extends Model
{
    /**
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
