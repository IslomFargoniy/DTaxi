<?php

namespace App\Models;

use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GPS tarixi (tezkor joriy joylashuv Redis'da).
 *
 * @property int $id
 * @property int $driver_id
 * @property Point $location
 */
#[Fillable(['driver_id', 'location', 'speed', 'heading', 'accuracy', 'recorded_at'])]
class DriverLocation extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['location' => Point::class, 'recorded_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
