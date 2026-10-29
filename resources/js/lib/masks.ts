// Rus klaviaturasida yozilgan, lotinga o'xshash harflar (davlat raqami va hujjatlarda faqat lotin)
const CYRILLIC_TO_LATIN: Record<string, string> = {
    А: 'A',
    В: 'B',
    Е: 'E',
    К: 'K',
    М: 'M',
    Н: 'H',
    О: 'O',
    Р: 'P',
    С: 'C',
    Т: 'T',
    У: 'Y',
    Х: 'X',
};

function latinUpper(value: string): string {
    return value
        .toUpperCase()
        .replace(/[А-ЯЁ]/g, (c) => CYRILLIC_TO_LATIN[c] ?? '')
        .replace(/[^0-9A-Z]/g, '');
}

/**
 * O'zbekiston davlat raqami: "01 A 123 BC" (jismoniy shaxs) yoki "01 123 ABC" (yuridik shaxs).
 * Har o'rinda faqat o'ziga mos belgi qabul qilinadi; 3-belgi (harf yoki raqam) ko'rinishni tanlaydi.
 * Server — Vehicle::plateIsValid.
 */
export function maskPlate(value: string): string {
    const chars = latinUpper(value);
    let out = '';
    let legal: boolean | null = null;
    for (const c of chars) {
        const i = out.length;
        if (i >= 8) {
            break;
        }
        const digit = /\d/.test(c);
        if (i === 2) {
            legal = digit;
        }
        // Jismoniy: R R H R R R H H; yuridik: R R R R R H H H
        const wantDigit =
            i < 2 || (legal ? i < 5 : i >= 3 && i < 6) || (i === 2 && digit);
        if (digit === wantDigit) {
            out += c;
        }
    }

    const parts = legal
        ? [out.slice(0, 2), out.slice(2, 5), out.slice(5)]
        : [out.slice(0, 2), out.slice(2, 3), out.slice(3, 6), out.slice(6)];

    return parts.filter((p) => p !== '').join(' ');
}

/**
 * Hujjat raqami: avval lotin harflari (seriya), keyin raqamlar — "AF 1234567", "AAF 1234567".
 * Raqamdan keyin harf yozilmaydi.
 */
export function maskDocument(
    value: string,
    letters: number,
    digits: number,
): string {
    const chars = latinUpper(value);
    let series = '';
    let number = '';
    for (const c of chars) {
        if (/\d/.test(c)) {
            if (number.length < digits) {
                number += c;
            }
        } else if (number === '' && series.length < letters) {
            series += c;
        }
    }

    return number === '' ? series : `${series} ${number}`.trim();
}

/** Ism va rang: raqam va belgilar yozilmaydi (harf, bo'shliq, tutuq belgisi, chiziqcha) */
export function maskWords(value: string): string {
    return value.replace(/[^\p{L}\s'ʻʼ‘’`-]/gu, '').replace(/\s{2,}/g, ' ');
}
