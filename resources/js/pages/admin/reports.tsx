import { Head, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import ReportController from '@/actions/App/Http/Controllers/Admin/ReportController';
import { DataTable, formatMoney } from '@/components/data-table';
import Heading from '@/components/heading';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/hooks/use-trans';
import { index } from '@/routes/admin/reports';

type Daily = {
    day: string;
    orders: number;
    completed: number;
    cancelled_client: number;
    cancelled_operator: number;
    no_driver: number;
    revenue: number;
    commission: number;
    avg_fare: number;
    avg_assign_s: number;
};
type DriverRow = {
    callsign: string;
    name: string;
    trips: number;
    revenue: number;
    commission: number;
    km: number;
    cancels: number;
    rating: number;
    acceptance: number | null;
    accept_seconds: number | null;
    client_rating: number | null;
};
type OperatorRow = {
    name: string;
    orders: number;
    completed: number;
    calls: number;
    talk_seconds: number;
    avg_wait: number | null;
};
type Filters = { from: string; to: string; branch_id: string };

type Props = {
    filters: Filters;
    branches: { id: number; name: string }[];
    daily: Daily[];
    cancellations: { reason: string; total: number }[];
    drivers: DriverRow[];
    operators: OperatorRow[];
    topups: { source: string; count: number; total: number }[];
    sources: { label: string; total: number }[];
    channels: {
        days: { day: string; peak: number; lost: number }[];
        capacity: number;
        recommendation: 'lost' | 'full' | null;
    };
};

const SOURCE: Record<string, string> = {
    click: 'Click',
    payme: 'Payme',
    cash: 'Cash desk',
    system: 'System',
};

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    const { t } = useTrans();

    return (
        <section className="grid gap-2">
            <h2 className="text-sm font-semibold">{t(title)}</h2>
            {children}
        </section>
    );
}

function Stat({ label, value }: { label: string; value: string | number }) {
    const { t } = useTrans();

    return (
        <div className="rounded-xl border p-3">
            <div className="text-xs text-muted-foreground">{t(label)}</div>
            <div className="text-xl font-semibold">{value}</div>
        </div>
    );
}

/**
 * Kengaytirilgan hisobotlar: davr va filial bo'yicha, CSV eksport bilan.
 */
export default function Reports({
    filters,
    branches,
    daily,
    cancellations,
    drivers,
    operators,
    topups,
    sources,
    channels,
}: Props) {
    const { t } = useTrans();
    const [local, setLocal] = useState<Filters>(filters);
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
    const query = Object.fromEntries(
        Object.entries(filters).filter(([, v]) => v),
    );
    const sum = (key: keyof Daily) =>
        daily.reduce((acc, d) => acc + (Number(d[key]) || 0), 0);
    const totalOrders = sum('orders');
    const completed = sum('completed');
    const maxOrders = Math.max(1, ...daily.map((d) => d.orders));

    return (
        <>
            <Head title={t('Reports')} />
            <div className="grid gap-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-2">
                    <Heading
                        title="Reports"
                        description="Orders, revenue, drivers, operators and phone lines for the period."
                    />
                    <div className="flex flex-wrap gap-2">
                        {branches.length > 1 && (
                            <select
                                className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                value={local.branch_id}
                                onChange={(e) =>
                                    apply({
                                        ...local,
                                        branch_id: e.target.value,
                                    })
                                }
                            >
                                <option value="">{t('All branches')}</option>
                                {branches.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.name}
                                    </option>
                                ))}
                            </select>
                        )}
                        <Input
                            type="date"
                            className="w-40"
                            value={local.from}
                            onChange={(e) =>
                                apply({ ...local, from: e.target.value })
                            }
                        />
                        <Input
                            type="date"
                            className="w-40"
                            value={local.to}
                            onChange={(e) =>
                                apply({ ...local, to: e.target.value })
                            }
                        />
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <Stat label="Orders" value={totalOrders} />
                    <Stat
                        label="Completed"
                        value={`${completed} (${totalOrders ? Math.round((completed / totalOrders) * 100) : 0}%)`}
                    />
                    <Stat label="No driver found" value={sum('no_driver')} />
                    <Stat label="Revenue" value={formatMoney(sum('revenue'))} />
                    <Stat
                        label="Commission"
                        value={formatMoney(sum('commission'))}
                    />
                    <Stat
                        label="Average fare"
                        value={formatMoney(
                            completed
                                ? Math.round(sum('revenue') / completed)
                                : 0,
                        )}
                    />
                </div>

                <Section title="By day">
                    <div className="flex h-24 items-end gap-1 rounded-xl border p-3">
                        {daily.map((d) => (
                            <div
                                key={d.day}
                                className="flex flex-1 flex-col justify-end"
                                title={`${d.day}: ${d.orders} / ${d.completed}`}
                            >
                                <div
                                    className="w-full rounded-t bg-muted"
                                    style={{
                                        height: `${((d.orders - d.completed) / maxOrders) * 100}%`,
                                    }}
                                />
                                <div
                                    className="w-full bg-primary"
                                    style={{
                                        height: `${(d.completed / maxOrders) * 100}%`,
                                    }}
                                />
                            </div>
                        ))}
                    </div>
                    <DataTable
                        rows={daily.map((d, i) => ({ ...d, id: i }))}
                        columns={[
                            { key: 'day', title: 'Date', render: (r) => r.day },
                            {
                                key: 'orders',
                                title: 'Orders',
                                render: (r) => r.orders,
                            },
                            {
                                key: 'completed',
                                title: 'Completed',
                                render: (r) => r.completed,
                            },
                            {
                                key: 'cancelled',
                                title: 'Cancelled',
                                render: (r) =>
                                    r.cancelled_client + r.cancelled_operator,
                            },
                            {
                                key: 'no_driver',
                                title: 'No driver found',
                                render: (r) => r.no_driver,
                            },
                            {
                                key: 'revenue',
                                title: 'Revenue',
                                render: (r) => formatMoney(r.revenue),
                            },
                            {
                                key: 'commission',
                                title: 'Commission',
                                render: (r) => formatMoney(r.commission),
                            },
                            {
                                key: 'assign',
                                title: 'Avg. assign time, s',
                                render: (r) => r.avg_assign_s || '—',
                            },
                        ]}
                    />
                    <div>
                        <Button asChild variant="outline" size="sm">
                            <a
                                href={ReportController.export.url('daily', {
                                    query,
                                })}
                            >
                                <Download /> CSV
                            </a>
                        </Button>
                    </div>
                </Section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Section title="Balance top-ups">
                        <DataTable
                            rows={topups.map((r, i) => ({ ...r, id: i }))}
                            columns={[
                                {
                                    key: 'source',
                                    title: 'Source',
                                    render: (r) =>
                                        t(SOURCE[r.source] ?? r.source),
                                },
                                {
                                    key: 'count',
                                    title: 'Payments',
                                    render: (r) => r.count,
                                },
                                {
                                    key: 'total',
                                    title: 'Amount',
                                    render: (r) => formatMoney(r.total),
                                },
                            ]}
                        />
                    </Section>
                    <Section title="Cancellation reasons">
                        <DataTable
                            rows={cancellations.map((r, i) => ({
                                ...r,
                                id: i,
                            }))}
                            columns={[
                                {
                                    key: 'reason',
                                    title: 'Reason',
                                    render: (r) => r.reason,
                                },
                                {
                                    key: 'total',
                                    title: 'Orders',
                                    render: (r) => r.total,
                                },
                            ]}
                        />
                    </Section>
                </div>

                <Section title="Drivers">
                    <DataTable
                        rows={drivers.map((r, i) => ({ ...r, id: i }))}
                        columns={[
                            {
                                key: 'callsign',
                                title: 'Callsign',
                                render: (r) => r.callsign,
                            },
                            {
                                key: 'name',
                                title: 'Full name',
                                render: (r) => r.name,
                            },
                            {
                                key: 'trips',
                                title: 'Trips',
                                render: (r) => r.trips,
                            },
                            { key: 'km', title: 'km', render: (r) => r.km },
                            {
                                key: 'revenue',
                                title: 'Revenue',
                                render: (r) => formatMoney(r.revenue),
                            },
                            {
                                key: 'commission',
                                title: 'Commission',
                                render: (r) => formatMoney(r.commission),
                            },
                            {
                                key: 'cancels',
                                title: 'Cancels',
                                render: (r) => r.cancels,
                            },
                            {
                                key: 'rating',
                                title: 'Rating',
                                render: (r) => r.rating,
                            },
                            {
                                key: 'acceptance',
                                title: 'Took offers, %',
                                render: (r) =>
                                    r.acceptance === null
                                        ? '—'
                                        : `${r.acceptance}%${r.accept_seconds !== null ? ` · ${r.accept_seconds} s` : ''}`,
                            },
                            {
                                key: 'client_rating',
                                title: 'Client rating',
                                render: (r) =>
                                    r.client_rating === null
                                        ? '—'
                                        : `★ ${r.client_rating}`,
                            },
                        ]}
                    />
                    <div>
                        <Button asChild variant="outline" size="sm">
                            <a
                                href={ReportController.export.url('drivers', {
                                    query,
                                })}
                            >
                                <Download /> CSV
                            </a>
                        </Button>
                    </div>
                </Section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Section title="Operators">
                        <DataTable
                            rows={operators.map((r, i) => ({ ...r, id: i }))}
                            columns={[
                                {
                                    key: 'name',
                                    title: 'Operator',
                                    render: (r) => r.name,
                                },
                                {
                                    key: 'orders',
                                    title: 'Orders',
                                    render: (r) => r.orders,
                                },
                                {
                                    key: 'completed',
                                    title: 'Completed',
                                    render: (r) => r.completed,
                                },
                                {
                                    key: 'calls',
                                    title: 'Calls',
                                    render: (r) => r.calls,
                                },
                                {
                                    key: 'talk',
                                    title: 'Talk',
                                    render: (r) =>
                                        `${Math.round(r.talk_seconds / 60)} ${t('min')}`,
                                },
                                {
                                    key: 'wait',
                                    title: 'Avg. answer time, s',
                                    render: (r) => r.avg_wait ?? '—',
                                },
                            ]}
                        />
                    </Section>
                    <Section title="Order sources">
                        <DataTable
                            rows={sources.map((r, i) => ({ ...r, id: i }))}
                            columns={[
                                {
                                    key: 'label',
                                    title: 'Source',
                                    render: (r) =>
                                        r.label
                                            .replace(/^phone/, t('Operator'))
                                            .replace(/^ivr/, t('IVR'))
                                            .replace(
                                                '(pre-order)',
                                                `(${t('Pre-order')})`,
                                            ),
                                },
                                {
                                    key: 'total',
                                    title: 'Orders',
                                    render: (r) => r.total,
                                },
                            ]}
                        />
                    </Section>
                </div>

                <Section title="Phone lines">
                    {channels.recommendation && (
                        <Alert variant="destructive">
                            <AlertDescription>
                                {t(
                                    channels.recommendation === 'lost'
                                        ? 'Calls were lost because all lines were busy. Get more channels from the SIP provider.'
                                        : 'All lines were busy at peak time. Consider getting more channels from the SIP provider.',
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
                    <DataTable
                        rows={channels.days.map((r, i) => ({ ...r, id: i }))}
                        empty="No calls"
                        columns={[
                            { key: 'day', title: 'Date', render: (r) => r.day },
                            {
                                key: 'peak',
                                title: 'Peak simultaneous calls',
                                render: (r) =>
                                    `${r.peak} / ${channels.capacity}`,
                            },
                            {
                                key: 'lost',
                                title: 'Lost: lines busy',
                                render: (r) => r.lost,
                            },
                        ]}
                    />
                </Section>
            </div>
        </>
    );
}

Reports.layout = { breadcrumbs: [{ title: 'Reports', href: index() }] };
