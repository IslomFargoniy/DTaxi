<?php

namespace App\Models;

use App\Enums\OfferResponse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Efirda kimga ko'rsatildi va kim olgan (qaror #28).
 *
 * @property int $id
 * @property int $order_id
 * @property int $driver_id
 * @property int|null $distance_m
 * @property bool $is_direct
 * @property OfferResponse|null $response
 * @property Carbon $offered_at
 * @property Carbon|null $responded_at
 */
#[Fillable(['order_id', 'driver_id', 'distance_m', 'is_direct', 'response', 'offered_at', 'responded_at'])]
class OrderOffer extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['is_direct' => 'boolean', 'response' => OfferResponse::class, 'offered_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
