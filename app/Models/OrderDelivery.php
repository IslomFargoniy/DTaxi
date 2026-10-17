<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dostavka buyurtmasining qo'shimcha maydonlari.
 *
 * @property int $order_id
 * @property string|null $sender_name
 * @property string|null $sender_phone
 * @property string|null $recipient_name
 * @property string|null $recipient_phone
 * @property string|null $description
 */
#[Fillable(['order_id', 'sender_name', 'sender_phone', 'recipient_name', 'recipient_phone', 'description'])]
class OrderDelivery extends Model
{
    protected $primaryKey = 'order_id';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
