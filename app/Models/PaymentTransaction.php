<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Click/Payme to'lovlari — har biri bir marta hisoblanadi (idempotentlik).
 *
 * @property int $id
 * @property string $provider
 * @property string $provider_transaction_id
 * @property int|null $driver_id
 * @property int $amount
 * @property string $state
 */
#[Fillable(['provider', 'provider_transaction_id', 'driver_id', 'amount', 'state', 'raw_request', 'performed_at', 'cancelled_at'])]
class PaymentTransaction extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'integer', 'raw_request' => 'array', 'performed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
