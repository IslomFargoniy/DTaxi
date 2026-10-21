import type { ChangeEvent, ComponentProps, Ref } from 'react';
import { useLayoutEffect, useRef, useState } from 'react';
import { Input } from '@/components/ui/input';
import {
    formatPhoneNational,
    PHONE_NATIONAL_LENGTH,
    phoneE164,
    phoneNational,
} from '@/lib/format';
import { cn } from '@/lib/utils';

type Props = Omit<
    ComponentProps<'input'>,
    'value' | 'defaultValue' | 'onChange' | 'type' | 'ref'
> & {
    ref?: Ref<HTMLInputElement>;
    /** E.164 ("+998901234567") yoki bo'sh; boshqa ko'rinishlar ham tushuniladi */
    value?: string | null;
    defaultValue?: string | null;
    /** Har doim E.164 ("+998901234567") yoki "" */
    onChange?: (value: string) => void;
};

// Matndagi kursorgacha bo'lgan raqamlar soni → formatlangan matndagi o'rin
function caretAfterDigits(formatted: string, digits: number): number {
    if (digits <= 0) {
        return 0;
    }
    let seen = 0;
    for (let i = 0; i < formatted.length; i++) {
        if (/\d/.test(formatted[i])) {
            seen++;
            if (seen === digits) {
                return i + 1;
            }
        }
    }

    return formatted.length;
}

/**
 * O'zbekiston telefon raqami: "+998" doimiy, qolgani "90 123 45 67"
 * ko'rinishida yoziladi. Har qanday ko'rinishdagi raqamni joylash mumkin.
 * Ota komponent E.164 qiymat oladi; `name` berilsa — <Form> uchun yashirin input.
 */
export function PhoneInput({
    value,
    defaultValue,
    onChange,
    name,
    className,
    ref,
    onPaste,
    placeholder = '90 123 45 67',
    ...props
}: Props) {
    const controlled = value !== undefined;
    const [inner, setInner] = useState(() => phoneNational(defaultValue));
    const national = controlled ? phoneNational(value) : inner;
    const display = formatPhoneNational(national);
    const inputRef = useRef<HTMLInputElement | null>(null);
    const pendingCaret = useRef<number | null>(null);

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

    const commit = (next: string, caretDigits: number) => {
        pendingCaret.current = caretDigits;
        if (!controlled) {
            setInner(next);
        }
        onChange?.(phoneE164(next));
    };

    const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
        const raw = e.target.value;
        const caret = e.target.selectionStart ?? raw.length;
        let caretDigits = raw.slice(0, caret).replace(/\D/g, '').length;
        let next: string;

        if (
            raw.includes('+') ||
            raw.replace(/\D/g, '').length > PHONE_NATIONAL_LENGTH
        ) {
            // Avtoto'ldirish, to'liq raqam yoki bittalab yozilgan "998..."/"8 90..." → milliy qism
            next = phoneNational(raw);
            caretDigits = next.length;
        } else {
            next = raw.replace(/\D/g, '');
            const inputType = (e.nativeEvent as InputEvent).inputType ?? '';
            // Bo'shliq o'chirildi — undan oldingi (yoki keyingi) raqam o'chadi
            if (next === national && raw.length < display.length) {
                if (inputType === 'deleteContentForward') {
                    next =
                        next.slice(0, caretDigits) +
                        next.slice(caretDigits + 1);
                } else if (caretDigits > 0) {
                    next =
                        next.slice(0, caretDigits - 1) +
                        next.slice(caretDigits);
                    caretDigits--;
                }
            }
            next = next.slice(0, PHONE_NATIONAL_LENGTH);
        }

        commit(next, Math.min(caretDigits, next.length));
    };

    return (
        <div className="relative">
            <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-base text-muted-foreground md:text-sm">
                +998
            </span>
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
                type="tel"
                inputMode="tel"
                autoComplete={props.autoComplete ?? 'tel'}
                placeholder={placeholder}
                className={cn('pl-14 tabular-nums', className)}
                value={display}
                onChange={handleChange}
                onPaste={(e) => {
                    onPaste?.(e);
                    const text = e.clipboardData.getData('text');
                    // To'liq raqam joylansa — butun maydon almashtiriladi
                    if (
                        !e.defaultPrevented &&
                        text.replace(/\D/g, '').length >= PHONE_NATIONAL_LENGTH
                    ) {
                        e.preventDefault();
                        const next = phoneNational(text);
                        commit(next, next.length);
                    }
                }}
            />
            {name && (
                <input type="hidden" name={name} value={phoneE164(national)} />
            )}
        </div>
    );
}
