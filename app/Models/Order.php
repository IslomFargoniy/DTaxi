<?php

namespace App\Models;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Concerns\BelongsToBranch;
use Clickbar\Magellan\Data\Geometries\Point;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property int $client_id
 * @property int|null $operator_id
 * @property int|null $driver_id
 * @property int|null $vehicle_id
 * @property int $tariff_class_id
 * @property OrderStatus $status
 * @property OrderSource $source
 * @property string $pickup_text
 * @property Point $pickup_location
 * @property int|null $pickup_landmark_id
 * @property string|null $dropoff_text
 * @property Point|null $dropoff_location
 * @property string|null $comment
 * @property array<string, mixed> $tariff_snapshot
 * @property int|null $price_estimate
 * @property int|null $price_bonus
 * @property int|null $company_id
 * @property list<array{text: string, lat: float, lng: float}>|null $stops
 * @property int|null $price_final
 * @property array<string, int>|null $price_breakdown
 * @property int|null $distance_m
 * @property int|null $duration_s
 * @property int|null $waiting_s
 * @property int|null $commission
 * @property int|null $phone_number_id
 * @property int|null $search_radius_m
 * @property int $search_round
 * @property Carbon|null $search_exhausted_at
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $searching_at
 * @property Carbon|null $assigned_at
 * @property Carbon|null $arrived_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property Carbon $created_at
 * @property-read Client $client
 * @property-read Driver|null $driver
 * @property-read Vehicle|null $vehicle
 * @property-read TariffClass $tariffClass
 * @property-read Branch $branch
 * @property-read OrderDelivery|null $delivery
 * @property-read Company|null $company
 */
#[Fillable([
    'branch_id', 'client_id', 'company_id', 'operator_id', 'driver_id', 'vehicle_id', 'tariff_class_id', 'status', 'source', 'phone_number_id',
    'pickup_text', 'pickup_location', 'pickup_landmark_id', 'dropoff_text', 'dropoff_location', 'stops', 'comment', 'scheduled_at',
    'tariff_snapshot', 'price_estimate', 'price_final', 'price_breakdown', 'distance_m', 'duration_s',
    'waiting_s', 'commission', 'search_radius_m', 'searching_at', 'assigned_at', 'arrived_at', 'started_at',
    'completed_at', 'cancelled_at', 'cancel_reason',
])]
class Order extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'source' => OrderSource::class,
            'pickup_location' => Point::class,
            'dropoff_location' => Point::class,
            'tariff_snapshot' => 'array',
            'price_breakdown' => 'array',
            'stops' => 'array',
            'scheduled_at' => 'datetime',
            'searching_at' => 'datetime',
            'assigned_at' => 'datetime',
            'arrived_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'search_exhausted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /**
     * @return BelongsTo<TariffClass, $this>
     */
    public function tariffClass(): BelongsTo
    {
        return $this->belongsTo(TariffClass::class);
    }

    /**
     * @return BelongsTo<Landmark, $this>
     */
    public function pickupLandmark(): BelongsTo
    {
        return $this->belongsTo(Landmark::class, 'pickup_landmark_id');
    }

    /**
     * @return HasMany<OrderOffer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(OrderOffer::class);
    }

    /**
     * @return HasMany<OrderEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    /**
     * @return HasOne<OrderDelivery, $this>
     */
    public function delivery(): HasOne
    {
        return $this->hasOne(OrderDelivery::class);
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', array_map(
            fn (OrderStatus $s) => $s->value,
            array_filter(OrderStatus::cases(), fn (OrderStatus $s) => $s->isActive()),
        ));
    }
}
