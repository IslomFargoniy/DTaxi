import { Head } from '@inertiajs/react';
import {
    DataTable,
    formatDateTime,
    formatMoney,
} from '@/components/data-table';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useTrans } from '@/hooks/use-trans';
import { orderStatusColor, orderStatusLabel } from '@/lib/order-status';
import { cn } from '@/lib/utils';
import { index } from '@/routes/admin/orders';
import type { Order } from '@/types/operator';

type Event = {
    type: string;
    actor: string | null;
    payload: Record<string, unknown> | null;
    created_at: string | null;
};
type Offer = {
    callsign: string;
    distance_m: number | null;
    is_direct: boolean;
    response: string | null;
    offered_at: string;
    responded_at: string | null;
};

export default function OrderShow({
    order,
    events,
    offers,
}: {
    order: Order & {
        price_breakdown: Record<string, number> | null;
        distance_m: number | null;
    };
    events: Event[];
    offers: Offer[];
}) {
    const { t } = useTrans();
    const rows: [string, React.ReactNode][] = [
        ['Client', `${order.client?.phone ?? ''} ${order.client?.name ?? ''}`],
        ['Pickup', order.pickup.text],
        ['Destination', order.dropoff?.text ?? '—'],
        ['Tariff class', order.tariff_class?.name],
        [
            'Driver',
            order.driver
                ? `${order.driver.callsign} · ${order.driver.name}`
                : '—',
        ],
        [
            'Car',
            order.vehicle
                ? `${order.vehicle.color} ${order.vehicle.model} ${order.vehicle.plate_number}`
                : '—',
        ],
        [
            'Distance',
            order.distance_m
                ? `${(order.distance_m / 1000).toFixed(1)} km`
                : '—',
        ],
        ['Estimate', formatMoney(order.price_estimate)],
        ['Final price', formatMoney(order.price_final)],
        ['Comment', order.comment ?? '—'],
    ];

    return (
        <>
            <Head title={`${t('Order')} #${order.id}`} />
            <div className="grid gap-4 p-4 lg:grid-cols-2">
                <div className="flex items-center gap-3 lg:col-span-2">
                    <Heading title={`${t('Order')} #${order.id}`} />
                    <Badge
                        className={cn(
                            'text-white',
                            orderStatusColor[order.status],
                        )}
                    >
                        {t(orderStatusLabel[order.status])}
                    </Badge>
                </div>
                <Card>
                    <CardContent className="pt-6">
                        <dl className="grid grid-cols-[140px_1fr] gap-y-2 text-sm">
                            {rows.map(([label, value]) => (
                                <div key={label} className="contents">
                                    <dt className="text-muted-foreground">
                                        {t(label)}
                                    </dt>
                                    <dd>{value}</dd>
                                </div>
                            ))}
                        </dl>
                        {order.price_breakdown && (
                            <div className="mt-4 flex flex-wrap gap-2 text-xs">
                                {Object.entries(order.price_breakdown)
                                    .filter(([, v]) => v)
                                    .map(([k, v]) => (
                                        <Badge key={k} variant="outline">
                                            {t(k)}: {v.toLocaleString('ru-RU')}
                                        </Badge>
                                    ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>{t('History')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ol className="grid gap-2 text-sm">
                            {events.map((e, i) => (
                                <li
                                    key={i}
                                    className="grid grid-cols-[120px_1fr] gap-2"
                                >
                                    <span className="text-xs text-muted-foreground">
                                        {formatDateTime(e.created_at)}
                                    </span>
                                    <span>
                                        <span className="font-medium">
                                            {t(`event.${e.type}`)}
                                        </span>
                                        {e.actor && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {e.actor}
                                            </span>
                                        )}
                                        {e.payload && (
                                            <span className="block text-xs text-muted-foreground">
                                                {JSON.stringify(e.payload)}
                                            </span>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </CardContent>
                </Card>
                <div className="lg:col-span-2">
                    <h3 className="mb-2 text-sm font-semibold">
                        {t('Offers on air')}
                    </h3>
                    <DataTable
                        rows={offers.map((o, i) => ({ ...o, id: i }))}
                        columns={[
                            {
                                key: 'callsign',
                                title: 'Driver',
                                render: (o) => o.callsign,
                            },
                            {
                                key: 'distance',
                                title: 'Distance',
                                render: (o) =>
                                    o.distance_m ? `${o.distance_m} m` : '—',
                            },
                            {
                                key: 'direct',
                                title: 'Assigned by operator',
                                render: (o) => (o.is_direct ? '✓' : ''),
                            },
                            {
                                key: 'response',
                                title: 'Response',
                                render: (o) =>
                                    o.response ? t(`offer.${o.response}`) : '—',
                            },
                            {
                                key: 'at',
                                title: 'Time',
                                render: (o) => formatDateTime(o.offered_at),
                            },
                        ]}
                    />
                </div>
            </div>
        </>
    );
}

OrderShow.layout = { breadcrumbs: [{ title: 'Orders', href: index() }] };
