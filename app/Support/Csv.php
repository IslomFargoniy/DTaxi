<?php

namespace App\Support;

/**
 * CSV eksport (Excel): mijoz yozgan matn (ism, manzil) "=", "+", "-", "@" bilan boshlansa Excel uni formula
 * sifatida bajaradi (CSV injection) — oldiga apostrof qo'yiladi. Sonlar o'zgarmaydi.
 */
class Csv
{
    public static function cell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * @param  array<int|string, mixed>  $row
     * @return list<mixed>
     */
    public static function row(array $row): array
    {
        return array_values(array_map(self::cell(...), $row));
    }
}
