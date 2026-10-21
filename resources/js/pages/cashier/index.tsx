import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import CashierController from '@/actions/App/Http/Controllers/Cashier/CashierController';
import {
    DataTable,
    formatDateTime,
    formatMoney,
} from '@/components/data-table';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/hooks/use-trans';
import { getJson, isAbort } from '@/lib/http';
import { index } from '@/routes/cashier';
import { search } from '@/routes/cashier/drivers';

type FoundDriver = {
    id: number;
    callsign: string;
    name: string;
    phone: string | null;
    balance: number;
    vehicle: string | null;
};
type Shift = {
    id: number;
    opened_at: string;
    total_cash: number;
    topups: {
        id: number;
        callsign: string;
        name: string;
        amount: number;
        created_at: string | null;
    }[];
};
type ClosedShift = {
    id: number;
    opened_at: string;
    closed_at: string | null;
    total_cash: number;
    confirmed: boolean;
};

// Har to'ldirishga yangi id: ikki marta bosilsa server pulni bir marta qo'shadi.
// crypto.randomUUID faqat https/localhost'da bor — http'da oddiy generator.
function newRequestId(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;

        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

/**
 * Kassir paneli (qaror #25): haydovchini topish, naqd to'ldirish, smena.
 */
export default function CashierIndex({
    shift,
    history,
}: {
    shift: Shift | null;
    history: ClosedShift[];
}) {
    const { t } = useTrans();
    const [q, setQ] = useState('');
    const [found, setFound] = useState<FoundDriver[]>([]);
    const [driver, setDriver] = useState<FoundDriver | null>(null);
    const form = useForm({
        driver_id: 0,
        amount: '',
        request_id: newRequestId(),
    });

    useEffect(() => {
        if (q.trim().length < 2) {
            setFound([]);

            return;
        }
        // Eski qidiruv javobi yangisidan keyin kelsa — ro'yxatni buzmasin
        const controller = new AbortController();
        const id = setTimeout(() => {
            getJson<FoundDriver[]>(search.url({ query: { q } }), {
                signal: controller.signal,
            })
                .then(setFound)
                .catch((e) => !isAbort(e) && setFound([]));
        }, 250);

        return () => {
            clearTimeout(id);
            controller.abort();
        };
    }, [q]);

    return (
        <>
            <Head title={t('Cash desk')} />
            <div className="grid gap-4 p-4 lg:grid-cols-2">
                <div className="flex items-center gap-3 lg:col-span-2">
                    <Heading
                        title="Cash desk"
                        description="Cash top-ups of driver balances."
                    />
                    <div className="ml-auto">
                        {shift ? (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    window.confirm(t('Close the shift?')) &&
                                    router.post(CashierController.close.url())
                                }
                            >
                                {t('Close shift')}
                            </Button>
                        ) : (
                            <Button
                                onClick={() =>
                                    router.post(CashierController.open.url())
                                }
                            >
                                {t('Open shift')}
                            </Button>
                        )}
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('Top up balance')}</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3">
                        <Input
                            autoFocus
                            placeholder={t('Callsign, phone or plate')}
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                        />
                        {found.length > 0 && !driver && (
                            <ul className="grid gap-1">
                                {found.map((d) => (
                                    <li key={d.id}>
                                        <button
                                            type="button"
                                            className="w-full rounded border px-3 py-2 text-left text-sm hover:bg-accent"
                                            onClick={() => {
                                                setDriver(d);
                                                form.setData('driver_id', d.id);
                                            }}
                                        >
                                            <span className="font-mono font-medium">
                                                {d.callsign}
                                            </span>{' '}
                                            · {d.name} · {d.vehicle}
                                            <span className="float-right text-muted-foreground">
                                                {formatMoney(d.balance)}
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {driver && (
                            <form
                                className="grid gap-3 rounded-md border p-3"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    form.post(CashierController.topUp.url(), {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setDriver(null);
                                            setQ('');
                                            form.reset();
                                            form.setData(
                                                'request_id',
                                                newRequestId(),
                                            );
                                        },
                                    });
                                }}
                            >
                                <div className="text-sm">
                                    <span className="font-mono font-semibold">
                                        {driver.callsign}
                                    </span>{' '}
                                    · {driver.name}
                                    <div className="text-muted-foreground">
                                        {t('Balance')}:{' '}
                                        {formatMoney(driver.balance)}
                                    </div>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {[20000, 50000, 100000, 200000].map(
                                        (amount) => (
                                            <Button
                                                key={amount}
                                                type="button"
                                                size="sm"
                                                variant="secondary"
                                                onClick={() =>
                                                    form.setData(
                                                        'amount',
                                                        String(amount),
                                                    )
                                                }
                                            >
                                                {amount.toLocaleString('ru-RU')}
                                            </Button>
                                        ),
                                    )}
                                </div>
                                <Input
                                    type="number"
                                    placeholder={t('Amount')}
                                    value={form.data.amount}
                                    onChange={(e) =>
                                        form.setData('amount', e.target.value)
                                    }
                                />
                                <InputError message={form.errors.amount} />
                                <div className="flex gap-2">
                                    <Button
                                        disabled={
                                            form.processing ||
                                            !shift ||
                                            !form.data.amount
                                        }
                                    >
                                        {t('Top up')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setDriver(null)}
                                    >
                                        {t('Cancel')}
                                    </Button>
                                </div>
                                {!shift && (
                                    <p className="text-xs text-destructive">
                                        {t('Open a cash shift first.')}
                                    </p>
                                )}
                            </form>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>
                            {shift
                                ? `${t('Current shift')}: ${formatMoney(shift.total_cash)}`
                                : t('No open shift')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        {shift && (
                            <DataTable
                                rows={shift.topups}
                                columns={[
                                    {
                                        key: 'time',
                                        title: 'Time',
                                        render: (r) =>
                                            formatDateTime(r.created_at),
                                    },
                                    {
                                        key: 'driver',
                                        title: 'Driver',
                                        render: (r) =>
                                            `${r.callsign} · ${r.name}`,
                                    },
                                    {
                                        key: 'amount',
                                        title: 'Amount',
                                        className: 'text-right',
                                        render: (r) => formatMoney(r.amount),
                                    },
                                ]}
                            />
                        )}
                        <div>
                            <h3 className="mb-2 text-sm font-semibold">
                                {t('Previous shifts')}
                            </h3>
                            <DataTable
                                rows={history}
                                columns={[
                                    {
                                        key: 'opened',
                                        title: 'Opened',
                                        render: (s) =>
                                            formatDateTime(s.opened_at),
                                    },
                                    {
                                        key: 'closed',
                                        title: 'Closed',
                                        render: (s) =>
                                            formatDateTime(s.closed_at),
                                    },
                                    {
                                        key: 'total',
                                        title: 'Total',
                                        className: 'text-right',
                                        render: (s) =>
                                            formatMoney(s.total_cash),
                                    },
                                    {
                                        key: 'confirmed',
                                        title: '',
                                        render: (s) =>
                                            s.confirmed ? (
                                                <Badge>{t('Confirmed')}</Badge>
                                            ) : null,
                                    },
                                ]}
                            />
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

CashierIndex.layout = { breadcrumbs: [{ title: 'Cash desk', href: index() }] };
