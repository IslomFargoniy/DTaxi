<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Filialga tegishli model: admin/operator/kassir faqat o'ziga biriktirilgan filiallar
 * ma'lumotini ko'radi; superadmin, konsol va navbat ishlari — hammasini.
 *
 * @property int|null $branch_id
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope('branch', function (Builder $query) {
            $user = auth()->user();

            if ($user instanceof User && ! $user->isSuperadmin()) {
                $query->whereIn($query->qualifyColumn('branch_id'), $user->branchIds());
            }
        });
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
