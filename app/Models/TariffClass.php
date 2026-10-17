<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use Database\Factories\TariffClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tarif klassi: Standart / Komfort / Yuk / Dostavka (qaror #27).
 *
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property string|null $icon
 * @property int $sort
 * @property bool $is_delivery
 * @property bool $is_default
 * @property bool $is_active
 * @property-read Tariff|null $tariff
 */
#[Fillable(['branch_id', 'name', 'icon', 'sort', 'is_delivery', 'is_default', 'is_active'])]
class TariffClass extends Model
{
    use Auditable;
    use BelongsToBranch;

    /** @use HasFactory<TariffClassFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_delivery' => 'boolean', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /**
     * @return HasOne<Tariff, $this>
     */
    public function tariff(): HasOne
    {
        return $this->hasOne(Tariff::class);
    }

    /**
     * @return BelongsToMany<Vehicle, $this>
     */
    public function vehicles(): BelongsToMany
    {
        return $this->belongsToMany(Vehicle::class, 'vehicle_tariff_class');
    }
}
