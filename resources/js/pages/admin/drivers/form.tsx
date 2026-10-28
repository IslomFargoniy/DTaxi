import { Head, router, useForm } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { useConfirm } from '@/components/confirm-dialog';
import { EditDialog } from '@/components/edit-dialog';
import { useState } from 'react';
import DriverController from '@/actions/App/Http/Controllers/Admin/DriverController';
import { DataTable, formatDateTime } from '@/components/data-table';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCan } from '@/hooks/use-can';
import { useTrans } from '@/hooks/use-trans';
import { DriverFields, useDriverForm, VehicleFields } from './fields';
import type { TariffClassOption } from './fields';
import { index } from '@/routes/admin/drivers';
import type { DriverRow } from './index';
import { MoneyInput } from '@/components/money-input';
import { formatMoney, formatNumber } from '@/lib/format';

type Driver = DriverRow & {
    license_no: string | null;
    license_expires_at: string | null;
    documents: {
        id: number;
        type: string;
        status: string;
        expires_at: string | null;
        url: string;
    }[];
    transactions: {
        id: number;
        type: string;
        source: string;
        amount: number;
        balance_after: number;
        comment: string | null;
        created_at: string | null;
        excused_at: string | null;
    }[];
    shifts: {
        started_at: string;
        ended_at: string | null;
        minutes: number;
        orders: number;
        earnings: number;
        commission: number;
    }[];
};

type Props = {
    driver: Driver | null;
    branches: { id: number; name: string }[];
    tariffClasses: TariffClassOption[];
    documentTypes: string[];
    /** Faqat yangi haydovchi: keyingi bo'sh pozivnoy */
    nextCallsign?: string;
};

export default function DriverForm({
    driver,
    branches,
    tariffClasses,
    documentTypes,
    nextCallsign,
}: Props) {
    const { t } = useTrans();
    const confirm = useConfirm();
    const { can } = useCan();
    const form = useDriverForm(driver, branches, nextCallsign);

    const [blockOpen, setBlockOpen] = useState(false);
    const block = useForm({ minutes: 60 });
    const adjust = useForm({ amount: null as number | null, comment: '' });
    const [doc, setDoc] = useState<{
        type: string;
        file: File | null;
        expires_at: string;
    }>({ type: documentTypes[0], file: null, expires_at: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (driver) {
            form.put(DriverController.update.url(driver.id), {
                preserveScroll: true,
            });
        } else {
            form.post(DriverController.store.url());
        }
    };

    return (
        <>
            <Head
                title={
                    driver
                        ? `${t('Driver')} ${driver.callsign}`
                        : t('Add driver')
                }
            />
            <div
                className={cn(
                    'grid gap-4 p-4',
                    driver && 'xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]',
                )}
            >
                <form
                    onSubmit={submit}
                    className="grid min-w-0 content-start gap-4"
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <Heading
                            title={
                                driver
                                    ? `${driver.callsign} · ${driver.name}`
                                    : 'Add driver'
                            }
                        />
                        {driver && (
                            <div className="ml-auto flex gap-2">
                                {driver.is_approved ? (
                                    <Badge>{t('Approved')}</Badge>
                                ) : (
                                    <Badge variant="outline">
                                        {t('Not approved')}
                                    </Badge>
                                )}
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={
                                        driver.is_approved
                                            ? 'outline'
                                            : 'default'
                                    }
                                    onClick={() =>
                                        router.post(
                                            DriverController.approve.url(
                                                driver.id,
                                            ),
                                            { approved: !driver.is_approved },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {driver.is_approved
                                        ? t('Revoke approval')
                                        : t('Approve')}
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        driver.blocked_until &&
                                        new Date(driver.blocked_until) >
                                            new Date()
                                            ? router.post(
                                                  DriverController.block.url(
                                                      driver.id,
                                                  ),
                                                  { minutes: 0 },
                                                  { preserveScroll: true },
                                              )
                                            : setBlockOpen(true)
                                    }
                                >
                                    {driver.blocked_until &&
                                    new Date(driver.blocked_until) > new Date()
                                        ? t('Unblock')
                                        : t('Block')}
                                </Button>
                            </div>
                        )}
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Driver')}</CardTitle>
                        </CardHeader>
                        <CardContent className="grid items-start gap-3 sm:grid-cols-2">
                            <DriverFields
                                form={form}
                                branches={branches}
                                isNew={!driver}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>{t('Car')}</CardTitle>
                            {driver?.vehicle && !driver.vehicle.is_approved && (
                                <Badge variant="outline">
                                    {t('Not approved')}
                                </Badge>
                            )}
                        </CardHeader>
                        <CardContent className="grid items-start gap-3 sm:grid-cols-2">
                            <VehicleFields
                                form={form}
                                tariffClasses={tariffClasses}
                            />
                        </CardContent>
                    </Card>

                    <div>
                        <Button disabled={form.processing}>{t('Save')}</Button>
                    </div>
                </form>

                {driver && (
                    <div className="grid min-w-0 content-start gap-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    {t('Balance')}:{' '}
                                    {formatMoney(driver.balance)}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3">
                                {can('balance.adjust') && (
                                    <div className="grid gap-2">
                                        <div className="flex gap-2">
                                            <div className="min-w-0 flex-1">
                                                <MoneyInput
                                                    allowNegative
                                                    placeholder={t(
                                                        'Amount (+/-)',
                                                    )}
                                                    value={adjust.data.amount}
                                                    onChange={(v) =>
                                                        adjust.setData(
                                                            'amount',
                                                            v,
                                                        )
                                                    }
                                                />
                                            </div>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                disabled={
                                                    adjust.processing ||
                                                    !adjust.data.amount ||
                                                    !adjust.data.comment.trim()
                                                }
                                                onClick={() =>
                                                    adjust.post(
                                                        DriverController.adjustBalance.url(
                                                            driver.id,
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                            onSuccess: () =>
                                                                adjust.reset(),
                                                        },
                                                    )
                                                }
                                            >
                                                {t('Apply')}
                                            </Button>
                                        </div>
                                        <InputError
                                            message={adjust.errors.amount}
                                        />
                                        <Input
                                            placeholder={t(
                                                'Comment (required)',
                                            )}
                                            maxLength={255}
                                            value={adjust.data.comment}
                                            onChange={(e) =>
                                                adjust.setData(
                                                    'comment',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={adjust.errors.comment}
                                        />
                                    </div>
                                )}
                                <DataTable
                                    rows={driver.transactions}
                                    columns={[
                                        {
                                            key: 'date',
                                            title: 'Date',
                                            render: (r) =>
                                                formatDateTime(r.created_at),
                                        },
                                        {
                                            key: 'type',
                                            title: 'Type',
                                            render: (r) => t(r.type),
                                        },
                                        {
                                            key: 'amount',
                                            title: 'Amount',
                                            className: 'text-right',
                                            render: (r) => (
                                                <span
                                                    className={
                                                        r.amount < 0
                                                            ? 'text-destructive'
                                                            : 'text-emerald-600 dark:text-emerald-400'
                                                    }
                                                >
                                                    {r.amount > 0 ? '+' : ''}
                                                    {formatNumber(r.amount)}
                                                </span>
                                            ),
                                        },
                                        {
                                            key: 'excuse',
                                            title: '',
                                            render: (r) =>
                                                r.type !==
                                                'penalty' ? null : r.excused_at ? (
                                                    <Badge variant="outline">
                                                        {t('Returned')}
                                                    </Badge>
                                                ) : can('balance.adjust') ? (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        title={r.comment ?? ''}
                                                        onClick={async () =>
                                                            (await confirm({
                                                                title: 'Return this penalty to the driver?',
                                                                confirmLabel:
                                                                    'Return',
                                                                destructive: false,
                                                            })) &&
                                                            router.post(
                                                                DriverController.excusePenalty.url(
                                                                    {
                                                                        driver: driver.id,
                                                                        transaction:
                                                                            r.id,
                                                                    },
                                                                ),
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {t('Excuse')}
                                                    </Button>
                                                ) : null,
                                        },
                                    ]}
                                />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('Shifts')}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <DataTable
                                    rows={driver.shifts.map((r, i) => ({
                                        ...r,
                                        id: i,
                                    }))}
                                    columns={[
                                        {
                                            key: 'start',
                                            title: 'Start',
                                            render: (r) =>
                                                formatDateTime(r.started_at),
                                        },
                                        {
                                            key: 'duration',
                                            title: 'Duration',
                                            render: (r) =>
                                                `${Math.floor(r.minutes / 60)}:${String(r.minutes % 60).padStart(2, '0')}${r.ended_at ? '' : ' ▶'}`,
                                        },
                                        {
                                            key: 'orders',
                                            title: 'Orders',
                                            className: 'text-right',
                                            render: (r) => r.orders,
                                        },
                                        {
                                            key: 'earnings',
                                            title: 'Earnings',
                                            className: 'text-right',
                                            render: (r) =>
                                                formatNumber(r.earnings),
                                        },
                                        {
                                            key: 'commission',
                                            title: 'Commission',
                                            className: 'text-right',
                                            render: (r) =>
                                                formatNumber(r.commission),
                                        },
                                    ]}
                                />
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>{t('Documents')}</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3">
                                {driver.documents.map((d) => (
                                    <div
                                        key={d.id}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <a
                                            href={d.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="underline"
                                        >
                                            {t(d.type)}
                                        </a>
                                        <span className="text-xs text-muted-foreground">
                                            {d.expires_at}
                                        </span>
                                        <Badge
                                            variant={
                                                d.status === 'approved'
                                                    ? 'default'
                                                    : d.status === 'rejected'
                                                      ? 'destructive'
                                                      : 'outline'
                                            }
                                            className="ml-auto"
                                        >
                                            {t(d.status)}
                                        </Badge>
                                        {d.status !== 'approved' && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.put(
                                                        DriverController.reviewDocument.url(
                                                            [driver.id, d.id],
                                                        ),
                                                        { status: 'approved' },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                ✓
                                            </Button>
                                        )}
                                        {d.status !== 'rejected' && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.put(
                                                        DriverController.reviewDocument.url(
                                                            [driver.id, d.id],
                                                        ),
                                                        { status: 'rejected' },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                ✕
                                            </Button>
                                        )}
                                    </div>
                                ))}
                                <div className="grid gap-2 border-t pt-3">
                                    <select
                                        className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                        value={doc.type}
                                        onChange={(e) =>
                                            setDoc({
                                                ...doc,
                                                type: e.target.value,
                                            })
                                        }
                                    >
                                        {documentTypes.map((type) => (
                                            <option key={type} value={type}>
                                                {t(type)}
                                            </option>
                                        ))}
                                    </select>
                                    <Input
                                        type="file"
                                        accept="image/*,application/pdf"
                                        onChange={(e) =>
                                            setDoc({
                                                ...doc,
                                                file:
                                                    e.target.files?.[0] ?? null,
                                            })
                                        }
                                    />
                                    <Input
                                        type="date"
                                        value={doc.expires_at}
                                        onChange={(e) =>
                                            setDoc({
                                                ...doc,
                                                expires_at: e.target.value,
                                            })
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={!doc.file}
                                        onClick={() =>
                                            router.post(
                                                DriverController.uploadDocument.url(
                                                    driver.id,
                                                ),
                                                { ...doc, file: doc.file },
                                                {
                                                    forceFormData: true,
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        setDoc({
                                                            ...doc,
                                                            file: null,
                                                        }),
                                                },
                                            )
                                        }
                                    >
                                        {t('Upload')}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}
            </div>
            {driver && (
                <EditDialog
                    open={blockOpen}
                    onOpenChange={(open) => {
                        setBlockOpen(open);
                        if (!open) {
                            block.reset();
                            block.clearErrors();
                        }
                    }}
                    title="Block driver"
                    processing={block.processing}
                    onSubmit={() =>
                        block.post(DriverController.block.url(driver.id), {
                            preserveScroll: true,
                            onSuccess: () => setBlockOpen(false),
                        })
                    }
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor="block-minutes">
                            {t('Block for how many minutes?')}
                        </Label>
                        <Input
                            id="block-minutes"
                            type="number"
                            min={1}
                            autoFocus
                            value={block.data.minutes}
                            onChange={(e) =>
                                block.setData('minutes', Number(e.target.value))
                            }
                        />
                        <InputError message={block.errors.minutes} />
                    </div>
                </EditDialog>
            )}
        </>
    );
}

DriverForm.layout = { breadcrumbs: [{ title: 'Drivers', href: index() }] };
