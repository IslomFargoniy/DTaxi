<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Haydovchi bekor qilgan buyurtmalar va jarimalar (qaror #29).
 *
 * @property int $id
 * @property int $order_id
 * @property int $driver_id
 * @property string $reason
 * @property int $penalty_amount
 * @property \Illuminate\Support\Carbon|null $created_at
 */
#[Fillable(['order_id', 'driver_id', 'reason', 'penalty_amount', 'penalty_waived_by', 'created_at'])]
class OrderCancellation extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['penalty_amount' => 'integer', 'created_at' => 'datetime'];
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
