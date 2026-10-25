<?php

namespace App\Models;

use App\Enums\DriverStatus;
use App\Events\DriverLocationUpdated;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use Clickbar\Magellan\Data\Geometries\Point;
use Database\Factories\DriverFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $user_id
 * @property int $branch_id
 * @property string $callsign
 * @property DriverStatus $status
 * @property int $balance
 * @property int $rating
 * @property Carbon|null $blocked_until
 * @property string|null $license_no
 * @property Carbon|null $license_expires_at
 * @property bool $is_approved
 * @property Point|null $last_location
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $mock_location_at
 * @property-read User $user
 * @property-read Vehicle|null $vehicle
 */
#[Fillable([
    'user_id', 'branch_id', 'callsign', 'status', 'balance', 'rating', 'blocked_until',
    'license_no', 'license_expires_at', 'is_approved', 'approved_by', 'approved_at',
    'last_location', 'last_seen_at',
])]
class Driver extends Model
{
    use Auditable;
    use BelongsToBranch;

    /** @use HasFactory<DriverFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // Liniyaga chiqdi/chiqdi, band/bo'sh — operator xaritasi keyingi GPS nuqtani kutmasdan yangilanadi
        static::updated(function (Driver $driver) {
            if ($driver->wasChanged('status')) {
                DriverLocationUpdated::dispatch($driver);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => DriverStatus::class,
            'balance' => 'integer',
            'rating' => 'integer',
            'blocked_until' => 'datetime',
            'license_expires_at' => 'date',
            'is_approved' => 'boolean',
            'approved_at' => 'datetime',
            'last_location' => Point::class,
            'last_seen_at' => 'datetime',
            'mock_location_at' => 'datetime',
        ];
    }

    /**
     * Yangi haydovchi uchun keyingi bo'sh pozivnoy: eng katta raqamli + 1 (pozivnoy barcha filiallar bo'yicha yagona —
     * u operator tergan ichki raqam ham). Haydovchilar hali yo'q bo'lsa — 101.
     */
    public static function nextCallsign(): string
    {
        $max = (int) DB::scalar("SELECT COALESCE(MAX(callsign::int), 100) FROM drivers WHERE callsign ~ '^[0-9]{1,6}$'");

        return (string) min(999999, max(101, $max + 1));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Joriy mashina.
     *
     * @return HasOne<Vehicle, $this>
     */
    public function vehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class)->where('is_current', true);
    }

    /**
     * @return HasMany<Vehicle, $this>
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * @return HasMany<DriverTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(DriverTransaction::class);
    }

    /**
     * @return HasMany<DriverDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    public function isBlocked(): bool
    {
        return $this->blocked_until !== null && $this->blocked_until->isFuture();
    }
}
