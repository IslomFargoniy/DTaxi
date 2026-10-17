<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Buyurtma tarixidagi har bir o'zgarish — nizolarni hal qilish uchun.
 *
 * @property int $id
 * @property int $order_id
 * @property string $type
 * @property int|null $actor_id
 * @property array<string, mixed>|null $payload
 */
#[Fillable(['order_id', 'type', 'actor_id', 'payload', 'created_at'])]
class OrderEvent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
