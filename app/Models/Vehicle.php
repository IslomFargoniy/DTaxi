<?php

namespace App\Models;

use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Haydovchining o'z mashinasi (qaror #26).
 *
 * @property int $id
 * @property int $driver_id
 * @property string $brand
 * @property string $model
 * @property string $color
 * @property string $plate_number
 * @property int|null $year
 * @property bool $is_current
 * @property bool $is_approved
 */
#[Fillable(['driver_id', 'brand', 'model', 'color', 'plate_number', 'year', 'tech_passport_no', 'is_current', 'is_approved', 'approved_by', 'approved_at'])]
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'is_approved' => 'boolean', 'approved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return BelongsToMany<TariffClass, $this>
     */
    public function tariffClasses(): BelongsToMany
    {
        return $this->belongsToMany(TariffClass::class, 'vehicle_tariff_class');
    }

    /**
     * Davlat raqamini bir xil ko'rinishga keltiradi: "01 a 123 bc" → "01A123BC".
     */
    public static function normalizePlate(string $plate): string
    {
        return mb_strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $plate));
    }

    /**
     * O'zbekiston davlat raqami (normalizePlate'dan keyin): "01A123BC" (jismoniy shaxs)
     * yoki "01123ABC" (yuridik shaxs).
     */
    public static function plateIsValid(string $plate): bool
    {
        return preg_match('/^(\d{2}[A-Z]\d{3}[A-Z]{2}|\d{5}[A-Z]{3})$/', self::normalizePlate($plate)) === 1;
    }

    /**
     * Ko'rsatish uchun: "01A123BC" → "01 A 123 BC", "01123ABC" → "01 123 ABC"; boshqa format — o'zgarishsiz.
     */
    public static function formatPlate(string $plate): string
    {
        $compact = self::normalizePlate($plate);
        if (preg_match('/^(\d{2})([A-Z])(\d{3})([A-Z]{2})$/', $compact, $m) === 1
            || preg_match('/^(\d{2})(\d{3})([A-Z]{3})$/', $compact, $m) === 1) {
            return implode(' ', array_slice($m, 1));
        }

        return $plate;
    }

    protected function setPlateNumberAttribute(string $value): void
    {
        $this->attributes['plate_number'] = self::normalizePlate($value);
    }
}
