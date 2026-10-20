<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Korporativ mijoz: xodimlari (clients.company_id) naqd to'lamaydi, oy oxirida hisob-faktura.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property string|null $contact_phone
 * @property int $discount_percent
 * @property string|null $note
 * @property bool $is_active
 * @property Carbon $created_at
 */
#[Fillable(['branch_id', 'name', 'contact_phone', 'discount_percent', 'note', 'is_active'])]
class Company extends Model
{
    use Auditable;
    use BelongsToBranch;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'discount_percent' => 'integer'];
    }

    /**
     * @return HasMany<Client, $this>
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
