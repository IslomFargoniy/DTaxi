<?php

namespace App\Models;

use App\Enums\ComplaintType;
use App\Models\Concerns\BelongsToBranch;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $branch_id
 * @property int|null $order_id
 * @property int|null $client_id
 * @property int|null $driver_id
 * @property ComplaintType $type
 * @property string $text
 * @property string $status open|resolved
 * @property string|null $resolution
 * @property int|null $created_by
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 * @property-read Order|null $order
 * @property-read Client|null $client
 * @property-read Driver|null $driver
 * @property-read User|null $author
 */
#[Fillable(['branch_id', 'order_id', 'client_id', 'driver_id', 'type', 'text', 'status', 'resolution', 'created_by', 'resolved_by', 'resolved_at'])]
class Complaint extends Model
{
    use BelongsToBranch;

    protected function casts(): array
    {
        return ['type' => ComplaintType::class, 'resolved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
