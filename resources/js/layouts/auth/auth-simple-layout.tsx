import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { LocaleAppearanceSwitcher } from '@/components/locale-appearance-switcher';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';
import { useTrans } from '@/hooks/use-trans';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { t } = useTrans();

    return (
        <div className="relative flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            {/* Kirishdan oldin ham til va rejimni tanlash mumkin */}
            <div className="absolute top-4 right-4 flex">
                <LocaleAppearanceSwitcher />
            </div>
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2 font-medium"
                        >
                            <div className="mb-1 flex size-14 items-center justify-center rounded-2xl bg-[#FFC800]">
                                <AppLogoIcon className="size-8 fill-current text-[#111111]" />
                            </div>
                            <span className="sr-only">{t(title ?? '')}</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">
                                {t(title ?? '')}
                            </h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {t(description ?? '')}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
