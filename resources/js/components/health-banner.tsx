import { usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useTrans } from '@/hooks/use-trans';

/**
 * Tizim muammolari (superadmin): Asterisk/trunk uzilgan, navbatlar to'xtagan, disk to'lgan va h.k.
 */
export function HealthBanner() {
    const { health } = usePage<{ health?: string[] }>().props;
    const { t } = useTrans();

    if (!health || health.length === 0) {
        return null;
    }

    return (
        <div className="mx-4 mt-2 flex items-start gap-2 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
            <ul className="grid gap-0.5">
                {health.map((problem) => (
                    <li key={problem}>{t(problem)}</li>
                ))}
            </ul>
        </div>
    );
}
