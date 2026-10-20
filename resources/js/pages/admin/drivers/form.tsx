import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import DriverController from '@/actions/App/Http/Controllers/Admin/DriverController';
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
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCan } from '@/hooks/use-can';
import { useTrans } from '@/hooks/use-trans';
import { index } from '@/routes/admin/drivers';
import type { DriverRow } from './index';

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
    tariffClasses: { id: number; branch_id: number; name: string }[];
    documentTypes: string[];
};

function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    const { t } = useTrans();

    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{t(label)}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

export default function DriverForm({
    driver,
    branches,
    tariffClasses,
    documentTypes,
}: Props) {
    const { t } = useTrans();
    const { can } = useCan();
    const form = useForm({
        branch_id: driver?.branch_id ?? branches[0]?.id ?? 0,
        name: driver?.name ?? '',
        phone: driver?.phone ?? '',
        password: '',
        callsign: driver?.callsign ?? '',
        license_no: driver?.license_no ?? '',
        license_expires_at: driver?.license_expires_at ?? '',
        vehicle: {
            brand: driver?.vehicle?.brand ?? 'Chevrolet',
            model: driver?.vehicle?.model ?? '',
            color: driver?.vehicle?.color ?? '',
            plate_number: driver?.vehicle?.plate_number ?? '',
            year: driver?.vehicle?.year ?? '',
            tech_passport_no: driver?.vehicle?.tech_passport_no ?? '',
            tariff_class_ids:
                driver?.vehicle?.tariff_class_ids ?? ([] as number[]),
        },
    });
    const errors = form.errors as Record<string, string>;
    const classes = tariffClasses.filter(
        (c) => c.branch_id === form.data.branch_id,
    );
    const setVehicle = (key: keyof typeof form.data.vehicle, value: unknown) =>
        form.setData('vehicle', { ...form.data.vehicle, [key]: value });

    const [adjust, setAdjust] = useState({ amount: '', comment: '' });
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
            <div className="grid gap-4 p-4 xl:grid-cols-[minmax(0,1fr)_420px]">
                <form onSubmit={submit} className="grid content-start gap-4">
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
                                    onClick={() => {
                                        const blocked =
                                            driver.blocked_until &&
                                            new Date(driver.blocked_until) >
                                                new Date();
                                        const minutes = blocked
                                            ? 0
                                            : Number(
                                                  window.prompt(
                                                      t(
                                                          'Block for how many minutes?',
                                                      ),
                                                      '60',
                                                  ),
                                              );
                                        if (!Number.isNaN(minutes)) {
                                            router.post(
                                                DriverController.block.url(
                                                    driver.id,
                                                ),
                                                { minutes },
                                                { preserveScroll: true },
                                            );
                                        }
                                    }}
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
                            {branches.length > 1 && (
                                <Field
                                    id="branch"
                                    label="Branch"
                                    error={errors.branch_id}
                                >
                                    <select
                                        id="branch"
                                        className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                        value={form.data.branch_id}
                                        onChange={(e) =>
                                            form.setData(
                                                'branch_id',
                                                Number(e.target.value),
                                            )
                                        }
                                    >
                                        {branches.map((b) => (
                                            <option key={b.id} value={b.id}>
                                                {b.name}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            )}
                            <Field
                                id="callsign"
                                label="Callsign"
                                error={errors.callsign}
                            >
                                <Input
                                    id="callsign"
                                    value={form.data.callsign}
                                    onChange={(e) =>
                                        form.setData('callsign', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                id="name"
                                label="Full name"
                                error={errors.name}
                            >
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                id="phone"
                                label="Phone"
                                error={errors.phone}
                            >
                                <Input
                                    id="phone"
                                    value={form.data.phone}
                                    onChange={(e) =>
                                        form.setData('phone', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                id="password"
                                label={driver ? 'New password' : 'Password'}
                                error={errors.password}
                            >
                                <Input
                                    id="password"
                                    type="password"
                                    autoComplete="new-password"
                                    value={form.data.password}
                                    onChange={(e) =>
                                        form.setData('password', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                id="license_no"
                                label="Driving licence"
                                error={errors.license_no}
                            >
                                <Input
                                    id="license_no"
                                    value={form.data.license_no}
                                    onChange={(e) =>
                                        form.setData(
                                            'license_no',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                id="license_expires_at"
                                label="Licence valid until"
                                error={errors.license_expires_at}
                            >
                                <Input
                                    id="license_expires_at"
                                    type="date"
                                    value={form.data.license_expires_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'license_expires_at',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
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
                            {(
                                [
                                    'brand',
                                    'model',
                                    'color',
                                    'plate_number',
                                    'year',
                                    'tech_passport_no',
                                ] as const
                            ).map((key) => (
                                <Field
                                    key={key}
                                    id={`vehicle-${key}`}
                                    label={key}
                                    error={
                                        errors[`vehicle.${key}`] ??
                                        (key === 'plate_number'
                                            ? errors.plate
                                            : undefined)
                                    }
                                >
                                    <Input
                                        id={`vehicle-${key}`}
                                        value={String(
                                            form.data.vehicle[key] ?? '',
                                        )}
                                        onChange={(e) =>
                                            setVehicle(key, e.target.value)
                                        }
                                    />
                                </Field>
                            ))}
                            <div className="grid gap-2 sm:col-span-2">
                                <Label>
                                    {t('Tariff classes this car works in')}
                                </Label>
                                <div className="flex flex-wrap gap-4">
                                    {classes.map((c) => (
                                        <label
                                            key={c.id}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <Checkbox
                                                checked={form.data.vehicle.tariff_class_ids.includes(
                                                    c.id,
                                                )}
                                                onCheckedChange={(v) =>
                                                    setVehicle(
                                                        'tariff_class_ids',
                                                        v
                                                            ? [
                                                                  ...form.data
                                                                      .vehicle
                                                                      .tariff_class_ids,
                                                                  c.id,
                                                              ]
                                                            : form.data.vehicle.tariff_class_ids.filter(
                                                                  (id) =>
                                                                      id !==
                                                                      c.id,
                                                              ),
                                                    )
                                                }
                                            />
                                            {c.name}
                                        </label>
                                    ))}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <div>
                        <Button disabled={form.processing}>{t('Save')}</Button>
                    </div>
                </form>

                {driver && (
                    <div className="grid content-start gap-4">
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
                                            <Input
                                                type="number"
                                                placeholder={t('Amount (+/-)')}
                                                value={adjust.amount}
                                                onChange={(e) =>
                                                    setAdjust({
                                                        ...adjust,
                                                        amount: e.target.value,
                                                    })
                                                }
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                disabled={
                                                    !adjust.amount ||
                                                    !adjust.comment
                                                }
                                                onClick={() =>
                                                    router.post(
                                                        DriverController.adjustBalance.url(
                                                            driver.id,
                                                        ),
                                                        adjust,
                                                        {
                                                            preserveScroll: true,
                                                            onSuccess: () =>
                                                                setAdjust({
                                                                    amount: '',
                                                                    comment: '',
                                                                }),
                                                        },
                                                    )
                                                }
                                            >
                                                {t('Apply')}
                                            </Button>
                                        </div>
                                        <Input
                                            placeholder={t(
                                                'Comment (required)',
                                            )}
                                            value={adjust.comment}
                                            onChange={(e) =>
                                                setAdjust({
                                                    ...adjust,
                                                    comment: e.target.value,
                                                })
                                            }
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
                                                            : 'text-emerald-600'
                                                    }
                                                >
                                                    {r.amount.toLocaleString(
                                                        'ru-RU',
                                                    )}
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
                                                        onClick={() =>
                                                            window.confirm(
                                                                t(
                                                                    'Return this penalty to the driver?',
                                                                ),
                                                            ) &&
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
                                                r.earnings.toLocaleString(
                                                    'ru-RU',
                                                ),
                                        },
                                        {
                                            key: 'commission',
                                            title: 'Commission',
                                            className: 'text-right',
                                            render: (r) =>
                                                r.commission.toLocaleString(
                                                    'ru-RU',
                                                ),
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
        </>
    );
}

DriverForm.layout = { breadcrumbs: [{ title: 'Drivers', href: index() }] };
