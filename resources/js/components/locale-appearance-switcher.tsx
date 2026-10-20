import { router } from '@inertiajs/react';
import { Check, Globe, Monitor, Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/hooks/use-appearance';
import type { Appearance } from '@/hooks/use-appearance';
import { useTrans } from '@/hooks/use-trans';
import type { Locale } from '@/hooks/use-trans';
import { LOCALES } from '@/lib/locales';
import { update as updateLocale } from '@/routes/locale';

const MODES: { value: Appearance; label: string; icon: typeof Sun }[] = [
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'dark', label: 'Dark', icon: Moon },
    { value: 'system', label: 'System', icon: Monitor },
];

/**
 * Til (4 ta) va ko'rinish rejimi — sarlavhaning o'ng tomonida, har sahifada.
 * Til serverda saqlanadi (keyingi kirishda ham), rejim — brauzerda.
 */
export function LocaleAppearanceSwitcher() {
    const { t, locale } = useTrans();
    const { appearance, resolvedAppearance, updateAppearance } =
        useAppearance();
    const current = LOCALES.find((l) => l.code === locale) ?? LOCALES[0];
    const ModeIcon = resolvedAppearance === 'dark' ? Moon : Sun;

    const changeLocale = (code: Locale) => {
        if (code !== locale) {
            router.put(
                updateLocale.url(),
                { locale: code },
                { preserveScroll: true },
            );
        }
    };

    return (
        <div className="ml-auto flex items-center gap-1">
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="gap-1.5"
                        aria-label={t('Language')}
                    >
                        <Globe className="size-4" />
                        <span className="text-xs font-semibold">
                            {current.short}
                        </span>
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-44">
                    <DropdownMenuLabel>{t('Language')}</DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    {LOCALES.map((l) => (
                        <DropdownMenuItem
                            key={l.code}
                            onSelect={() => changeLocale(l.code)}
                        >
                            <span className="w-7 text-xs text-muted-foreground">
                                {l.short}
                            </span>
                            {l.label}
                            {l.code === locale && (
                                <Check className="ml-auto size-4" />
                            )}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        aria-label={t('Appearance')}
                    >
                        <ModeIcon className="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-40">
                    {MODES.map(({ value, label, icon: Icon }) => (
                        <DropdownMenuItem
                            key={value}
                            onSelect={() => updateAppearance(value)}
                        >
                            <Icon className="size-4" />
                            {t(label)}
                            {appearance === value && (
                                <Check className="ml-auto size-4" />
                            )}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}
