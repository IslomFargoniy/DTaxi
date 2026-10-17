import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/hooks/use-trans';

/**
 * Ro'yxat qidiruvi: yozish to'xtagach URL `q` parametri yangilanadi.
 */
export function SearchInput({
    value,
    placeholder = 'Search',
}: {
    value: string;
    placeholder?: string;
}) {
    const { t } = useTrans();
    const [q, setQ] = useState(value);
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }
        const id = setTimeout(() => {
            const params = new URLSearchParams(window.location.search);
            if (q) {
                params.set('q', q);
            } else {
                params.delete('q');
            }
            params.delete('page');
            router.get(
                `${window.location.pathname}?${params.toString()}`,
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(id);
    }, [q]);

    return (
        <div className="relative max-w-sm flex-1">
            <Search className="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
            <Input
                className="pl-8"
                value={q}
                placeholder={t(placeholder)}
                onChange={(e) => setQ(e.target.value)}
            />
        </div>
    );
}
