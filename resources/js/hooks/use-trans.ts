import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';

export type Locale = 'uz' | 'ru';

/**
 * Tarjima: kalit — inglizcha matn, qiymat — lang/{uz,ru}.json dan.
 * `:name` ko'rinishidagi o'rinbosarlar `replace` orqali to'ldiriladi.
 */
export function useTrans() {
    const { locale, translations } = usePage<{
        locale: Locale;
        translations: Record<string, string>;
    }>().props;

    const t = useCallback(
        (key: string, replace?: Record<string, string | number>) => {
            let text = translations?.[key] ?? key;

            if (replace) {
                for (const [name, value] of Object.entries(replace)) {
                    text = text.replaceAll(`:${name}`, String(value));
                }
            }

            return text;
        },
        [translations],
    );

    return { t, locale };
}
