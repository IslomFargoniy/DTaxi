<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $phone
 * @property string|null $name
 * @property string|null $note
 * @property bool $is_blacklisted
 * @property int $orders_count
 * @property int|null $last_branch_id
 * @property int|null $company_id
 * @property int|null $discount_percent
 * @property-read Company|null $company
 */
#[Fillable(['phone', 'name', 'note', 'is_blacklisted', 'orders_count', 'last_branch_id', 'company_id', 'discount_percent'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_blacklisted' => 'boolean', 'orders_count' => 'integer'];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
     * Telefon raqamini E.164 ko'rinishiga keltiradi: "90 123 45 67", "8 90 123 45 67",
     * "00998901234567" → "+998901234567". Boshqa (chet el, ichki) raqamlar faqat raqamlarga qisqaradi.
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '8')) {
            // Eski "8 90 ..." yozuvi (8 — shaharlararo prefiks)
            $digits = substr($digits, 1);
        }
        if (strlen($digits) === 9) {
            $digits = '998'.$digits;
        }

        return '+'.$digits;
    }

    /**
     * Qidiruv uchun raqamlar: "90 123 45 67", "8 90 ...", "+998 90 ..." → "998901234567";
     * qisman yozilgani ("90 123") o'zgarishsiz raqamlarga qisqaradi.
     */
    public static function phoneSearchDigits(string $query): string
    {
        $digits = (string) preg_replace('/\D/', '', $query);

        return $digits === '' ? '' : substr(self::normalizePhone($digits), 1);
    }

    /**
     * Ko'rsatish uchun: "+998901234567" → "+998 90 123 45 67"; boshqa raqamlar o'zgarishsiz.
     */
    public static function formatPhone(string $phone): string
    {
        if (preg_match('/^\+998(\d{2})(\d{3})(\d{2})(\d{2})$/', $phone, $m) !== 1) {
            return $phone;
        }

        return "+998 {$m[1]} {$m[2]} {$m[3]} {$m[4]}";
    }

    protected function setPhoneAttribute(string $value): void
    {
        $this->attributes['phone'] = self::normalizePhone($value);
    }
}
