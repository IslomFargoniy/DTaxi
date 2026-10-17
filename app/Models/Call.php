<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Qo'ng'iroq (CDR): kiruvchi, operator callback, avto-qo'ng'iroq (TTS), haydovchi ↔ dispetcher.
 *
 * @property int $id
 * @property string|null $channel_id
 * @property int|null $branch_id
 * @property string $direction inbound | outbound | internal
 * @property string $kind client | tts | driver | callback
 * @property string|null $caller
 * @property string|null $callee
 * @property int|null $phone_number_id
 * @property int|null $sip_trunk_id
 * @property int|null $operator_id
 * @property int|null $driver_id
 * @property int|null $client_id
 * @property int|null $order_id
 * @property string $status
 * @property int $attempt
 * @property Carbon $started_at
 * @property Carbon|null $answered_at
 * @property Carbon|null $ended_at
 * @property int|null $wait_seconds
 * @property int|null $talk_seconds
 * @property string|null $recording_path
 * @property array<string, mixed>|null $meta
 */
#[Fillable([
    'channel_id', 'branch_id', 'direction', 'kind', 'caller', 'callee', 'phone_number_id', 'sip_trunk_id',
    'operator_id', 'driver_id', 'client_id', 'order_id', 'status', 'attempt', 'started_at', 'answered_at',
    'ended_at', 'wait_seconds', 'talk_seconds', 'recording_path', 'meta',
])]
class Call extends Model
{
    use BelongsToBranch;

    public const ACTIVE = ['ringing', 'waiting', 'answered'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
