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
};

function ClientDialog({
    client,
    onClose,
}: {
    client: Client;
    onClose: () => void;
}) {
    const { t } = useTrans();
    const form = useForm({
        name: client.name ?? '',
        note: client.note ?? '',
        is_blacklisted: client.is_blacklisted,
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
        </EditDialog>
    );
}

export default function Clients({
    clients,
    filters,
}: {
    clients: Paginated<Client>;
    filters: { q: string; blacklisted: boolean };
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
                            render: (c) =>
                                c.is_blacklisted ? (
                                    <Badge variant="destructive">
                                        {t('Blacklist')}
                                    </Badge>
                                ) : null,
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
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

Clients.layout = { breadcrumbs: [{ title: 'Clients', href: index() }] };
