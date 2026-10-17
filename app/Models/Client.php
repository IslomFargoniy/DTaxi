<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $phone
 * @property string|null $name
 * @property string|null $note
 * @property bool $is_blacklisted
 * @property int $orders_count
 * @property int|null $last_branch_id
 */
#[Fillable(['phone', 'name', 'note', 'is_blacklisted', 'orders_count', 'last_branch_id'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_blacklisted' => 'boolean', 'orders_count' => 'integer'];
    }

    /**
     * @return HasMany<ClientAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(ClientAddress::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Telefon raqamini E.164 ko'rinishiga keltiradi: "90 123 45 67" → "+998901234567".
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 9) {
            $digits = '998'.$digits;
        }

        return '+'.$digits;
    }

    protected function setPhoneAttribute(string $value): void
    {
        $this->attributes['phone'] = self::normalizePhone($value);
    }
}
