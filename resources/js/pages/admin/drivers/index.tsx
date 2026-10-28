import { Head, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import DriverController from '@/actions/App/Http/Controllers/Admin/DriverController';
import { DataTable, Pagination } from '@/components/data-table';
import type { Paginated } from '@/components/data-table';
import Heading from '@/components/heading';
import { SearchInput } from '@/components/search-input';
import { Badge } from '@/components/ui/badge';
import { EditDialog } from '@/components/edit-dialog';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/hooks/use-trans';
import { edit, index } from '@/routes/admin/drivers';
import { formatMoney, formatPhone, formatPlate } from '@/lib/format';
import { DriverFields, useDriverForm, VehicleFields } from './fields';
import type { TariffClassOption } from './fields';

export type DriverRow = {
    id: number;
    callsign: string;
    name: string;
    phone: string | null;
    branch_id: number;
    status: 'offline' | 'free' | 'busy';
    balance: number;
    rating: number;
    is_approved: boolean;
    blocked_until: string | null;
    mock_location_at: string | null;
    last_seen_at: string | null;
    vehicle: {
        brand: string;
        model: string;
        color: string;
        plate_number: string;
        year: number | null;
        tech_passport_no: string | null;
        is_approved: boolean;
        tariff_class_ids: number[];
    } | null;
};

const statusVariant = {
    free: 'default',
    busy: 'destructive',
    offline: 'secondary',
} as const;

export default function DriversIndex({
    drivers,
    filters,
    nextCallsign,
    branches,
    tariffClasses,
}: {
    drivers: Paginated<DriverRow>;
    filters: { q?: string; pending?: boolean };
    nextCallsign: string;
    branches: { id: number; name: string }[];
    tariffClasses: TariffClassOption[];
}) {
    const { t } = useTrans();
    const [adding, setAdding] = useState(false);
    const form = useDriverForm(null, branches, nextCallsign);

    return (
        <>
            <Head title={t('Drivers')} />
            <div className="p-4">
                <div className="mb-4 flex items-center justify-between">
                    <Heading
                        title="Drivers"
                        description="Partners working with their own cars. A driver goes on air only after approval."
                    />
                    <Button onClick={() => setAdding(true)}>
                        <Plus /> {t('Add driver')}
                    </Button>
                </div>
                <div className="mb-3 flex flex-wrap items-center gap-2">
                    <SearchInput
                        value={filters.q ?? ''}
                        placeholder="Callsign, name, phone or plate"
                    />
                    <Button
                        variant={filters.pending ? 'default' : 'outline'}
                        size="sm"
                        onClick={() =>
                            router.get(
                                index.url({
                                    query: filters.pending
                                        ? {}
                                        : { pending: 1 },
                                }),
                                {},
                                { preserveState: true },
                            )
                        }
                    >
                        {t('Awaiting approval')}
                    </Button>
                </div>
                <DataTable
                    rows={drivers.data}
                    onRowClick={(row) => router.visit(edit.url(row.id))}
                    columns={[
                        {
                            key: 'callsign',
                            title: 'Callsign',
                            render: (d) => (
                                <span className="font-mono font-medium">
                                    {d.callsign}
                                </span>
                            ),
                        },
                        { key: 'name', title: 'Name', render: (d) => d.name },
                        {
                            key: 'phone',
                            title: 'Phone',
                            render: (d) => (
                                <span className="whitespace-nowrap">
                                    {formatPhone(d.phone) || '—'}
                                </span>
                            ),
                        },
                        {
                            key: 'vehicle',
                            title: 'Car',
                            render: (d) =>
                                d.vehicle
                                    ? `${d.vehicle.color} ${d.vehicle.model} · ${formatPlate(d.vehicle.plate_number)}`
                                    : '—',
                        },
                        {
                            key: 'status',
                            title: 'Status',
                            render: (d) => (
                                <div className="flex gap-1">
                                    <Badge variant={statusVariant[d.status]}>
                                        {t(
                                            d.status === 'free'
                                                ? 'Free'
                                                : d.status === 'busy'
                                                  ? 'Busy'
                                                  : 'Offline',
                                        )}
                                    </Badge>
                                    {!d.is_approved && (
                                        <Badge variant="outline">
                                            {t('Not approved')}
                                        </Badge>
                                    )}
                                    {d.mock_location_at &&
                                        Date.now() -
                                            new Date(
                                                d.mock_location_at,
                                            ).getTime() <
                                            7 * 86400_000 && (
                                            <Badge
                                                variant="destructive"
                                                title={d.mock_location_at}
                                            >
                                                {t('Fake GPS')}
                                            </Badge>
                                        )}
                                    {d.blocked_until &&
                                        new Date(d.blocked_until) >
                                            new Date() && (
                                            <Badge variant="destructive">
                                                {t('Blocked')}
                                            </Badge>
                                        )}
                                </div>
                            ),
                        },
                        {
                            key: 'balance',
                            title: 'Balance',
                            className: 'text-right',
                            render: (d) => formatMoney(d.balance),
                        },
                        {
                            key: 'rating',
                            title: 'Rating',
                            className: 'text-right',
                            render: (d) => d.rating,
                        },
                    ]}
                />
                <Pagination links={drivers.links} />
            </div>
            <EditDialog
                open={adding}
                onOpenChange={(open) => {
                    setAdding(open);
                    if (!open) {
                        form.reset();
                        form.clearErrors();
                    }
                }}
                title="Add driver"
                className="sm:max-w-2xl"
                processing={form.processing}
                // Saqlangach profil sahifasi ochiladi (hujjatlar, balans)
                onSubmit={() => form.post(DriverController.store.url())}
            >
                <div className="grid items-start gap-3 sm:grid-cols-2">
                    <DriverFields form={form} branches={branches} isNew />
                </div>
                <h3 className="mt-2 text-sm font-medium">{t('Car')}</h3>
                <div className="grid items-start gap-3 sm:grid-cols-2">
                    <VehicleFields form={form} tariffClasses={tariffClasses} />
                </div>
            </EditDialog>
        </>
    );
}

DriversIndex.layout = { breadcrumbs: [{ title: 'Drivers', href: index() }] };
