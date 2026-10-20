<?php

namespace App\Support;

/**
 * O'zbek lotin yozuvini kirillga o'girish (tarjima fayllari uchun: lang/uz → lang/oz).
 * O'rinbosarlar (:name), HTML teglari, havolalar va brend nomlari (Click, Payme, SIP...) o'zgarmaydi.
 */
class UzbekCyrillic
{
    /** Lotinda qoladigan nomlar */
    private const KEEP = ['Dtaxi', 'Click', 'Payme', 'SpeechKit', 'Firebase', 'Telegram', 'Android', 'Asterisk', 'Reverb', 'Horizon', 'Yandex', 'Google', 'OpenStreetMap', 'WebRTC', 'Wi-Fi', 'Sarkor', 'Uztelecom', 'Beeline', 'Ucell', 'Mobiuz', 'FCM', 'ARI', 'SIP', 'OSRM', 'OSM', 'APK', 'SMS', 'CSV', 'PDF', 'ID', 'IP', 'URL', 'API', 'GPS', 'SFTP', 'JSON', 'PIN', 'QR', 'PBX', 'WSS'];

    private const LETTERS = [
        'a' => 'а', 'b' => 'б', 'd' => 'д', 'f' => 'ф', 'g' => 'г', 'h' => 'ҳ', 'i' => 'и', 'j' => 'ж', 'k' => 'к',
        'l' => 'л', 'm' => 'м', 'n' => 'н', 'o' => 'о', 'p' => 'п', 'q' => 'қ', 'r' => 'р', 's' => 'с', 't' => 'т',
        'u' => 'у', 'v' => 'в', 'x' => 'х', 'y' => 'й', 'z' => 'з', 'e' => 'е',
    ];

    private const VOWELS = 'aeiouAEIOU';

    public static function convert(string $text): string
    {
        // O'zgarmaydigan bo'laklar: :o'rinbosar, {{ }}, <teg>, havola, `kod`
        $parts = preg_split('/(:[A-Za-z_]+|\{\{.*?\}\}|<[^>]+>|https?:\/\/\S+|`[^`]*`)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        $out = '';
        foreach ($parts as $i => $part) {
            $out .= $i % 2 === 1 ? $part : self::words($part);
        }

        return $out;
    }

    private static function words(string $text): string
    {
        // Apostrof turlari bir xil ko'rinishga: o' g' va tutuq belgisi
        $text = str_replace(['ʻ', '‘', '’', 'ʼ', '`'], "'", $text);

        return (string) preg_replace_callback("/[A-Za-z][A-Za-z']*/u", fn (array $m) => self::word($m[0]), $text);
    }

    private static function word(string $word): string
    {
        $bare = rtrim($word, "'");
        // "Payme'da", "SIP'ni" — brend/qisqartma + qo'shimcha: so'z butunligicha lotinda
        $stem = explode("'", $bare)[0];
        if ($stem !== $bare && (in_array($stem, self::KEEP, true) || (strlen($stem) > 1 && strtoupper($stem) === $stem))) {
            return $word;
        }
        if (in_array($bare, self::KEEP, true) || preg_match('/[cC](?![hH])|[wW]/', $bare) || (strlen($bare) > 1 && strtoupper($bare) === $bare && strlen($bare) <= 5)) {
            return $word;
        }

        $upperAll = strlen($bare) > 1 && strtoupper($bare) === $bare;
        $upperFirst = ctype_upper($word[0]);
        $lower = strtolower($word);
        $result = '';
        $length = strlen($lower);

        for ($i = 0; $i < $length; $i++) {
            $c = $lower[$i];
            $next = $lower[$i + 1] ?? '';
            $prev = $i > 0 ? $lower[$i - 1] : '';
            $wordStart = $i === 0;

            if (($c === 'o' || $c === 'g') && $next === "'") {
                $result .= $c === 'o' ? 'ў' : 'ғ';
                $i++;

                continue;
            }
            if ($c === 's' && $next === 'h') {
                $result .= 'ш';
                $i++;

                continue;
            }
            if ($c === 'c' && $next === 'h') {
                $result .= 'ч';
                $i++;

                continue;
            }
            if ($c === 'y' && in_array($next, ['a', 'o', 'u', 'e'], true) && ! ($next === 'o' && ($lower[$i + 2] ?? '') === "'")) {
                // ya, yo, yu, ye → я, ё, ю, е (ye so'z boshida yoki unlidan keyin)
                $result .= ['a' => 'я', 'o' => 'ё', 'u' => 'ю', 'e' => 'е'][$next];
                $i++;

                continue;
            }
            if ($c === 'e' && ($wordStart || str_contains(self::VOWELS, $prev))) {
                $result .= 'э';

                continue;
            }
            if ($c === "'") {
                // Tutuq belgisi (ma'lum, sur'at) — ъ; so'z oxirida — o'zi
                $result .= $i === $length - 1 ? "'" : 'ъ';

                continue;
            }

            $result .= self::LETTERS[$c] ?? $c;
        }

        if ($upperAll) {
            return mb_strtoupper($result);
        }

        return $upperFirst ? mb_strtoupper(mb_substr($result, 0, 1)).mb_substr($result, 1) : $result;
    }
}
