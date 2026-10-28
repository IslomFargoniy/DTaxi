import { Head, router, useForm } from '@inertiajs/react';
import ComplaintController from '@/actions/App/Http/Controllers/Admin/ComplaintController';
import OrderHistoryController from '@/actions/App/Http/Controllers/Admin/OrderHistoryController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { DataTable, formatDateTime } from '@/components/data-table';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useTrans } from '@/hooks/use-trans';
import { orderStatusColor, orderStatusLabel } from '@/lib/order-status';
import { cn } from '@/lib/utils';
import { index } from '@/routes/admin/orders';
import type { Order } from '@/types/operator';
import {
    formatMoney,
    formatNumber,
    formatPhone,
    formatPlate,
} from '@/lib/format';

type Event = {
    type: string;
    actor: string | null;
    payload: Record<string, unknown> | null;
    created_at: string | null;
};
type CallRow = {
    id: number;
    direction: string;
    started_at: string;
    duration_s: number | null;
    recording_url: string | null;
};
type ComplaintRow = {
    id: number;
    type: string;
    text: string;
    status: string;
    author: string | null;
    created_at: string;
};
type Offer = {
    callsign: string;
    distance_m: number | null;
    is_direct: boolean;
    response: string | null;
    offered_at: string;
    responded_at: string | null;
};

const km = (meters: number) => `${(meters / 1000).toFixed(1)} km`;

const text = (value: unknown): string =>
    typeof value === 'object' ? JSON.stringify(value) : String(value as string);

// Tahrirlangan maydonlar — operator formasidagi nomlar bilan (joy va matn bitta)
const EDITED_FIELDS: Record<string, string> = {
    pickup_text: 'Pickup',
    pickup_location: 'Pickup',
    dropoff_text: 'Destination',
    dropoff_location: 'Destination',
    stops: 'Stop',
    tariff_class_id: 'Tariff class',
    price_bonus: 'Extra to the price (so‘m)',
    comment: 'Comment',
};

/**
 * Hodisa ma'lumotini (payload) "Nomi: qiymat" qatorlariga aylantiradi; noma'lum kalitlar ham yo'qolmaydi.
 */
function eventDetails(
    payload: Record<string, unknown> | null,
    t: (key: string) => string,
): [string, string][] {
    if (!payload) {
        return [];
    }

    const rows: [string, string][] = [];
    for (const [key, value] of Object.entries(payload)) {
        if (
            value === null ||
            value === undefined ||
            value === '' ||
            value === false
        ) {
            continue;
        }
        switch (key) {
            case 'radius_m':
                rows.push([t('Search radius'), km(Number(value))]);
                break;
            case 'driver_id':
                rows.push([t('Driver'), text(value)]);
                break;
            case 'reason':
                rows.push([t('Reason'), text(value)]);
                break;
            case 'at':
                rows.push([t('Time'), formatDateTime(text(value))]);
                break;
            case 'price':
                rows.push([t('Price'), formatMoney(Number(value))]);
                break;
            case 'commission_base':
                rows.push([t('Commission base'), formatMoney(Number(value))]);
                break;
            case 'reported_m':
                rows.push([t('Distance (driver)'), km(Number(value))]);
                break;
            case 'track_m':
                rows.push([t('Distance (GPS track)'), km(Number(value))]);
                break;
            case 'distance_capped':
                rows.push([
                    t('Distance capped, driver reported'),
                    km(Number(value)),
                ]);
                break;
            case 'suspicious':
                rows.push([t('Check'), t('Distance looks suspicious')]);
                break;
            case 'rating':
                rows.push([t('Rating'), `${text(value)} ★`]);
                break;
            case 'message':
                rows.push([t('Message'), text(value)]);
                break;
            case 'status':
                rows.push([t('Call status'), text(value)]);
                break;
            case 'fields':
                if (Array.isArray(value)) {
                    const labels = [
                        ...new Set(
                            value
                                .map((field) => EDITED_FIELDS[text(field)])
                                .filter(Boolean),
                        ),
                    ].map((label) => t(label));
                    if (labels.length > 0) {
                        rows.push([t('Changed'), labels.join(', ')]);
                    }
                }
                break;
            default:
                rows.push([key, text(value)]);
        }
    }

    return rows;
}

export default function OrderShow({
    order,
    events,
    offers,
    calls,
    complaints,
    clientRating,
}: {
    order: Order & {
        price_breakdown: Record<string, number> | null;
        distance_m: number | null;
    };
    events: Event[];
    offers: Offer[];
    calls: CallRow[];
    complaints: ComplaintRow[];
    clientRating: number | null;
}) {
    const { t } = useTrans();
    const complaint = useForm({
        order_id: order.id,
        type: 'complaint',
        text: '',
    });
    const rows: [string, React.ReactNode][] = [
        [
            'Client',
            `${formatPhone(order.client?.phone)} ${order.client?.name ?? ''}`.trim() ||
                '—',
        ],
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
                ? `${order.vehicle.color} ${order.vehicle.model} ${formatPlate(order.vehicle.plate_number)}`
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
                        <dl className="grid grid-cols-[140px_minmax(0,1fr)] gap-y-2 text-sm">
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
                                            {t(k)}: {formatNumber(v)}
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
                                    className="grid grid-cols-[120px_minmax(0,1fr)] gap-2"
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
                                        {eventDetails(e.payload, t).map(
                                            ([label, value]) => (
                                                <span
                                                    key={label}
                                                    className="block text-xs text-muted-foreground"
                                                >
                                                    {label}: {value}
                                                </span>
                                            ),
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>{t('Client feedback')}</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 text-sm">
                        {order.status === 'completed' && (
                            <div className="flex items-center gap-1">
                                <span className="mr-2 text-muted-foreground">
                                    {t('Rating')}:
                                </span>
                                {[1, 2, 3, 4, 5].map((n) => (
                                    <button
                                        key={n}
                                        type="button"
                                        className={cn(
                                            'text-xl',
                                            clientRating !== null &&
                                                n <= clientRating
                                                ? 'text-amber-500 dark:text-amber-400'
                                                : 'text-muted-foreground/40',
                                        )}
                                        onClick={() =>
                                            router.post(
                                                OrderHistoryController.rate.url(
                                                    order.id,
                                                ),
                                                { rating: n },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        ★
                                    </button>
                                ))}
                            </div>
                        )}
                        {complaints.map((c) => (
                            <div key={c.id} className="rounded-md border p-2">
                                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                    <Badge variant="outline">
                                        {t(`complaint.${c.type}`)}
                                    </Badge>
                                    {formatDateTime(c.created_at)} · {c.author}
                                    {c.status === 'resolved' && (
                                        <Badge variant="secondary">
                                            {t('Resolved')}
                                        </Badge>
                                    )}
                                </div>
                                <p className="mt-1">{c.text}</p>
                            </div>
                        ))}
                        <form
                            className="flex flex-wrap gap-1.5"
                            onSubmit={(e) => {
                                e.preventDefault();
                                complaint.post(
                                    ComplaintController.store.url(),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            complaint.reset('text'),
                                    },
                                );
                            }}
                        >
                            <select
                                className="h-9 rounded-md border bg-background px-2 text-sm"
                                value={complaint.data.type}
                                onChange={(e) =>
                                    complaint.setData('type', e.target.value)
                                }
                            >
                                {['complaint', 'lost_item', 'praise'].map(
                                    (type) => (
                                        <option key={type} value={type}>
                                            {t(`complaint.${type}`)}
                                        </option>
                                    ),
                                )}
                            </select>
                            <Input
                                className="min-w-48 flex-1"
                                placeholder={t('What did the client say?')}
                                value={complaint.data.text}
                                onChange={(e) =>
                                    complaint.setData('text', e.target.value)
                                }
                            />
                            <Button
                                disabled={
                                    complaint.processing ||
                                    complaint.data.text.trim() === ''
                                }
                            >
                                {t('Add')}
                            </Button>
                        </form>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>{t('Calls')}</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-2 text-sm">
                        {calls.length === 0 && (
                            <p className="text-muted-foreground">—</p>
                        )}
                        {calls.map((call) => (
                            <div key={call.id} className="grid gap-1">
                                <span className="text-xs text-muted-foreground">
                                    {formatDateTime(call.started_at)} ·{' '}
                                    {t(call.direction)}
                                    {call.duration_s !== null &&
                                        ` · ${Math.floor(call.duration_s / 60)}:${String(call.duration_s % 60).padStart(2, '0')}`}
                                </span>
                                {call.recording_url && (
                                    <audio
                                        controls
                                        preload="none"
                                        src={call.recording_url}
                                        className="h-8 w-full"
                                    />
                                )}
                            </div>
                        ))}
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
