<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Haydovchi ↔ dispetcher chati (qaror #47).
 *
 * @property int $id
 * @property int $driver_id
 * @property int|null $order_id
 * @property int $sender_id
 * @property string|null $body
 * @property string|null $attachment_path
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 */
#[Fillable(['driver_id', 'order_id', 'sender_id', 'body', 'attachment_path', 'read_at', 'created_at'])]
class ChatMessage extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        // Xabar egasi aniq: filial cheklovi (BelongsToBranch) bu yerda kerak emas
        return $this->belongsTo(Driver::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
