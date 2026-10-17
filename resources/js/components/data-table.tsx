import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTrans } from '@/hooks/use-trans';
import { cn } from '@/lib/utils';

export type Column<T> = {
    key: string;
    title: string;
    className?: string;
    render: (row: T) => ReactNode;
};

/**
 * Oddiy jadval: admin bo'limlarining barcha ro'yxatlari uchun.
 */
export function DataTable<T extends { id: number }>({
    rows,
    columns,
    onRowClick,
    empty = 'Nothing found',
}: {
    rows: T[];
    columns: Column<T>[];
    onRowClick?: (row: T) => void;
    empty?: string;
}) {
    const { t } = useTrans();

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                    <tr>
                        {columns.map((column) => (
                            <th
                                key={column.key}
                                className={cn(
                                    'px-3 py-2 font-medium',
                                    column.className,
                                )}
                            >
                                {t(column.title)}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.length === 0 && (
                        <tr>
                            <td
                                colSpan={columns.length}
                                className="px-3 py-6 text-center text-muted-foreground"
                            >
                                {t(empty)}
                            </td>
                        </tr>
                    )}
                    {rows.map((row) => (
                        <tr
                            key={row.id}
                            className={cn(
                                'border-t',
                                onRowClick &&
                                    'cursor-pointer hover:bg-accent/50',
                            )}
                            onClick={() => onRowClick?.(row)}
                        >
                            {columns.map((column) => (
                                <td
                                    key={column.key}
                                    className={cn(
                                        'px-3 py-2',
                                        column.className,
                                    )}
                                >
                                    {column.render(row)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
};

export function Pagination({ links }: { links: Paginated<unknown>['links'] }) {
    if (links.length <= 3) {
        return null;
    }

    return (
        <nav className="mt-3 flex flex-wrap gap-1">
            {links.map((link, i) => (
                <Link
                    key={i}
                    href={link.url ?? '#'}
                    preserveScroll
                    preserveState
                    className={cn(
                        'rounded border px-2.5 py-1 text-xs',
                        link.active && 'bg-primary text-primary-foreground',
                        !link.url && 'pointer-events-none opacity-40',
                    )}
                    dangerouslySetInnerHTML={{ __html: link.label }}
                />
            ))}
        </nav>
    );
}

export function formatMoney(value: number | null | undefined): string {
    return value === null || value === undefined
        ? '—'
        : `${value.toLocaleString('ru-RU')} so'm`;
}

export function formatDateTime(value: string | null | undefined): string {
    return value
        ? new Date(value).toLocaleString('ru-RU', {
              dateStyle: 'short',
              timeStyle: 'short',
          })
        : '—';
}
