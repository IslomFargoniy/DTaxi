<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\Branches\BranchProvisioner;
use Carbon\CarbonImmutable;
use Clickbar\Magellan\Data\Geometries\MultiPolygon;
use Clickbar\Magellan\Data\Geometries\Point;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property array<string, array{from: string, to: string}|null>|null $work_schedule null — kecha-kunduz
 * @property list<string>|null $holidays Y-m-d sanalar
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'region_id', 'service_name', 'greeting_text', 'center', 'service_area', 'timezone', 'is_active', 'work_schedule', 'holidays'])]
class Branch extends Model
{
    use Auditable;

    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::created(fn (Branch $branch) => app(BranchProvisioner::class)->provision($branch));
    }

    protected function casts(): array
    {
        return [
            'center' => Point::class,
            'service_area' => MultiPolygon::class,
            'is_active' => 'boolean',
            'work_schedule' => 'array',
            'holidays' => 'array',
        ];
    }

    public const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * Filial hozir ishlayaptimi (filial vaqt zonasida). Tunda ishlaydigan smena (22:00–06:00) ham hisobga olinadi.
     */
    public function isOpen(?\DateTimeInterface $at = null): bool
    {
        $now = CarbonImmutable::instance($at ?? now())->setTimezone($this->timezone);

        if (in_array($now->toDateString(), $this->holidays ?? [], true)) {
            return false;
        }
        if (! $this->work_schedule) {
            return true;
        }

        $time = $now->format('H:i');
        $today = $this->work_schedule[strtolower($now->format('D'))] ?? null;
        if ($today && ($today['from'] <= $today['to']
            ? $time >= $today['from'] && $time < $today['to']
            : $time >= $today['from'])) {
            return true;
        }

        // Kechagi tungi smena bugun ertalab davom etmoqda
        $yesterday = $this->work_schedule[strtolower($now->subDay()->format('D'))] ?? null;

        return $yesterday !== null && $yesterday['from'] > $yesterday['to'] && $time < $yesterday['to'];
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
     * @return HasMany<TariffClass, $this>
     */
    public function tariffClasses(): HasMany
    {
        return $this->hasMany(TariffClass::class);
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
