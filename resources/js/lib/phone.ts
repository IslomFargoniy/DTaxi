import {
    AsYouType,
    getCountries,
    getExampleNumber,
    getCountryCallingCode,
    parsePhoneNumberFromString,
    validatePhoneNumberLength,
} from 'libphonenumber-js';
import type { CountryCode } from 'libphonenumber-js';
import examples from 'libphonenumber-js/mobile/examples';

export type { CountryCode };

// Bayroqlar alohida fayl: ro'yxat ochilganda faqat ko'rinayotganlari yuklanadi (emoji bayroqlar Windows'da chiqmaydi)
const FLAGS = import.meta.glob<string>(
    '/node_modules/country-flag-icons/3x2/*.svg',
    { query: '?no-inline', import: 'default', eager: true },
);

export function flagUrl(country: CountryCode): string | undefined {
    return FLAGS[`/node_modules/country-flag-icons/3x2/${country}.svg`];
}

export const COUNTRIES: CountryCode[] = getCountries();

/** Ro'yxat boshida turadigan qo'shni davlatlar */
export const NEARBY: CountryCode[] = [
    'UZ',
    'KZ',
    'KG',
    'TJ',
    'TM',
    'RU',
    'AZ',
    'TR',
];

// Brauzer vaqt mintaqasi → davlat (foydalanuvchi qayerdan kirayotgani)
const TIMEZONES: Record<string, CountryCode> = {
    'Asia/Tashkent': 'UZ',
    'Asia/Samarkand': 'UZ',
    'Asia/Almaty': 'KZ',
    'Asia/Qostanay': 'KZ',
    'Asia/Aqtobe': 'KZ',
    'Asia/Aqtau': 'KZ',
    'Asia/Atyrau': 'KZ',
    'Asia/Oral': 'KZ',
    'Asia/Qyzylorda': 'KZ',
    'Asia/Bishkek': 'KG',
    'Asia/Dushanbe': 'TJ',
    'Asia/Ashgabat': 'TM',
    'Asia/Baku': 'AZ',
    'Asia/Tbilisi': 'GE',
    'Asia/Yerevan': 'AM',
    'Europe/Moscow': 'RU',
    'Europe/Samara': 'RU',
    'Asia/Yekaterinburg': 'RU',
    'Asia/Novosibirsk': 'RU',
    'Europe/Minsk': 'BY',
    'Europe/Kyiv': 'UA',
    'Europe/Kiev': 'UA',
    'Europe/Istanbul': 'TR',
    'Asia/Dubai': 'AE',
};

const STORAGE_KEY = 'phone-country';

export function isCountry(value: unknown): value is CountryCode {
    return (
        typeof value === 'string' && COUNTRIES.includes(value as CountryCode)
    );
}

/**
 * Yangi raqam uchun davlat: oxirgi tanlangani → brauzer vaqt mintaqasi → firma sozlamasi.
 */
export function defaultCountry(firm: string | undefined): CountryCode {
    try {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (isCountry(saved)) {
            return saved;
        }
    } catch {
        // Saqlash yopiq (inkognito) — keyingi qoidaga o'tiladi
    }
    const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (zone && TIMEZONES[zone]) {
        return TIMEZONES[zone];
    }

    return isCountry(firm) ? firm : 'UZ';
}

export function rememberCountry(country: CountryCode): void {
    try {
        localStorage.setItem(STORAGE_KEY, country);
    } catch {
        // Eslab qolinmasa ham maydon ishlayveradi
    }
}

export function callingCode(country: CountryCode): string {
    return getCountryCallingCode(country);
}

/**
 * E.164 qiymatdan davlat va milliy qism. To'liq bo'lmagan raqam (yozish davomida) ham —
 * davlat kodi `fallback` nikiga mos kelsa shu davlat qoladi (+7: RU va KZ bir xil).
 */
export function splitPhone(
    value: string | null | undefined,
    fallback: CountryCode,
): { country: CountryCode; national: string } {
    const raw = (value ?? '').trim();
    if (raw === '') {
        return { country: fallback, national: '' };
    }
    const digits = raw.replace(/\D/g, '');
    const own = callingCode(fallback);
    const parsed = parsePhoneNumberFromString(
        raw.startsWith('+') ? raw : `+${digits}`,
    );
    if (parsed?.country && parsed.isPossible()) {
        return { country: parsed.country, national: parsed.nationalNumber };
    }
    if (digits.startsWith(own)) {
        return { country: fallback, national: digits.slice(own.length) };
    }
    // Eng uzun mos keladigan davlat kodi
    const match = COUNTRIES.map((c) => ({ c, code: callingCode(c) }))
        .filter(({ code }) => digits.startsWith(code))
        .sort((a, b) => b.code.length - a.code.length)[0];

    return match
        ? { country: match.c, national: digits.slice(match.code.length) }
        : { country: fallback, national: digits };
}

export function joinPhone(country: CountryCode, national: string): string {
    return national === '' ? '' : `+${callingCode(country)}${national}`;
}

/** Milliy qism shu davlat uchun juda uzunmi (ortiqcha raqam yozilmaydi) */
export function tooLong(country: CountryCode, national: string): boolean {
    return validatePhoneNumberLength(national, country) === 'TOO_LONG';
}

/** "901234567" → "90 123 45 67" (yozish davomida qisman ham) */
export function formatNational(country: CountryCode, national: string): string {
    return national === '' ? '' : new AsYouType(country).input(national);
}

/** Davlatning namunaviy raqami — placeholder uchun */
export function examplePlaceholder(country: CountryCode): string {
    return getExampleNumber(country, examples)?.formatNational() ?? '';
}

/**
 * Ko'rsatish uchun: "+998901234567" → "+998 90 123 45 67", "+79123456789" → "+7 912 345 67 89".
 * Telefon raqami bo'lmasa (ichki raqam) — o'zgarishsiz.
 */
export function formatPhone(value: string | null | undefined): string {
    if (!value) {
        return '';
    }
    const digits = value.replace(/\D/g, '');
    // Eski yozuvlar: "+" siz yoki faqat milliy 9 raqam (O'zbekiston)
    const candidate = value.trim().startsWith('+')
        ? value
        : digits.length === 9
          ? `+998${digits}`
          : `+${digits}`;
    const parsed = parsePhoneNumberFromString(candidate);

    return parsed?.isPossible()
        ? parsed.formatInternational().replace(/-/g, ' ')
        : value;
}

/** Davlat nomi joriy tilda (o'zbek kirill — lotin nomi) */
export function countryName(country: CountryCode, locale: string): string {
    try {
        const names = new Intl.DisplayNames([locale === 'oz' ? 'uz' : locale], {
            type: 'region',
        });

        return names.of(country) ?? country;
    } catch {
        return country;
    }
}
