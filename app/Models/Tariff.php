<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use Database\Factories\TariffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $branch_id
 * @property int $tariff_class_id
 * @property int $base_fare
 * @property string $base_km
 * @property int $per_km
 * @property int $rounding_step
 * @property bool $waiting_enabled
 * @property int $waiting_free_min
 * @property int $waiting_per_min
 * @property bool $night_enabled
 * @property string $night_from
 * @property string $night_to
 * @property string $night_coeff
 * @property bool $outside_enabled
 * @property int $outside_per_km
 */
#[Fillable([
    'branch_id', 'tariff_class_id', 'base_fare', 'base_km', 'per_km', 'rounding_step',
    'waiting_enabled', 'waiting_free_min', 'waiting_per_min',
    'night_enabled', 'night_from', 'night_to', 'night_coeff',
    'outside_enabled', 'outside_per_km',
])]
class Tariff extends Model
{
    use Auditable;
    use BelongsToBranch;

    /** @use HasFactory<TariffFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'base_fare' => 'integer',
            'per_km' => 'integer',
            'rounding_step' => 'integer',
            'waiting_enabled' => 'boolean',
            'waiting_free_min' => 'integer',
            'waiting_per_min' => 'integer',
            'night_enabled' => 'boolean',
            'outside_enabled' => 'boolean',
            'outside_per_km' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TariffClass, $this>
     */
    public function tariffClass(): BelongsTo
    {
        return $this->belongsTo(TariffClass::class);
    }

    /**
     * Buyurtmaga yoziladigan nusxa — keyin tarif o'zgarsa ham eski buyurtma o'z narxida qoladi.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->only([
            'base_fare', 'base_km', 'per_km', 'rounding_step',
            'waiting_enabled', 'waiting_free_min', 'waiting_per_min',
            'night_enabled', 'night_from', 'night_to', 'night_coeff',
            'outside_enabled', 'outside_per_km',
        ]);
    }
}
