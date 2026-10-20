<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $driver_id
 * @property int $branch_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 */
#[Fillable(['driver_id', 'branch_id', 'started_at', 'ended_at'])]
class DriverShift extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
