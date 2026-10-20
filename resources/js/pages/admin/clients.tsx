import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ClientController from '@/actions/App/Http/Controllers/Admin/ClientController';
import { DataTable, Pagination } from '@/components/data-table';
import type { Paginated } from '@/components/data-table';
import { EditDialog } from '@/components/edit-dialog';
import Heading from '@/components/heading';
import { SearchInput } from '@/components/search-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTrans } from '@/hooks/use-trans';
import { index } from '@/routes/admin/clients';

type Client = {
    id: number;
    phone: string;
    name: string | null;
    note: string | null;
    is_blacklisted: boolean;
    orders_count: number;
    company_id: number | null;
    discount_percent: number | null;
};

type CompanyOption = { id: number; name: string };

function ClientDialog({
    client,
    companies,
    canPrice,
    onClose,
}: {
    client: Client;
    companies: CompanyOption[];
    canPrice: boolean;
    onClose: () => void;
}) {
    const { t } = useTrans();
    const form = useForm({
        name: client.name ?? '',
        note: client.note ?? '',
        is_blacklisted: client.is_blacklisted,
        company_id: client.company_id,
        discount_percent: client.discount_percent,
    });

    return (
        <EditDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={client.phone}
            processing={form.processing}
            onSubmit={() =>
                form.put(ClientController.update.url(client.id), {
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
        >
            <div className="grid gap-1.5">
                <Label htmlFor="name">{t('Name')}</Label>
                <Input
                    id="name"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="note">{t('Note for operators')}</Label>
                <Input
                    id="note"
                    value={form.data.note}
                    onChange={(e) => form.setData('note', e.target.value)}
                />
            </div>
            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={form.data.is_blacklisted}
                    onCheckedChange={(v) =>
                        form.setData('is_blacklisted', v === true)
                    }
                />
                {t('Blacklist')}
            </label>
            {canPrice && (
                <div className="grid grid-cols-2 items-start gap-2">
                    <div className="grid gap-1.5">
                        <Label htmlFor="company_id">{t('Company')}</Label>
                        <select
                            id="company_id"
                            className="h-9 rounded-md border bg-background px-2 text-sm"
                            value={form.data.company_id ?? ''}
                            onChange={(e) =>
                                form.setData(
                                    'company_id',
                                    e.target.value
                                        ? Number(e.target.value)
                                        : null,
                                )
                            }
                        >
                            <option value="">—</option>
                            {companies.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="discount_percent">
                            {t('Discount, %')}
                        </Label>
                        <Input
                            id="discount_percent"
                            type="number"
                            min={0}
                            max={100}
                            value={form.data.discount_percent ?? ''}
                            onChange={(e) =>
                                form.setData(
                                    'discount_percent',
                                    e.target.value === ''
                                        ? null
                                        : Number(e.target.value),
                                )
                            }
                        />
                    </div>
                </div>
            )}
        </EditDialog>
    );
}

export default function Clients({
    clients,
    filters,
    companies,
    canPrice,
}: {
    clients: Paginated<Client>;
    filters: { q: string; blacklisted: boolean };
    companies: CompanyOption[];
    canPrice: boolean;
}) {
    const { t } = useTrans();
    const [editing, setEditing] = useState<Client | null>(null);

    return (
        <>
            <Head title={t('Clients')} />
            <div className="p-4">
                <Heading title="Clients" />
                <div className="mb-3 flex gap-2">
                    <SearchInput
                        value={filters.q}
                        placeholder="Phone or name"
                    />
                    <Button
                        size="sm"
                        variant={filters.blacklisted ? 'default' : 'outline'}
                        onClick={() =>
                            router.get(
                                index.url({
                                    query: filters.blacklisted
                                        ? {}
                                        : { blacklisted: 1 },
                                }),
                                {},
                                { preserveState: true },
                            )
                        }
                    >
                        {t('Blacklist')}
                    </Button>
                </div>
                <DataTable
                    rows={clients.data}
                    onRowClick={setEditing}
                    columns={[
                        {
                            key: 'phone',
                            title: 'Phone',
                            render: (c) => (
                                <span className="font-mono">{c.phone}</span>
                            ),
                        },
                        {
                            key: 'name',
                            title: 'Name',
                            render: (c) => c.name ?? '—',
                        },
                        {
                            key: 'orders',
                            title: 'Orders',
                            className: 'text-right',
                            render: (c) => c.orders_count,
                        },
                        {
                            key: 'flag',
                            title: '',
                            render: (c) => (
                                <span className="flex gap-1">
                                    {c.is_blacklisted && (
                                        <Badge variant="destructive">
                                            {t('Blacklist')}
                                        </Badge>
                                    )}
                                    {c.company_id && (
                                        <Badge variant="secondary">
                                            {companies.find(
                                                (x) => x.id === c.company_id,
                                            )?.name ?? t('Company')}
                                        </Badge>
                                    )}
                                    {c.discount_percent ? (
                                        <Badge variant="outline">
                                            −{c.discount_percent}%
                                        </Badge>
                                    ) : null}
                                </span>
                            ),
                        },
                        {
                            key: 'note',
                            title: 'Note',
                            render: (c) => (
                                <span className="text-xs text-muted-foreground">
                                    {c.note}
                                </span>
                            ),
                        },
                    ]}
                />
                <Pagination links={clients.links} />
            </div>
            {editing && (
                <ClientDialog
                    client={editing}
                    companies={companies}
                    canPrice={canPrice}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

Clients.layout = { breadcrumbs: [{ title: 'Clients', href: index() }] };
