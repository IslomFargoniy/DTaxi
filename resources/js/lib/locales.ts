import type { Locale } from '@/hooks/use-trans';

/** Tizim tillari — har biri o'z tilida (foydalanuvchi o'z tilini taniydi) */
export const LOCALES: { code: Locale; label: string; short: string }[] = [
    { code: 'uz', label: "O'zbekcha", short: 'UZ' },
    { code: 'oz', label: 'Ўзбекча', short: 'ЎЗ' },
    { code: 'ru', label: 'Русский', short: 'RU' },
    { code: 'en', label: 'English', short: 'EN' },
];

/** Xarita nomlari tili: kirill o'zbekcha uchun OSM'dagi o'zbekcha nomlar */
export function mapLang(locale: Locale): string {
    return locale === 'oz' ? 'uz' : locale;
}

const CURRENCY: Record<Locale, string> = {
    uz: "so'm",
    oz: 'сўм',
    ru: 'сум',
    en: 'UZS',
};

// formatMoney oddiy funksiya (hook emas) — joriy tilni useTrans yangilab turadi
let currentLocale: Locale = 'uz';

export function setCurrentLocale(locale: Locale): void {
    currentLocale = locale;
}

export function currencyLabel(): string {
    return CURRENCY[currentLocale] ?? CURRENCY.uz;
}
