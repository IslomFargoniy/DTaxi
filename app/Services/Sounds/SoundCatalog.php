<?php

namespace App\Services\Sounds;

use Illuminate\Support\Str;

/**
 * Ovozli bo'laklar katalogi (reja §5.4, qaror #14): bir marta generatsiya qilinadi (SpeechKit, ayol ovozi).
 * Kalit → aytiladigan matn. Mashina modellari va ranglari bazadagi mashinalardan qo'shiladi.
 */
class SoundCatalog
{
    /** @var array<string, string> */
    public const PHRASES = [
        'to-you' => 'Sizga',
        'car-number' => 'raqami',
        'about' => 'taxminan',
        'minutes-arrives' => 'daqiqada yetib keladi',
        'driver-waiting' => 'Sizni haydovchi kutmoqda.',
        'still-waiting' => 'Haydovchi hali ham sizni kutmoqda.',
        'no-driver' => "Kechirasiz, hozir bo'sh mashina yo'q. Operator siz bilan bog'lanadi.",
        'cancelled' => 'Buyurtmangiz bekor qilindi.',
        'thanks' => "Rahmat, oq yo'l!",
        'for' => 'uchun',
        'press' => 'ni bosing',
        'repeat' => 'Xabarni takrorlash uchun bir ni bosing.',
    ];

    /** @var array<string, string> */
    public const IVR = [
        'welcome' => 'Assalomu alaykum! Taksi xizmatiga xush kelibsiz.',
        'operators-busy' => 'Barcha operatorlar band, iltimos kuting.',
        'please-wait' => 'Iltimos, kuting. Operator tez orada javob beradi.',
        'lines-busy' => "Hozir barcha liniyalar band. Iltimos, keyinroq qo'ng'iroq qiling.",
        'call-later' => "Kechirasiz, operatorlar band. Biz sizga qayta qo'ng'iroq qilamiz.",
        'closed' => 'Hozir ish vaqti emas.',
        'choose-branch' => 'Kerakli filialni tanlang.',
        'auto-order-offer' => 'Oxirgi manzilingizga taksi chaqirish uchun bir ni, operator bilan gaplashish uchun ikki ni bosing.',
        'order-accepted' => "Buyurtmangiz qabul qilindi. Haydovchi topilgach sizga qo'ng'iroq qilamiz.",
    ];

    /** @var array<int, string> raqamlar: 0–9, o'nliklar, yuz, ming */
    public const NUMBERS = [
        '0' => 'nol', '1' => 'bir', '2' => 'ikki', '3' => 'uch', '4' => "to'rt", '5' => 'besh', '6' => 'olti',
        '7' => 'yetti', '8' => 'sakkiz', '9' => "to'qqiz", '10' => "o'n", '20' => 'yigirma', '30' => "o'ttiz",
        '40' => 'qirq', '50' => 'ellik', '60' => 'oltmish', '70' => 'yetmish', '80' => 'sakson', '90' => "to'qson",
        '100' => 'yuz', '1000' => 'ming',
    ];

    /** @var array<string, string> davlat raqami harflari — o'zbekcha o'qilishi */
    public const LETTERS = [
        'a' => 'a', 'b' => 'be', 'c' => 'se', 'd' => 'de', 'e' => 'e', 'f' => 'ef', 'g' => 'ge', 'h' => 'ha',
        'i' => 'i', 'j' => 'je', 'k' => 'ka', 'l' => 'el', 'm' => 'em', 'n' => 'en', 'o' => 'o', 'p' => 'pe',
        'q' => 'qu', 'r' => 'er', 's' => 'es', 't' => 'te', 'u' => 'u', 'v' => 've', 'w' => 'dubl ve', 'x' => 'xa',
        'y' => 'ye', 'z' => 'ze',
    ];

    /**
     * @return array<string, array<int|string, string>> kategoriya → kalit → matn
     */
    public static function all(): array
    {
        return [
            'phrase' => self::PHRASES,
            'ivr' => self::IVR,
            'number' => self::NUMBERS,
            'letter' => self::LETTERS,
        ];
    }

    /** Mashina modeli / rang / filial nomi uchun kalit */
    public static function key(string $text): string
    {
        return Str::slug(str_replace(["'", 'ʻ', '‘', '’'], '', $text)) ?: 'x';
    }
}
