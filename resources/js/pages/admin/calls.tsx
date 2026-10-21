import { Head, Link, router, usePoll } from '@inertiajs/react';
import { useState } from 'react';
import CallController from '@/actions/App/Http/Controllers/Admin/CallController';
import { DataTable, formatDateTime, Pagination } from '@/components/data-table';
import type { Paginated } from '@/components/data-table';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/hooks/use-trans';
import { cn } from '@/lib/utils';
import { index } from '@/routes/admin/calls';
import { show as orderShow } from '@/routes/admin/orders';
import { formatPhone } from '@/lib/format';

type CallRow = {
    id: number;
    direction: string;
    kind: 'client' | 'driver' | 'callback' | 'tts';
    caller: string | null;
    callee: string | null;
    status: string;
    operator: string | null;
    client: string | null;
    order_id: number | null;
    attempt: number;
    started_at: string;
    wait_seconds: number | null;
    talk_seconds: number | null;
    has_recording: boolean;
    reason: string | null;
};

type Live = {
    calls: CallRow[];
    operators: {
        id: number;
        name: string;
        status: string;
        registered: boolean;
        since: string | null;
    }[];
    trunks: { id: number; title: string; active: number; max: number }[];
    daemon: boolean;
};

type Stats = {
    total: number;
    answered: number;
    missed: number;
    rejected: number;
    avg_wait: number;
    avg_talk: number;
    auto_total: number;
    auto_delivered: number;
    hourly: { hour: number; total: number; lost: number }[];
    by_operator: {
        operator: string | null;
        calls: number;
        avg_talk: number;
        total_talk: number;
    }[];
};

type Filters = {
    from: string;
    to: string;
    status: string;
    kind: string;
    operator_id: string;
    phone: string;
};

const KIND: Record<CallRow['kind'], string> = {
    client: 'Client',
    driver: 'Driver',
    callback: 'Callback',
    tts: 'Auto call',
};

const STATUS: Record<string, { label: string; className: string }> = {
    waiting: { label: 'In queue', className: 'bg-amber-500' },
    ringing: { label: 'Ringing', className: 'bg-amber-500' },
    answered: { label: 'Talking', className: 'bg-blue-600' },
    completed: { label: 'Completed', className: 'bg-green-600' },
    missed: { label: 'Missed', className: 'bg-red-600' },
    rejected: { label: 'Lines busy', className: 'bg-red-600' },
    blocked: { label: 'Blacklisted', className: 'bg-gray-600' },
    no_answer: { label: 'No answer', className: 'bg-gray-500' },
    busy: { label: 'Busy', className: 'bg-gray-500' },
    declined: { label: 'Declined', className: 'bg-gray-500' },
    failed: { label: 'Failed', className: 'bg-gray-500' },
};

const OPERATOR_STATUS: Record<string, string> = {
    ready: 'bg-green-500',
    busy: 'bg-blue-600',
    paused: 'bg-amber-500',
    offline: 'bg-gray-400',
};

function duration(seconds: number | null): string {
    if (seconds === null || seconds === undefined) {
        return '—';
    }

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

function StatCard({
    label,
    value,
    hint,
}: {
    label: string;
    value: string | number;
    hint?: string;
}) {
    const { t } = useTrans();

    return (
        <div className="rounded-xl border p-3">
            <div className="text-xs text-muted-foreground">{t(label)}</div>
            <div className="text-2xl font-semibold">{value}</div>
            {hint && (
                <div className="text-xs text-muted-foreground">{hint}</div>
            )}
        </div>
    );
}

/**
 * Qo'ng'iroqlar (§5.5): jonli monitoring (5 soniyada yangilanadi), statistika, tarix va yozuvlar.
 */
export default function Calls({
    calls,
    filters,
    stats,
    live,
    operators,
}: {
    calls: Paginated<CallRow>;
    filters: Filters;
    stats: Stats;
    live: Live;
    operators: { id: number; name: string }[];
}) {
    const { t } = useTrans();
    const [local, setLocal] = useState<Filters>(filters);
    usePoll(5000, { only: ['live'] });

    const apply = (next: Filters) => {
        setLocal(next);
        router.get(
            index.url(),
            Object.fromEntries(Object.entries(next).filter(([, v]) => v)),
            {
                preserveState: true,
                replace: true,
            },
        );
    };
    const maxHour = Math.max(1, ...stats.hourly.map((h) => h.total));
    const answeredPct = stats.total
        ? Math.round((stats.answered / stats.total) * 100)
        : 0;

    return (
        <>
            <Head title={t('Calls')} />
            <div className="grid gap-6 p-4">
                <section className="grid gap-3 lg:grid-cols-[1fr_320px]">
                    <div>
                        <div className="mb-2 flex items-center gap-2">
                            <h2 className="text-sm font-semibold">
                                {t('Live')}
                            </h2>
                            <span
                                className={cn(
                                    'size-2 rounded-full',
                                    live.daemon ? 'bg-green-500' : 'bg-red-500',
                                )}
                            />
                            {!live.daemon && (
                                <span className="text-xs text-red-600 dark:text-red-400">
                                    {t(
                                        'Call service (ari:listen) is not running',
                                    )}
                                </span>
                            )}
                            <div className="ml-auto flex gap-2">
                                {live.trunks.map((tr) => (
                                    <Badge
                                        key={tr.id}
                                        variant={
                                            tr.active >= tr.max
                                                ? 'destructive'
                                                : 'outline'
                                        }
                                    >
                                        {tr.title}: {tr.active} / {tr.max}
                                    </Badge>
                                ))}
                            </div>
                        </div>
                        <DataTable
                            rows={live.calls}
                            empty="No active calls"
                            columns={[
                                {
                                    key: 'kind',
                                    title: 'Type',
                                    render: (r) => t(KIND[r.kind]),
                                },
                                {
                                    key: 'who',
                                    title: 'Phone',
                                    render: (r) =>
                                        r.client ??
                                        formatPhone(
                                            r.kind === 'tts' ||
                                                r.kind === 'callback'
                                                ? r.callee
                                                : r.caller,
                                        ),
                                },
                                {
                                    key: 'operator',
                                    title: 'Operator',
                                    render: (r) => r.operator ?? '—',
                                },
                                {
                                    key: 'status',
                                    title: 'Status',
                                    render: (r) => (
                                        <Badge
                                            className={
                                                STATUS[r.status]?.className
                                            }
                                        >
                                            {t(
                                                STATUS[r.status]?.label ??
                                                    r.status,
                                            )}
                                        </Badge>
                                    ),
                                },
                                {
                                    key: 'time',
                                    title: 'Duration',
                                    render: (r) =>
                                        duration(
                                            Math.floor(
                                                (Date.now() -
                                                    new Date(
                                                        r.started_at,
                                                    ).getTime()) /
                                                    1000,
                                            ),
                                        ),
                                },
                            ]}
                        />
                    </div>
                    <div>
                        <h2 className="mb-2 text-sm font-semibold">
                            {t('Operators')}
                        </h2>
                        <div className="grid gap-1 rounded-lg border p-2">
                            {live.operators.length === 0 && (
                                <div className="text-xs text-muted-foreground">
                                    {t('Nothing found')}
                                </div>
                            )}
                            {live.operators.map((o) => (
                                <div
                                    key={o.id}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <span
                                        className={cn(
                                            'size-2 rounded-full',
                                            OPERATOR_STATUS[o.status],
                                        )}
                                    />
                                    <span className="flex-1 truncate">
                                        {o.name}
                                    </span>
                                    {!o.registered &&
                                        o.status !== 'offline' && (
                                            <span className="text-xs text-red-600 dark:text-red-400">
                                                {t('phone offline')}
                                            </span>
                                        )}
                                    <span className="text-xs text-muted-foreground">
                                        {t(
                                            o.status === 'ready'
                                                ? 'Ready'
                                                : o.status === 'busy'
                                                  ? 'Talking'
                                                  : o.status === 'paused'
                                                    ? 'Paused'
                                                    : 'Offline',
                                        )}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                <section>
                    <div className="mb-3 flex flex-wrap items-end justify-between gap-2">
                        <Heading
                            title="Calls"
                            description="Statistics and history for the selected period."
                        />
                        <div className="flex gap-2">
                            <Input
                                type="date"
                                className="w-40"
                                max={local.to || undefined}
                                value={local.from}
                                onChange={(e) =>
                                    apply({ ...local, from: e.target.value })
                                }
                            />
                            <Input
                                type="date"
                                className="w-40"
                                min={local.from || undefined}
                                value={local.to}
                                onChange={(e) =>
                                    apply({ ...local, to: e.target.value })
                                }
                            />
                        </div>
                    </div>
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                        <StatCard label="Incoming calls" value={stats.total} />
                        <StatCard
                            label="Answered"
                            value={stats.answered}
                            hint={`${answeredPct}%`}
                        />
                        <StatCard label="Missed" value={stats.missed} />
                        <StatCard
                            label="Lost: lines busy"
                            value={stats.rejected}
                        />
                        <StatCard
                            label="Avg wait / talk"
                            value={`${duration(stats.avg_wait)} / ${duration(stats.avg_talk)}`}
                        />
                        <StatCard
                            label="Voice notifications"
                            value={`${stats.auto_delivered} / ${stats.auto_total}`}
                        />
                    </div>

                    {stats.hourly.length > 0 && (
                        <div className="mt-4 rounded-xl border p-3">
                            <div className="mb-2 text-xs text-muted-foreground">
                                {t('Load by hour')}
                            </div>
                            <div className="flex h-28 items-end gap-1">
                                {Array.from({ length: 24 }, (_, hour) => {
                                    const h = stats.hourly.find(
                                        (x) => x.hour === hour,
                                    );

                                    return (
                                        <div
                                            key={hour}
                                            className="flex flex-1 flex-col items-center gap-1"
                                            title={`${hour}:00 — ${h?.total ?? 0} (${h?.lost ?? 0})`}
                                        >
                                            <div className="flex w-full flex-1 flex-col justify-end">
                                                <div
                                                    className="w-full rounded-t bg-red-400"
                                                    style={{
                                                        height: `${((h?.lost ?? 0) / maxHour) * 100}%`,
                                                    }}
                                                />
                                                <div
                                                    className="w-full bg-primary"
                                                    style={{
                                                        height: `${(((h?.total ?? 0) - (h?.lost ?? 0)) / maxHour) * 100}%`,
                                                    }}
                                                />
                                            </div>
                                            <span className="text-[10px] text-muted-foreground">
                                                {hour}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {stats.by_operator.length > 0 && (
                        <div className="mt-4 max-w-xl">
                            <DataTable
                                rows={stats.by_operator.map((r, i) => ({
                                    ...r,
                                    id: i,
                                }))}
                                columns={[
                                    {
                                        key: 'operator',
                                        title: 'Operator',
                                        render: (r) => r.operator ?? '—',
                                    },
                                    {
                                        key: 'calls',
                                        title: 'Calls',
                                        render: (r) => r.calls,
                                    },
                                    {
                                        key: 'avg',
                                        title: 'Avg talk',
                                        render: (r) => duration(r.avg_talk),
                                    },
                                    {
                                        key: 'total',
                                        title: 'Total talk',
                                        render: (r) => duration(r.total_talk),
                                    },
                                ]}
                            />
                        </div>
                    )}
                </section>

                <section>
                    <div className="mb-3 flex flex-wrap gap-2">
                        <Input
                            className="w-48"
                            type="search"
                            inputMode="tel"
                            autoComplete="off"
                            maxLength={25}
                            placeholder={t('Phone')}
                            value={local.phone}
                            onChange={(e) =>
                                setLocal({ ...local, phone: e.target.value })
                            }
                            onKeyDown={(e) => e.key === 'Enter' && apply(local)}
                        />
                        <select
                            className="h-9 rounded-md border bg-transparent px-2 text-sm"
                            value={local.kind}
                            onChange={(e) =>
                                apply({ ...local, kind: e.target.value })
                            }
                        >
                            <option value="">{t('All types')}</option>
                            {Object.entries(KIND).map(([v, l]) => (
                                <option key={v} value={v}>
                                    {t(l)}
                                </option>
                            ))}
                        </select>
                        <select
                            className="h-9 rounded-md border bg-transparent px-2 text-sm"
                            value={local.status}
                            onChange={(e) =>
                                apply({ ...local, status: e.target.value })
                            }
                        >
                            <option value="">{t('All statuses')}</option>
                            {Object.entries(STATUS).map(([v, s]) => (
                                <option key={v} value={v}>
                                    {t(s.label)}
                                </option>
                            ))}
                        </select>
                        <select
                            className="h-9 rounded-md border bg-transparent px-2 text-sm"
                            value={local.operator_id}
                            onChange={(e) =>
                                apply({ ...local, operator_id: e.target.value })
                            }
                        >
                            <option value="">{t('All operators')}</option>
                            {operators.map((o) => (
                                <option key={o.id} value={o.id}>
                                    {o.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <DataTable
                        rows={calls.data}
                        columns={[
                            {
                                key: 'time',
                                title: 'Time',
                                render: (r) => formatDateTime(r.started_at),
                            },
                            {
                                key: 'kind',
                                title: 'Type',
                                render: (r) =>
                                    t(KIND[r.kind]) +
                                    (r.kind === 'tts' && r.attempt > 1
                                        ? ` (${r.attempt})`
                                        : ''),
                            },
                            {
                                key: 'phone',
                                title: 'Phone',
                                render: (r) => (
                                    <span className="whitespace-nowrap">
                                        {formatPhone(
                                            r.kind === 'tts' ||
                                                r.kind === 'callback'
                                                ? r.callee
                                                : r.caller,
                                        )}
                                        {r.client && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {r.client}
                                            </span>
                                        )}
                                    </span>
                                ),
                            },
                            {
                                key: 'operator',
                                title: 'Operator',
                                render: (r) => r.operator ?? '—',
                            },
                            {
                                key: 'status',
                                title: 'Status',
                                render: (r) => (
                                    <Badge
                                        className={STATUS[r.status]?.className}
                                    >
                                        {t(STATUS[r.status]?.label ?? r.status)}
                                    </Badge>
                                ),
                            },
                            {
                                key: 'wait',
                                title: 'Wait',
                                render: (r) => duration(r.wait_seconds),
                            },
                            {
                                key: 'talk',
                                title: 'Talk',
                                render: (r) => duration(r.talk_seconds),
                            },
                            {
                                key: 'order',
                                title: 'Order',
                                render: (r) =>
                                    r.order_id ? (
                                        <Link
                                            className="underline"
                                            href={orderShow(r.order_id)}
                                        >
                                            #{r.order_id}
                                        </Link>
                                    ) : (
                                        '—'
                                    ),
                            },
                            {
                                key: 'recording',
                                title: 'Recording',
                                render: (r) =>
                                    r.has_recording ? (
                                        <audio
                                            controls
                                            preload="none"
                                            className="h-8 w-56"
                                            src={CallController.recording.url(
                                                r.id,
                                            )}
                                        />
                                    ) : (
                                        '—'
                                    ),
                            },
                        ]}
                    />
                    <Pagination links={calls.links} />
                </section>
            </div>
        </>
    );
}

Calls.layout = { breadcrumbs: [{ title: 'Calls', href: index() }] };
