<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Mijoz ilovasi FCM tokenlari (qaror #71).
 *
 * @property int $id
 * @property int $client_id
 * @property string $token
 */
#[Fillable(['client_id', 'token'])]
class ClientDeviceToken extends Model {}
