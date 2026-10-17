<?php

namespace App\Models;

use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Haydovchi balansi harakati: komissiya, to'ldirish, jarima, bonus.
 *
 * @property int $id
 * @property int $driver_id
 * @property int|null $order_id
 * @property TransactionType $type
 * @property TransactionSource $source
 * @property int $amount
 * @property int $balance_after
 * @property string|null $comment
 * @property \Illuminate\Support\Carbon|null $created_at
 */
#[Fillable(['driver_id', 'order_id', 'cash_shift_id', 'type', 'source', 'amount', 'balance_after', 'created_by', 'comment', 'created_at'])]
class DriverTransaction extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['type' => TransactionType::class, 'source' => TransactionSource::class, 'amount' => 'integer', 'balance_after' => 'integer', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
