<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FCM push tokenlari.
 *
 * @property int $id
 * @property int $user_id
 * @property string $platform
 * @property string $token
 */
#[Fillable(['user_id', 'platform', 'token'])]
class DeviceToken extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
