import type { ChangeEvent, ComponentProps, Ref } from 'react';
import { useLayoutEffect, useRef, useState } from 'react';
import { Input } from '@/components/ui/input';
import { currencyLabel } from '@/lib/locales';
import { cn } from '@/lib/utils';

type Props = Omit<
    ComponentProps<'input'>,
    'value' | 'onChange' | 'type' | 'ref' | 'min' | 'max'
> & {
    ref?: Ref<HTMLInputElement>;
    value: number | string | null | undefined;
    onChange: (value: number | null) => void;
    /** Manfiy qiymat (masalan, balansdan yechish: "-5 000") */
    allowNegative?: boolean;
    /** Kiritish maydoni ichida valyuta ("so'm") ko'rsatiladi */
    currency?: boolean;
    max?: number;
};

function group(digits: string): string {
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

function caretAfterDigits(formatted: string, digits: number): number {
    let seen = 0;
    for (let i = 0; i < formatted.length; i++) {
        if (digits === seen && formatted[i] !== '-') {
            return i;
        }
        if (/\d/.test(formatted[i])) {
            seen++;
        }
    }

    return formatted.length;
}

/**
 * Pul summasi (butun so'm): "25 000" ko'rinishida, faqat raqamlar.
 * Ota komponent son (yoki bo'sh bo'lsa null) oladi.
 */
export function MoneyInput({
    value,
    onChange,
    allowNegative = false,
    currency = true,
    max,
    className,
    ref,
    ...props
}: Props) {
    const inputRef = useRef<HTMLInputElement | null>(null);
    const pendingCaret = useRef<number | null>(null);
    const number =
        value === null || value === undefined || value === ''
            ? null
            : Math.trunc(Number(value));
    // Faqat "-" yozilgan (raqam hali yo'q) holat
    const [negativeDraft, setNegativeDraft] = useState(false);
    const display =
        number === null || Number.isNaN(number)
            ? negativeDraft
                ? '-'
                : ''
            : (number < 0 ? '-' : '') + group(String(Math.abs(number)));

    useLayoutEffect(() => {
        const el = inputRef.current;
        if (pendingCaret.current === null || !el) {
            return;
        }
        const pos = caretAfterDigits(display, pendingCaret.current);
        pendingCaret.current = null;
        if (document.activeElement === el) {
            el.setSelectionRange(pos, pos);
        }
    });

    const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
        const raw = e.target.value;
        const caret = e.target.selectionStart ?? raw.length;
        const negative = allowNegative && raw.trim().startsWith('-');
        let digits = raw.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
        let caretDigits = raw.slice(0, caret).replace(/\D/g, '').length;
        // Bo'shliq o'chirildi — undan oldingi raqam o'chadi
        if (
            number !== null &&
            digits === String(Math.abs(number)) &&
            raw.length < display.length &&
            raw.startsWith('-') === display.startsWith('-') &&
            caretDigits > 0
        ) {
            digits =
                digits.slice(0, caretDigits - 1) + digits.slice(caretDigits);
            caretDigits--;
        }
        digits = digits.slice(0, 12);
        pendingCaret.current = caretDigits;
        setNegativeDraft(negative && digits === '');
        if (digits === '') {
            onChange(null);

            return;
        }
        let next = Number(digits) * (negative ? -1 : 1);
        if (max !== undefined && next > max) {
            next = max;
        }
        onChange(next);
    };

    return (
        <div className="relative">
            <Input
                {...props}
                ref={(el) => {
                    inputRef.current = el;
                    if (typeof ref === 'function') {
                        ref(el);
                    } else if (ref) {
                        ref.current = el;
                    }
                }}
                type="text"
                inputMode={allowNegative ? 'text' : 'numeric'}
                autoComplete="off"
                className={cn('tabular-nums', currency && 'pr-14', className)}
                value={display}
                onChange={handleChange}
            />
            {currency && (
                <span className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-sm text-muted-foreground">
                    {currencyLabel()}
                </span>
            )}
        </div>
    );
}
