import { Head, router } from '@inertiajs/react';
import { DataTable, formatDateTime, Pagination } from '@/components/data-table';
import type { Paginated } from '@/components/data-table';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { useTrans } from '@/hooks/use-trans';
import { index } from '@/routes/admin/audit';

type Log = {
    id: number;
    user: string | null;
    action: 'created' | 'updated' | 'deleted';
    subject_type: string;
    subject_id: string | null;
    subject_label: string | null;
    changes: Record<string, { from: unknown; to: unknown } | string> | null;
    ip: string | null;
    created_at: string;
};

const ACTION: Record<Log['action'], string> = {
    created: 'Created',
    updated: 'Changed',
    deleted: 'Deleted',
};

const MAX_LENGTH = 120;

function show(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const text =
        typeof value === 'string' ||
        typeof value === 'number' ||
        typeof value === 'boolean'
            ? String(value)
            : JSON.stringify(value);

    // Eski yozuvlarda hudud poligoni to'liq saqlangan — jadvalni buzmasin
    return text.length > MAX_LENGTH ? `${text.slice(0, MAX_LENGTH)}…` : text;
}

/**
 * Audit jurnali: panelda kim nimani o'zgartirgan.
 */
export default function Audit({
    logs,
    types,
    filters,
}: {
    logs: Paginated<Log>;
    types: string[];
    filters: { type: string };
}) {
    const { t } = useTrans();

    return (
        <>
            <Head title={t('Audit log')} />
            <div className="p-4">
                <div className="mb-3 flex flex-wrap items-end justify-between gap-2">
                    <Heading
                        title="Audit log"
                        description="Who changed what in the panel: tariffs, settings, integrations, staff, branches, telephony."
                    />
                    <select
                        className="h-9 rounded-md border bg-transparent px-2 text-sm"
                        value={filters.type}
                        onChange={(e) =>
                            router.get(
                                index.url(),
                                e.target.value ? { type: e.target.value } : {},
                                { preserveState: true },
                            )
                        }
                    >
                        <option value="">{t('All')}</option>
                        {types.map((type) => (
                            <option key={type} value={type}>
                                {type}
                            </option>
                        ))}
                    </select>
                </div>
                <DataTable
                    rows={logs.data}
                    columns={[
                        {
                            key: 'time',
                            title: 'Time',
                            render: (r) => formatDateTime(r.created_at),
                        },
                        {
                            key: 'user',
                            title: 'Employee',
                            render: (r) => r.user ?? '—',
                        },
                        {
                            key: 'action',
                            title: 'Action',
                            render: (r) => (
                                <Badge
                                    variant={
                                        r.action === 'deleted'
                                            ? 'destructive'
                                            : r.action === 'created'
                                              ? 'default'
                                              : 'secondary'
                                    }
                                >
                                    {t(ACTION[r.action])}
                                </Badge>
                            ),
                        },
                        {
                            key: 'subject',
                            title: 'Object',
                            render: (r) => (
                                <span>
                                    {r.subject_type} #{r.subject_id}
                                    {r.subject_label && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {r.subject_label}
                                        </span>
                                    )}
                                </span>
                            ),
                        },
                        {
                            key: 'changes',
                            title: 'Changes',
                            render: (r) =>
                                r.changes ? (
                                    <ul className="grid gap-0.5 text-xs break-all">
                                        {Object.entries(r.changes).map(
                                            ([field, change]) => (
                                                <li key={field}>
                                                    <span className="font-medium">
                                                        {field}
                                                    </span>
                                                    :{' '}
                                                    {typeof change === 'string'
                                                        ? t(change)
                                                        : `${show(change.from)} → ${show(change.to)}`}
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                ) : (
                                    '—'
                                ),
                        },
                        { key: 'ip', title: 'IP', render: (r) => r.ip ?? '—' },
                    ]}
                />
                <Pagination links={logs.links} />
            </div>
        </>
    );
}

Audit.layout = { breadcrumbs: [{ title: 'Audit log', href: index() }] };
