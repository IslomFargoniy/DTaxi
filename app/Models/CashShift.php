<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Kassir smenasi: ochish, yopish, jami naqd.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $cashier_id
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property int $total_cash
 */
#[Fillable(['branch_id', 'cashier_id', 'opened_at', 'closed_at', 'total_cash', 'admin_confirmed_by'])]
class CashShift extends Model
{
    use BelongsToBranch;

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'total_cash' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
