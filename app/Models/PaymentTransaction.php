<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Click/Payme to'lovlari — har biri bir marta hisoblanadi (idempotentlik).
 *
 * @property int $id
 * @property string $provider
 * @property string $provider_transaction_id
 * @property int|null $driver_id
 * @property int $amount
 * @property string $state created | performed | cancelled | refunded
 * @property int|null $provider_time
 * @property int|null $cancel_reason
 * @property array<string, mixed>|null $raw_request
 * @property Carbon|null $performed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon $created_at
 */
#[Fillable(['provider', 'provider_transaction_id', 'driver_id', 'amount', 'state', 'provider_time', 'raw_request', 'performed_at', 'cancelled_at', 'cancel_reason'])]
class PaymentTransaction extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'integer', 'provider_time' => 'integer', 'cancel_reason' => 'integer', 'raw_request' => 'array', 'performed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
