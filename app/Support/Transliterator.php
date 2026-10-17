<?php

namespace App\Support;

/**
 * Qidiruv uchun matnni bir xil ko'rinishga keltiradi: kirill (o'zbek/rus) → lotin, kichik harf,
 * apostroflar olib tashlanadi. "Чорсу", "Chorsu", "Chorsu bozori" bir-birini topadi.
 */
class Transliterator
{
    private const CYRILLIC = [
        'ш' => 'sh', 'ч' => 'ch', 'щ' => 'sh', 'ж' => 'j', 'ю' => 'yu', 'я' => 'ya', 'ё' => 'yo',
        'ц' => 's', 'ў' => 'o', 'ғ' => 'g', 'қ' => 'q', 'ҳ' => 'h', 'ы' => 'i', 'э' => 'e', 'е' => 'e',
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'з' => 'z', 'и' => 'i', 'й' => 'y',
        'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's',
        'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'x', 'ъ' => '', 'ь' => '',
    ];

    public static function forSearch(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, self::CYRILLIC);
        // O'zbek lotin yozuvidagi apostrof variantlari: o' / oʻ / o‘ / o`
        $text = str_replace(["'", 'ʻ', 'ʼ', '‘', '’', '`'], '', $text);
        // Rus va o'zbek yozuvidagi farqlar: "x" va "h" (Xorazm/Хорезм), "ts" va "s"
        $text = str_replace(['kh', 'ts'], ['x', 's'], $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
