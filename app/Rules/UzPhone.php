<?php

namespace App\Rules;

use App\Models\Client;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * O'zbekiston telefon raqami: istalgan ko'rinishda keladi ("+998 90 123-45-67",
 * "998901234567", "8 90 123 45 67", "901234567"), normalizePhone'dan keyin
 * +998 va 9 raqam bo'lishi shart.
 */
class UzPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::passes($value)) {
            $fail(__('Enter a phone number in the format +998 90 123 45 67.'));
        }
    }

    public static function passes(string $value): bool
    {
        // Faqat raqam va odatiy ajratgichlar — harflar bilan "raqam" qabul qilinmaydi
        if (preg_match('/^[\d\s()+\-.]{9,25}$/', trim($value)) !== 1) {
            return false;
        }

        return preg_match('/^\+998\d{9}$/', Client::normalizePhone($value)) === 1;
    }
}
