import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import ComplaintController from '@/actions/App/Http/Controllers/Admin/ComplaintController';
import { DataTable, formatDateTime, Pagination } from '@/components/data-table';
import type { Paginated } from '@/components/data-table';
import { EditDialog } from '@/components/edit-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTrans } from '@/hooks/use-trans';
import { index } from '@/routes/admin/complaints';
import { show as showOrder } from '@/routes/admin/orders';

type Complaint = {
    id: number;
    order_id: number | null;
    type: string;
    text: string;
    status: string;
    resolution: string | null;
    client: string | null;
    driver: string | null;
    author: string | null;
    created_at: string;
};

const TYPES = ['complaint', 'lost_item', 'praise'];

function ResolveDialog({
    complaint,
    onClose,
}: {
    complaint: Complaint;
    onClose: () => void;
}) {
    const { t } = useTrans();
    const form = useForm({
        status: complaint.status === 'open' ? 'resolved' : 'open',
        resolution: complaint.resolution ?? '',
    });

    return (
        <EditDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={t(`complaint.${complaint.type}`)}
            processing={form.processing}
            onSubmit={() =>
                form.put(ComplaintController.update.url(complaint.id), {
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
        >
            <p className="text-sm">{complaint.text}</p>
            <div className="grid gap-1.5">
                <Label htmlFor="resolution">{t('What was done')}</Label>
                <Input
                    id="resolution"
                    maxLength={2000}
                    value={form.data.resolution}
                    onChange={(e) => form.setData('resolution', e.target.value)}
                />
                <InputError message={form.errors.resolution} />
            </div>
            <div className="flex gap-1.5">
                {(['open', 'resolved'] as const).map((status) => (
                    <Button
                        key={status}
                        type="button"
                        size="sm"
                        variant={
                            form.data.status === status ? 'default' : 'outline'
                        }
                        onClick={() => form.setData('status', status)}
                    >
                        {t(status === 'open' ? 'Open' : 'Resolved')}
                    </Button>
                ))}
            </div>
        </EditDialog>
    );
}

function NewDialog({ onClose }: { onClose: () => void }) {
    const { t } = useTrans();
    const form = useForm({ order_id: '', type: 'complaint', text: '' });

    return (
        <EditDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title="New record"
            processing={form.processing}
            onSubmit={() => {
                form.transform((data) => ({
                    ...data,
                    order_id: data.order_id ? Number(data.order_id) : null,
                }));
                form.post(ComplaintController.store.url(), {
                    preserveScroll: true,
                    onSuccess: onClose,
                });
            }}
        >
            <div className="flex flex-wrap gap-1.5">
                {TYPES.map((type) => (
                    <Button
                        key={type}
                        type="button"
                        size="sm"
                        variant={
                            form.data.type === type ? 'default' : 'outline'
                        }
                        onClick={() => form.setData('type', type)}
                    >
                        {t(`complaint.${type}`)}
                    </Button>
                ))}
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="order_id">{t('Order number (if known)')}</Label>
                <Input
                    id="order_id"
                    inputMode="numeric"
                    autoComplete="off"
                    maxLength={10}
                    value={form.data.order_id}
                    onChange={(e) =>
                        form.setData(
                            'order_id',
                            e.target.value.replace(/\D/g, ''),
                        )
                    }
                />
                <InputError message={form.errors.order_id} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="text">{t('What did the client say?')}</Label>
                <Input
                    id="text"
                    maxLength={2000}
                    value={form.data.text}
                    onChange={(e) => form.setData('text', e.target.value)}
                />
                <InputError message={form.errors.text} />
            </div>
        </EditDialog>
    );
}

export default function Complaints({
    complaints,
    filters,
}: {
    complaints: Paginated<Complaint>;
    filters: { status: string; type: string };
}) {
    const { t } = useTrans();
    const [editing, setEditing] = useState<Complaint | null>(null);
    const [creating, setCreating] = useState(false);

    const filter = (patch: Partial<typeof filters>) =>
        router.get(
            index.url({ query: { ...filters, ...patch } }),
            {},
            { preserveState: true },
        );

    return (
        <>
            <Head title={t('Complaints')} />
            <div className="p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading
                        title="Complaints"
                        description="Complaints, lost items and thanks from clients"
                    />
                    <Button onClick={() => setCreating(true)}>
                        <Plus /> {t('Add')}
                    </Button>
                </div>
                <div className="mb-3 flex flex-wrap gap-1.5">
                    {['open', 'resolved', 'all'].map((status) => (
                        <Button
                            key={status}
                            size="sm"
                            variant={
                                filters.status === status
                                    ? 'default'
                                    : 'outline'
                            }
                            onClick={() => filter({ status })}
                        >
                            {t(
                                status === 'open'
                                    ? 'Open'
                                    : status === 'resolved'
                                      ? 'Resolved'
                                      : 'All',
                            )}
                        </Button>
                    ))}
                    <span className="mx-1" />
                    {['', ...TYPES].map((type) => (
                        <Button
                            key={type || 'any'}
                            size="sm"
                            variant={
                                filters.type === type ? 'secondary' : 'ghost'
                            }
                            onClick={() => filter({ type })}
                        >
                            {type ? t(`complaint.${type}`) : t('All types')}
                        </Button>
                    ))}
                </div>
                <DataTable
                    rows={complaints.data}
                    onRowClick={setEditing}
                    columns={[
                        {
                            key: 'date',
                            title: 'Date',
                            render: (c) => formatDateTime(c.created_at),
                        },
                        {
                            key: 'type',
                            title: 'Type',
                            render: (c) => (
                                <Badge
                                    variant={
                                        c.type === 'complaint'
                                            ? 'destructive'
                                            : 'outline'
                                    }
                                >
                                    {t(`complaint.${c.type}`)}
                                </Badge>
                            ),
                        },
                        {
                            key: 'order',
                            title: 'Order',
                            render: (c) =>
                                c.order_id ? (
                                    <Link
                                        href={showOrder.url(c.order_id)}
                                        className="text-primary hover:underline"
                                        onClick={(e) => e.stopPropagation()}
                                    >
                                        #{c.order_id}
                                    </Link>
                                ) : (
                                    '—'
                                ),
                        },
                        {
                            key: 'text',
                            title: 'Text',
                            render: (c) => (
                                <span>
                                    {c.text}
                                    {c.resolution && (
                                        <span className="block text-xs text-muted-foreground">
                                            ✓ {c.resolution}
                                        </span>
                                    )}
                                </span>
                            ),
                        },
                        {
                            key: 'who',
                            title: 'Client',
                            render: (c) => (
                                <span className="text-xs">
                                    {c.client ?? '—'}
                                    {c.driver && ` · 🚕 ${c.driver}`}
                                </span>
                            ),
                        },
                        {
                            key: 'author',
                            title: 'Recorded by',
                            render: (c) => c.author ?? '—',
                        },
                    ]}
                />
                <Pagination links={complaints.links} />
            </div>
            {editing && (
                <ResolveDialog
                    complaint={editing}
                    onClose={() => setEditing(null)}
                />
            )}
            {creating && <NewDialog onClose={() => setCreating(false)} />}
        </>
    );
}

Complaints.layout = {
    breadcrumbs: [{ title: 'Complaints', href: index() }],
};
