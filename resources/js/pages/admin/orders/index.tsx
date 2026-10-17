import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    DataTable,
    formatDateTime,
    formatMoney,
    Pagination,
} from '@/components/data-table';
import type { Paginated } from '@/components/data-table';
import Heading from '@/components/heading';
import { SearchInput } from '@/components/search-input';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/hooks/use-trans';
import { orderStatusColor, orderStatusLabel } from '@/lib/order-status';
import { cn } from '@/lib/utils';
import { index, show } from '@/routes/admin/orders';
import type { Order, OrderStatus } from '@/types/operator';

type Filters = { from?: string; to?: string; status?: string; q?: string };

export default function OrdersIndex({
    orders,
    filters,
}: {
    orders: Paginated<Order>;
    filters: Filters;
}) {
    const { t } = useTrans();
    const [local, setLocal] = useState<Filters>(filters);

    const apply = (next: Filters) => {
        setLocal(next);
        router.get(
            index.url(),
            Object.fromEntries(Object.entries(next).filter(([, v]) => v)),
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title={t('Orders')} />
            <div className="p-4">
                <Heading title="Orders" />
                <div className="mb-3 flex flex-wrap gap-2">
                    <SearchInput
                        value={filters.q ?? ''}
                        placeholder="Order #, phone or address"
                    />
                    <Input
                        type="date"
                        className="w-40"
                        value={local.from ?? ''}
                        onChange={(e) =>
                            apply({ ...local, from: e.target.value })
                        }
                    />
                    <Input
                        type="date"
                        className="w-40"
                        value={local.to ?? ''}
                        onChange={(e) =>
                            apply({ ...local, to: e.target.value })
                        }
                    />
                    <select
                        className="h-9 rounded-md border bg-transparent px-2 text-sm"
                        value={local.status ?? ''}
                        onChange={(e) =>
                            apply({ ...local, status: e.target.value })
                        }
                    >
                        <option value="">{t('All statuses')}</option>
                        {Object.entries(orderStatusLabel).map(
                            ([value, label]) => (
                                <option key={value} value={value}>
                                    {t(label)}
                                </option>
                            ),
                        )}
                    </select>
                </div>
                <DataTable
                    rows={orders.data}
                    onRowClick={(o) => router.visit(show.url(o.id))}
                    columns={[
                        {
                            key: 'id',
                            title: '#',
                            render: (o) => (
                                <span className="font-mono">{o.id}</span>
                            ),
                        },
                        {
                            key: 'date',
                            title: 'Date',
                            render: (o) => formatDateTime(o.created_at),
                        },
                        {
                            key: 'status',
                            title: 'Status',
                            render: (o) => (
                                <Badge
                                    className={cn(
                                        'text-white',
                                        orderStatusColor[
                                            o.status as OrderStatus
                                        ],
                                    )}
                                >
                                    {t(
                                        orderStatusLabel[
                                            o.status as OrderStatus
                                        ],
                                    )}
                                </Badge>
                            ),
                        },
                        {
                            key: 'client',
                            title: 'Client',
                            render: (o) => o.client?.phone,
                        },
                        {
                            key: 'pickup',
                            title: 'Pickup',
                            render: (o) => (
                                <span className="line-clamp-1">
                                    {o.pickup.text}
                                </span>
                            ),
                        },
                        {
                            key: 'driver',
                            title: 'Driver',
                            render: (o) => o.driver?.callsign ?? '—',
                        },
                        {
                            key: 'price',
                            title: 'Price',
                            className: 'text-right',
                            render: (o) =>
                                formatMoney(o.price_final ?? o.price_estimate),
                        },
                    ]}
                />
                <Pagination links={orders.links} />
            </div>
        </>
    );
}

OrdersIndex.layout = { breadcrumbs: [{ title: 'Orders', href: index() }] };
