<?php

namespace App\Rules;

use App\Models\Client;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Telefon raqami (istalgan davlat): istalgan ko'rinishda keladi ("+998 90 123-45-67", "+7 912 345 67 89",
 * "998901234567", "8 90 123 45 67", "901234567"), normalizePhone'dan keyin to'liq va haqiqiy raqam bo'lishi shart.
 * Milliy ko'rinishdagisi firma davlatiniki deb olinadi (company.phone_country).
 */
class Phone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::passes($value)) {
            $fail(__('Enter a valid phone number.'));
        }
    }

    public static function passes(string $value): bool
    {
        // Faqat raqam va odatiy ajratgichlar — harflar bilan "raqam" qabul qilinmaydi
        if (preg_match('/^[\d\s()+\-.]{7,25}$/', trim($value)) !== 1) {
            return false;
        }

        return Client::phoneIsValid($value);
    }
}
