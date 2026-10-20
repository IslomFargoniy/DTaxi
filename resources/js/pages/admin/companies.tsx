import { Head, useForm } from '@inertiajs/react';
import { Download, Plus } from 'lucide-react';
import { useState } from 'react';
import CompanyController from '@/actions/App/Http/Controllers/Admin/CompanyController';
import { DataTable } from '@/components/data-table';
import { EditDialog } from '@/components/edit-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTrans } from '@/hooks/use-trans';
import { index } from '@/routes/admin/companies';

type Company = {
    id: number;
    branch_id: number;
    branch: string | null;
    name: string;
    contact_phone: string | null;
    discount_percent: number;
    note: string | null;
    is_active: boolean;
    clients_count: number;
    month_orders: number;
    month_sum: number;
};

type Branch = { id: number; name: string };

function CompanyDialog({
    company,
    branches,
    onClose,
}: {
    company: Company | 'new';
    branches: Branch[];
    onClose: () => void;
}) {
    const { t } = useTrans();
    const existing = company === 'new' ? null : company;
    const form = useForm({
        branch_id: existing?.branch_id ?? branches[0]?.id ?? 0,
        name: existing?.name ?? '',
        contact_phone: existing?.contact_phone ?? '',
        discount_percent: existing?.discount_percent ?? 0,
        note: existing?.note ?? '',
        is_active: existing?.is_active ?? true,
    });

    return (
        <EditDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={existing ? existing.name : 'New company'}
            processing={form.processing}
            onSubmit={() =>
                existing
                    ? form.put(CompanyController.update.url(existing.id), {
                          preserveScroll: true,
                          onSuccess: onClose,
                      })
                    : form.post(CompanyController.store.url(), {
                          preserveScroll: true,
                          onSuccess: onClose,
                      })
            }
        >
            {branches.length > 1 && (
                <div className="grid gap-1.5">
                    <Label htmlFor="branch_id">{t('Branch')}</Label>
                    <select
                        id="branch_id"
                        className="h-9 rounded-md border bg-background px-2 text-sm"
                        value={form.data.branch_id}
                        onChange={(e) =>
                            form.setData('branch_id', Number(e.target.value))
                        }
                    >
                        {branches.map((b) => (
                            <option key={b.id} value={b.id}>
                                {b.name}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            <div className="grid gap-1.5">
                <Label htmlFor="name">{t('Name')}</Label>
                <Input
                    id="name"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
                <InputError message={form.errors.name} />
            </div>
            <div className="grid grid-cols-2 items-start gap-2">
                <div className="grid gap-1.5">
                    <Label htmlFor="contact_phone">{t('Phone')}</Label>
                    <Input
                        id="contact_phone"
                        value={form.data.contact_phone}
                        onChange={(e) =>
                            form.setData('contact_phone', e.target.value)
                        }
                    />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="discount_percent">{t('Discount, %')}</Label>
                    <Input
                        id="discount_percent"
                        type="number"
                        min={0}
                        max={100}
                        value={form.data.discount_percent}
                        onChange={(e) =>
                            form.setData(
                                'discount_percent',
                                Number(e.target.value),
                            )
                        }
                    />
                    <InputError message={form.errors.discount_percent} />
                </div>
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="note">{t('Note')}</Label>
                <Input
                    id="note"
                    value={form.data.note}
                    onChange={(e) => form.setData('note', e.target.value)}
                />
            </div>
            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={form.data.is_active}
                    onCheckedChange={(v) =>
                        form.setData('is_active', v === true)
                    }
                />
                {t('Active')}
            </label>
            <p className="text-xs text-muted-foreground">
                {t(
                    'Employees are linked in Clients. Their trips are not paid in cash: the price goes to the driver balance and the company gets a monthly invoice.',
                )}
            </p>
        </EditDialog>
    );
}

export default function Companies({
    companies,
    branches,
    month,
}: {
    companies: Company[];
    branches: Branch[];
    month: string;
}) {
    const { t } = useTrans();
    const [editing, setEditing] = useState<Company | 'new' | null>(null);
    const [invoiceMonth, setInvoiceMonth] = useState(month);

    return (
        <>
            <Head title={t('Companies')} />
            <div className="p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading
                        title="Companies"
                        description="Corporate clients and monthly invoices"
                    />
                    <div className="flex items-center gap-2">
                        <Input
                            type="month"
                            className="w-40"
                            value={invoiceMonth}
                            onChange={(e) => setInvoiceMonth(e.target.value)}
                        />
                        <Button onClick={() => setEditing('new')}>
                            <Plus /> {t('Add')}
                        </Button>
                    </div>
                </div>
                <DataTable
                    rows={companies}
                    onRowClick={setEditing}
                    columns={[
                        {
                            key: 'name',
                            title: 'Name',
                            render: (c) => (
                                <span className="font-medium">{c.name}</span>
                            ),
                        },
                        {
                            key: 'branch',
                            title: 'Branch',
                            render: (c) => c.branch ?? '—',
                        },
                        {
                            key: 'discount',
                            title: 'Discount, %',
                            className: 'text-right',
                            render: (c) => c.discount_percent,
                        },
                        {
                            key: 'clients',
                            title: 'Employees',
                            className: 'text-right',
                            render: (c) => c.clients_count,
                        },
                        {
                            key: 'month',
                            title: 'This month',
                            className: 'text-right',
                            render: (c) =>
                                `${c.month_orders} · ${c.month_sum.toLocaleString('ru-RU')}`,
                        },
                        {
                            key: 'status',
                            title: '',
                            render: (c) =>
                                c.is_active ? null : (
                                    <Badge variant="secondary">
                                        {t('Inactive')}
                                    </Badge>
                                ),
                        },
                        {
                            key: 'invoice',
                            title: '',
                            render: (c) => (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    asChild
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    <a
                                        href={CompanyController.invoice.url(
                                            c.id,
                                            {
                                                query: { month: invoiceMonth },
                                            },
                                        )}
                                    >
                                        <Download /> {t('Invoice')}
                                    </a>
                                </Button>
                            ),
                        },
                    ]}
                />
            </div>
            {editing && (
                <CompanyDialog
                    company={editing}
                    branches={branches}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

Companies.layout = { breadcrumbs: [{ title: 'Companies', href: index() }] };
