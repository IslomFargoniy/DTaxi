import { currencyLabel } from '@/lib/locales';

/** O'zbekiston raqamining milliy qismi (operator kodi + 7 raqam) */
export const PHONE_NATIONAL_LENGTH = 9;

/**
 * Har qanday ko'rinishdagi raqamdan milliy qism: "+998 90 123-45-67",
 * "998901234567", "8 90 123 45 67", "(90) 123 45 67" → "901234567".
 * Server tomoni — Client::normalizePhone bilan bir xil qoidalar.
 */
export function phoneNational(raw: string | null | undefined): string {
    let digits = (raw ?? '').replace(/\D/g, '');
    // "+998..." — har doim mamlakat kodi (to'liq bo'lmagan qiymat ham: maydon yozish davomida "+9989" beradi)
    const international = (raw ?? '').trim().startsWith('+998');
    if (
        digits.startsWith('998') &&
        (international || digits.length > PHONE_NATIONAL_LENGTH)
    ) {
        digits = digits.slice(3);
    } else if (
        digits.startsWith('8') &&
        digits.length === PHONE_NATIONAL_LENGTH + 1
    ) {
        // Eski "8 90 ..." yozuvi
        digits = digits.slice(1);
    }

    return digits.slice(0, PHONE_NATIONAL_LENGTH);
}

/** "901234567" → "90 123 45 67" (yozish davomida qisman ham) */
export function formatPhoneNational(national: string): string {
    const parts = [
        national.slice(0, 2),
        national.slice(2, 5),
        national.slice(5, 7),
        national.slice(7, 9),
    ];

    return parts.filter((p) => p !== '').join(' ');
}

/** Milliy qism → E.164 ("" bo'sh bo'lsa) */
export function phoneE164(national: string): string {
    return national === '' ? '' : `+998${national}`;
}

/**
 * Ko'rsatish uchun: "+998901234567" → "+998 90 123 45 67".
 * O'zbek raqami bo'lmasa (ichki raqam, chet el) — o'zgarishsiz.
 */
export function formatPhone(value: string | null | undefined): string {
    if (!value) {
        return '';
    }
    const digits = value.replace(/\D/g, '');
    const uz =
        (digits.length === 12 && digits.startsWith('998')) ||
        digits.length === PHONE_NATIONAL_LENGTH;
    if (!uz) {
        return value;
    }

    return `+998 ${formatPhoneNational(digits.slice(-PHONE_NATIONAL_LENGTH))}`;
}

const numberFormat = new Intl.NumberFormat('ru-RU', {
    maximumFractionDigits: 2,
});

/** 25000 → "25 000" (minglik ajratgich — bo'shliq) */
export function formatNumber(value: number | null | undefined): string {
    return value === null || value === undefined || Number.isNaN(value)
        ? '—'
        : numberFormat.format(value).replace(/\s/g, ' ');
}

/** 25000 → "25 000 so'm" (joriy til valyutasi bilan) */
export function formatMoney(value: number | null | undefined): string {
    return value === null || value === undefined || Number.isNaN(value)
        ? '—'
        : `${formatNumber(value)} ${currencyLabel()}`;
}

/**
 * Davlat raqami: saqlanadi bo'shliqsiz ("01A123BC"), ko'rsatiladi
 * "01 A 123 BC" (jismoniy shaxs) yoki "01 123 ABC" (yuridik shaxs).
 */
export function formatPlate(value: string | null | undefined): string {
    if (!value) {
        return '';
    }
    const compact = value.toUpperCase().replace(/[^0-9A-Z]/g, '');
    const person = compact.match(/^(\d{2})([A-Z])(\d{3})([A-Z]{2})$/);
    if (person) {
        return person.slice(1).join(' ');
    }
    const legal = compact.match(/^(\d{2})(\d{3})([A-Z]{3})$/);
    if (legal) {
        return legal.slice(1).join(' ');
    }

    return value.toUpperCase();
}

/** Foiz maydoni: butun son 0–100 oralig'ida; bo'sh bo'lsa null */
export function toPercent(raw: string): number | null {
    if (raw.trim() === '') {
        return null;
    }
    const value = Math.trunc(Number(raw));

    return Number.isNaN(value) ? 0 : Math.min(100, Math.max(0, value));
}
