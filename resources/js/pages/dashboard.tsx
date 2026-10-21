import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useTrans } from '@/hooks/use-trans';
import { dashboard } from '@/routes';
import { formatMoney } from '@/lib/format';

type Stats = {
    orders_today: number;
    completed_today: number;
    cancelled_today: number;
    active: number;
    revenue_today: number;
    commission_today: number;
    avg_assign_seconds: number;
    drivers_free: number;
    drivers_busy: number;
    drivers_pending: number;
    by_hour: Record<string, number>;
};

function Tile({
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
        <Card>
            <CardHeader className="pb-1">
                <CardTitle className="text-sm font-normal text-muted-foreground">
                    {t(label)}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className="text-2xl font-semibold tabular-nums">
                    {value}
                </div>
                {hint && (
                    <div className="text-xs text-muted-foreground">
                        {t(hint)}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export default function Dashboard({ stats }: { stats: Stats | null }) {
    const { t } = useTrans();

    if (!stats) {
        return <Head title={t('Dashboard')} />;
    }

    const hours = Array.from({ length: 24 }, (_, h) => stats.by_hour[h] ?? 0);
    const max = Math.max(1, ...hours);

    return (
        <>
            <Head title={t('Dashboard')} />
            <div className="grid gap-4 p-4">
                <Heading title="Today" />
                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <Tile label="Orders" value={stats.orders_today} />
                    <Tile label="Completed" value={stats.completed_today} />
                    <Tile label="Cancelled" value={stats.cancelled_today} />
                    <Tile label="Active now" value={stats.active} />
                    <Tile
                        label="Trips total"
                        value={formatMoney(stats.revenue_today)}
                    />
                    <Tile
                        label="Commission"
                        value={formatMoney(stats.commission_today)}
                    />
                    <Tile
                        label="Average time to assign"
                        value={`${Math.floor(stats.avg_assign_seconds / 60)}:${String(stats.avg_assign_seconds % 60).padStart(2, '0')}`}
                    />
                    <Tile
                        label="Drivers free / busy"
                        value={`${stats.drivers_free} / ${stats.drivers_busy}`}
                        hint={
                            stats.drivers_pending
                                ? `${stats.drivers_pending} ${t('awaiting approval')}`
                                : undefined
                        }
                    />
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm">
                            {t('Orders by hour')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex h-40 items-end gap-1">
                            {hours.map((count, hour) => (
                                <div
                                    key={hour}
                                    className="flex flex-1 flex-col items-center gap-1"
                                    title={`${hour}:00 — ${count}`}
                                >
                                    <div
                                        className="w-full rounded-t bg-primary/80"
                                        style={{
                                            height: `${(count / max) * 100}%`,
                                        }}
                                    />
                                    <span className="text-[10px] text-muted-foreground">
                                        {hour}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Dashboard.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] };
