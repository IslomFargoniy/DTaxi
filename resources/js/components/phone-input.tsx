import { usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import type { ChangeEvent, ComponentProps, Ref } from 'react';
import { useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useTrans } from '@/hooks/use-trans';
import {
    callingCode,
    COUNTRIES,
    countryName,
    defaultCountry,
    examplePlaceholder,
    flagUrl,
    formatNational,
    joinPhone,
    NEARBY,
    rememberCountry,
    splitPhone,
    tooLong,
} from '@/lib/phone';
import type { CountryCode } from '@/lib/phone';
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

function Flag({ country }: { country: CountryCode }) {
    const src = flagUrl(country);

    return src ? (
        <img
            src={src}
            alt=""
            loading="lazy"
            className="h-3.5 w-5 shrink-0 rounded-[2px] object-cover shadow-[0_0_0_1px_rgb(0_0_0/0.08)]"
        />
    ) : (
        <span className="w-5 text-xs">{country}</span>
    );
}

/**
 * Telefon raqami: boshida davlat (bayroq + kod, ro'yxatdan almashtiriladi), keyin milliy qism shu davlat
 * formatida yoziladi — ortiqcha raqam yozilmaydi. Istalgan ko'rinishdagi to'liq raqamni joylash mumkin
 * ("+7 912 …" joylansa davlat o'zi almashadi). Ota komponent E.164 qiymat oladi; `name` berilsa — <Form> uchun yashirin input.
 */
export function PhoneInput({
    value,
    defaultValue,
    onChange,
    name,
    className,
    ref,
    onPaste,
    placeholder,
    disabled,
    ...props
}: Props) {
    const { phoneCountry } = usePage<{ phoneCountry?: string }>().props;
    const controlled = value !== undefined;
    const [fallback, setFallback] = useState<CountryCode>(
        () =>
            splitPhone(value ?? defaultValue, defaultCountry(phoneCountry))
                .country,
    );
    const [inner, setInner] = useState(() => defaultValue ?? '');
    const { country, national } = splitPhone(
        controlled ? value : inner,
        fallback,
    );
    const display = formatNational(country, national);
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

    const commit = (
        nextCountry: CountryCode,
        next: string,
        caretDigits: number,
    ) => {
        pendingCaret.current = caretDigits;
        setFallback(nextCountry);
        const e164 = joinPhone(nextCountry, next);
        if (!controlled) {
            setInner(e164);
        }
        onChange?.(e164);
    };

    // To'liq raqam ("+7 912 …", "00 998 …", "998 90 …") — davlat ham raqamdan olinadi
    const international = (text: string): boolean => {
        const digits = text.replace(/\D/g, '');
        const trimmed = text.trim();
        const full =
            trimmed.startsWith('+') ||
            trimmed.startsWith('00') ||
            (digits.startsWith(callingCode(country)) &&
                tooLong(country, digits));
        if (!full || digits === '') {
            return false;
        }
        const parsed = splitPhone(`+${digits.replace(/^00/, '')}`, country);
        commit(parsed.country, parsed.national, parsed.national.length);

        return true;
    };

    const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
        const raw = e.target.value;
        if (raw.includes('+') && international(raw)) {
            return;
        }
        const caret = e.target.selectionStart ?? raw.length;
        let caretDigits = raw.slice(0, caret).replace(/\D/g, '').length;
        let next = raw.replace(/\D/g, '');
        const inputType = (e.nativeEvent as InputEvent).inputType ?? '';
        // Bo'shliq yoki chiziqcha o'chirildi — undan oldingi (yoki keyingi) raqam o'chadi
        if (next === national && raw.length < display.length) {
            if (inputType === 'deleteContentForward') {
                next = next.slice(0, caretDigits) + next.slice(caretDigits + 1);
            } else if (caretDigits > 0) {
                next = next.slice(0, caretDigits - 1) + next.slice(caretDigits);
                caretDigits--;
            }
        }
        // Eski "8 90 …" yozuvi (O'zbekiston)
        if (country === 'UZ' && next.length === 10 && next.startsWith('8')) {
            next = next.slice(1);
        }
        // Shu davlat uchun ortiqcha raqam qo'shilmaydi
        if (tooLong(country, next) && next.length > national.length) {
            if (international(raw)) {
                return;
            }
            commit(country, national, Math.max(0, caretDigits - 1));

            return;
        }

        commit(country, next, Math.min(caretDigits, next.length));
    };

    const choose = (next: CountryCode) => {
        rememberCountry(next);
        commit(next, national, national.length);
        requestAnimationFrame(() => inputRef.current?.focus());
    };

    return (
        <div
            className={cn(
                'flex h-9 w-full min-w-0 items-stretch rounded-md border border-input bg-transparent shadow-xs transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 has-[[aria-invalid=true]]:border-destructive dark:bg-input/30',
                disabled && 'pointer-events-none opacity-50',
                className,
            )}
        >
            <CountryPicker
                country={country}
                onSelect={choose}
                disabled={disabled}
            />
            <Input
                {...props}
                disabled={disabled}
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
                autoComplete={props.autoComplete ?? 'tel-national'}
                placeholder={placeholder ?? examplePlaceholder(country)}
                className="h-full min-w-0 flex-1 rounded-none border-0 bg-transparent pl-1.5 tabular-nums shadow-none focus-visible:ring-0 dark:bg-transparent"
                value={display}
                onChange={handleChange}
                onPaste={(e) => {
                    onPaste?.(e);
                    const text = e.clipboardData.getData('text');
                    if (!e.defaultPrevented && international(text)) {
                        e.preventDefault();
                    }
                }}
            />
            {name && (
                <input
                    type="hidden"
                    name={name}
                    value={joinPhone(country, national)}
                />
            )}
        </div>
    );
}

function CountryPicker({
    country,
    onSelect,
    disabled,
}: {
    country: CountryCode;
    onSelect: (country: CountryCode) => void;
    disabled?: boolean;
}) {
    const { t } = useTrans();
    const { locale } = usePage<{ locale: string }>().props;
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');

    const all = useMemo(
        () =>
            COUNTRIES.map((c) => ({
                code: c,
                name: countryName(c, locale),
                dial: callingCode(c),
            })).sort((a, b) => a.name.localeCompare(b.name, locale)),
        [locale],
    );
    const q = query.trim().toLowerCase().replace(/^\+/, '');
    const list = q
        ? all.filter(
              (c) =>
                  c.name.toLowerCase().includes(q) ||
                  c.dial.startsWith(q) ||
                  c.code.toLowerCase() === q,
          )
        : [
              ...NEARBY.map((code) => all.find((c) => c.code === code)!),
              ...all.filter((c) => !NEARBY.includes(c.code)),
          ];

    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setQuery('');
            }}
        >
            <PopoverTrigger
                type="button"
                disabled={disabled}
                aria-label={t('Country code')}
                className="flex shrink-0 items-center gap-1.5 rounded-l-md pr-1 pl-3 text-base tabular-nums outline-none hover:bg-accent focus-visible:bg-accent md:text-sm"
            >
                <Flag country={country} />
                <span>+{callingCode(country)}</span>
                <ChevronDown className="size-3.5 text-muted-foreground" />
            </PopoverTrigger>
            <PopoverContent className="w-72 p-0">
                <div className="border-b p-2">
                    <Input
                        autoFocus
                        value={query}
                        placeholder={t('Country or code')}
                        className="h-8"
                        onChange={(e) => setQuery(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && list[0]) {
                                e.preventDefault();
                                onSelect(list[0].code);
                                setOpen(false);
                            }
                        }}
                    />
                </div>
                <div className="max-h-72 overflow-y-auto p-1">
                    {list.map((c, i) => (
                        <button
                            key={c.code}
                            type="button"
                            className={cn(
                                'flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent focus-visible:bg-accent focus-visible:outline-none',
                                c.code === country && 'font-medium',
                                !q &&
                                    i === NEARBY.length - 1 &&
                                    'mb-1 border-b pb-2',
                            )}
                            onClick={() => {
                                onSelect(c.code);
                                setOpen(false);
                            }}
                        >
                            <Flag country={c.code} />
                            <span className="min-w-0 flex-1 truncate">
                                {c.name}
                            </span>
                            <span className="text-muted-foreground tabular-nums">
                                +{c.dial}
                            </span>
                        </button>
                    ))}
                    {list.length === 0 && (
                        <p className="px-2 py-6 text-center text-sm text-muted-foreground">
                            {t('Nothing found')}
                        </p>
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}
