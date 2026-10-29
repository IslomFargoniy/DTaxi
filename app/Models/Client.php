<?php

namespace App\Models;

use App\Support\Settings;
use Database\Factories\ClientFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Throwable;

/**
 * @property int $id
 * @property string $phone
 * @property string|null $name
 * @property string $locale
 * @property Carbon|null $app_login_at
 * @property string|null $note
 * @property bool $is_blacklisted
 * @property int $orders_count
 * @property int|null $last_branch_id
 * @property int|null $company_id
 * @property int|null $discount_percent
 * @property-read Company|null $company
 */
#[Fillable(['phone', 'name', 'locale', 'note', 'is_blacklisted', 'orders_count', 'last_branch_id', 'company_id', 'discount_percent'])]
class Client extends Model implements AuthenticatableContract
{
    // Mijoz ilovasi (qaror #71): telefon + qo'ng'iroqdagi kod → Sanctum tokeni (parol yo'q)
    use Authenticatable, HasApiTokens;

    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_blacklisted' => 'boolean', 'orders_count' => 'integer', 'app_login_at' => 'datetime'];
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
     * Telefon raqamini E.164 ko'rinishiga keltiradi: "+7 912 …", "00998…" — xalqaro; "90 123 45 67",
     * "8 90 123 45 67", "998901234567" — firma davlati (company.phone_country) qoidalari bilan.
     * Telefon bo'lmagan qiymatlar (ichki raqam) faqat raqamlarga qisqaradi.
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);

        if (str_starts_with(trim($phone), '+')) {
            return '+'.$digits;
        }
        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }

        $util = PhoneNumberUtil::getInstance();
        $region = self::phoneCountry();
        try {
            $number = $util->parse($digits, $region);
            if ($util->isValidNumber($number)) {
                return $util->format($number, PhoneNumberFormat::E164);
            }
        } catch (NumberParseException) {
            // Pastdagi oddiy qoidalar
        }

        $code = (string) $util->getCountryCodeForRegion($region);
        $national = $util->getMetadataForRegion($region)?->getGeneralDesc()?->getPossibleLength() ?? [];
        $length = $national === [] ? 9 : max($national);
        if (strlen($digits) === $length + 1 && str_starts_with($digits, '8')) {
            // Eski "8 90 ..." yozuvi (8 — shaharlararo prefiks)
            $digits = substr($digits, 1);
        }
        if (strlen($digits) === $length) {
            $digits = $code.$digits;
        }

        return '+'.$digits;
    }

    /**
     * To'liq va haqiqiy telefon raqami (istalgan davlat) — normalizePhone'dan keyin.
     */
    public static function phoneIsValid(string $phone): bool
    {
        $util = PhoneNumberUtil::getInstance();
        try {
            return $util->isValidNumber($util->parse(self::normalizePhone($phone)));
        } catch (NumberParseException) {
            return false;
        }
    }

    /** Milliy ko'rinishda yozilgan raqamlar shu davlatniki deb olinadi */
    public static function phoneCountry(): string
    {
        try {
            $country = app(Settings::class)->get('company.phone_country');
        } catch (Throwable) {
            return 'UZ';
        }

        return is_string($country) && $country !== '' ? strtoupper($country) : 'UZ';
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
    /**
     * Ko'rsatish uchun: "+998901234567" → "+998 90 123 45 67". Telefon bo'lmasa (ichki raqam) — o'zgarishsiz.
     */
    public static function formatPhone(string $phone): string
    {
        if (! str_starts_with($phone, '+')) {
            return $phone;
        }
        $util = PhoneNumberUtil::getInstance();
        try {
            $number = $util->parse($phone);
        } catch (NumberParseException) {
            return $phone;
        }

        return $util->isPossibleNumber($number)
            ? str_replace('-', ' ', $util->format($number, PhoneNumberFormat::INTERNATIONAL))
            : $phone;
    }

    protected function setPhoneAttribute(string $value): void
    {
        $this->attributes['phone'] = self::normalizePhone($value);
    }
}
