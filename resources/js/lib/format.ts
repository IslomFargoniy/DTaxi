import { currencyLabel } from '@/lib/locales';

export { formatPhone } from '@/lib/phone';

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
