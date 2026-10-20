import { Head, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import BranchController from '@/actions/App/Http/Controllers/Admin/BranchController';
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
import { getJson } from '@/lib/http';
import { index } from '@/routes/admin/branches';
import { children as regionChildren } from '@/routes/admin/regions';

type Branch = {
    id: number;
    name: string;
    service_name: string | null;
    greeting_text: string | null;
    timezone: string;
    is_active: boolean;
    region_id: number | null;
    parent_region_id: number | null;
    region: string | null;
    work_schedule: Schedule | null;
    holidays: string[];
    settings: Record<string, number | null>;
};

type DayHours = { from: string; to: string } | null;
type Schedule = Record<string, DayHours>;

const WEEKDAYS: [string, string][] = [
    ['mon', 'Monday'],
    ['tue', 'Tuesday'],
    ['wed', 'Wednesday'],
    ['thu', 'Thursday'],
    ['fri', 'Friday'],
    ['sat', 'Saturday'],
    ['sun', 'Sunday'],
];

/**
 * Ish vaqti: o'chiq — kecha-kunduz; yoqilsa har kun uchun soatlar (dam olish kuni — belgisiz), bayram sanalari.
 */
function ScheduleEditor({
    schedule,
    holidays,
    onChange,
    onHolidays,
    errors,
}: {
    schedule: Schedule | null;
    holidays: string[];
    onChange: (s: Schedule | null) => void;
    onHolidays: (h: string[]) => void;
    errors: Record<string, string>;
}) {
    const { t } = useTrans();
    const [holidayText, setHolidayText] = useState(holidays.join(', '));
    const scheduleError = Object.entries(errors).find(([k]) =>
        k.startsWith('work_schedule'),
    )?.[1];

    return (
        <div className="grid gap-2 border-t pt-3">
            <label className="flex items-center gap-2 text-sm font-medium">
                <Checkbox
                    checked={schedule !== null}
                    onCheckedChange={(v) =>
                        onChange(
                            v
                                ? Object.fromEntries(
                                      WEEKDAYS.map(([d]) => [
                                          d,
                                          { from: '07:00', to: '23:00' },
                                      ]),
                                  )
                                : null,
                        )
                    }
                />
                {t('Working hours (otherwise 24/7)')}
            </label>
            {schedule &&
                WEEKDAYS.map(([day, label]) => {
                    const hours = schedule[day] ?? null;

                    return (
                        <div
                            key={day}
                            className="grid grid-cols-[110px_1fr_1fr] items-center gap-2 text-sm"
                        >
                            <label className="flex items-center gap-2">
                                <Checkbox
                                    checked={hours !== null}
                                    onCheckedChange={(v) =>
                                        onChange({
                                            ...schedule,
                                            [day]: v
                                                ? { from: '07:00', to: '23:00' }
                                                : null,
                                        })
                                    }
                                />
                                {t(label)}
                            </label>
                            {hours ? (
                                <>
                                    <Input
                                        type="time"
                                        className="h-8"
                                        value={hours.from}
                                        onChange={(e) =>
                                            onChange({
                                                ...schedule,
                                                [day]: {
                                                    ...hours,
                                                    from: e.target.value,
                                                },
                                            })
                                        }
                                    />
                                    <Input
                                        type="time"
                                        className="h-8"
                                        value={hours.to}
                                        onChange={(e) =>
                                            onChange({
                                                ...schedule,
                                                [day]: {
                                                    ...hours,
                                                    to: e.target.value,
                                                },
                                            })
                                        }
                                    />
                                </>
                            ) : (
                                <span className="col-span-2 text-xs text-muted-foreground">
                                    {t('Day off')}
                                </span>
                            )}
                        </div>
                    );
                })}
            <InputError message={scheduleError} />
            <div className="grid gap-1.5">
                <Label htmlFor="holidays" className="font-normal">
                    {t('Holidays (YYYY-MM-DD, comma separated)')}
                </Label>
                <Input
                    id="holidays"
                    placeholder="2027-01-01, 2027-03-21"
                    value={holidayText}
                    onChange={(e) => {
                        setHolidayText(e.target.value);
                        onHolidays(
                            e.target.value.split(/[\s,]+/).filter(Boolean),
                        );
                    }}
                />
                <InputError
                    message={
                        Object.entries(errors).find(([k]) =>
                            k.startsWith('holidays'),
                        )?.[1]
                    }
                />
            </div>
        </div>
    );
}

const settingLabels: Record<string, string> = {
    'drivers.commission_percent': 'Driver commission, %',
    'drivers.min_balance': "Minimum driver balance, so'm",
    'drivers.cancel_penalty': "Cancellation penalty, so'm",
    'drivers.max_cancels_per_shift': 'Cancels before a temporary block',
    'drivers.block_minutes': 'Block duration, minutes',
    'dispatch.step_seconds': 'Seconds before widening the search',
    'dispatch.direct_offer_seconds': 'Seconds to answer an assigned order',
};

function BranchDialog({
    branch,
    regions,
    defaults,
    onClose,
}: {
    branch: Branch | 'new';
    regions: { id: number; name: string }[];
    defaults: Record<string, number>;
    onClose: () => void;
}) {
    const { t } = useTrans();
    const existing = branch === 'new' ? null : branch;
    const [parent, setParent] = useState<number | ''>(
        existing?.parent_region_id ?? '',
    );
    const [children, setChildren] = useState<
        { id: number; name: string; type: string }[]
    >([]);
    const form = useForm({
        name: existing?.name ?? '',
        service_name: existing?.service_name ?? '',
        greeting_text: existing?.greeting_text ?? '',
        timezone: existing?.timezone ?? 'Asia/Tashkent',
        is_active: existing?.is_active ?? true,
        region_id: existing?.region_id ?? ('' as number | ''),
        work_schedule: (existing?.work_schedule ?? null) as Schedule | null,
        holidays: existing?.holidays ?? ([] as string[]),
        settings: Object.fromEntries(
            Object.keys(settingLabels).map((k) => [
                k.replaceAll('.', '__'),
                existing?.settings[k] ?? '',
            ]),
        ) as Record<string, number | string>,
    });

    useEffect(() => {
        if (parent) {
            getJson<{ id: number; name: string; type: string }[]>(
                regionChildren.url(parent),
            )
                .then(setChildren)
                .catch(() => setChildren([]));
        }
    }, [parent]);

    const errors = form.errors as Record<string, string>;

    return (
        <EditDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={existing ? 'Edit branch' : 'New branch'}
            processing={form.processing}
            onSubmit={() =>
                existing
                    ? form.put(BranchController.update.url(existing.id), {
                          onSuccess: onClose,
                      })
                    : form.post(BranchController.store.url(), {
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
                <InputError message={errors.name} />
            </div>
            <div className="grid grid-cols-2 items-start gap-2">
                <div className="grid gap-1.5">
                    <Label>{t('Region')}</Label>
                    <select
                        className="h-9 rounded-md border bg-transparent px-2 text-sm"
                        value={parent}
                        onChange={(e) =>
                            setParent(Number(e.target.value) || '')
                        }
                    >
                        <option value="">—</option>
                        {regions.map((r) => (
                            <option key={r.id} value={r.id}>
                                {r.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-1.5">
                    <Label>{t('City or district')}</Label>
                    <select
                        className="h-9 rounded-md border bg-transparent px-2 text-sm"
                        value={form.data.region_id}
                        onChange={(e) =>
                            form.setData(
                                'region_id',
                                Number(e.target.value) || '',
                            )
                        }
                    >
                        <option value="">—</option>
                        {children.map((r) => (
                            <option key={r.id} value={r.id}>
                                {r.name}
                            </option>
                        ))}
                    </select>
                </div>
            </div>
            <p className="text-xs text-muted-foreground">
                {t(
                    'The service area is filled from the boundary of the chosen city or district.',
                )}
            </p>
            <div className="grid gap-1.5">
                <Label htmlFor="service_name">
                    {t('Service name (IVR greeting)')}
                </Label>
                <Input
                    id="service_name"
                    placeholder="Ibrat taksi"
                    value={form.data.service_name}
                    onChange={(e) =>
                        form.setData('service_name', e.target.value)
                    }
                />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="greeting">{t('Greeting text')}</Label>
                <Input
                    id="greeting"
                    placeholder={t(
                        'Assalomu alaykum, … xizmatiga xush kelibsiz',
                    )}
                    value={form.data.greeting_text}
                    onChange={(e) =>
                        form.setData('greeting_text', e.target.value)
                    }
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
            <ScheduleEditor
                schedule={form.data.work_schedule}
                holidays={form.data.holidays}
                onChange={(v) => form.setData('work_schedule', v)}
                onHolidays={(v) => form.setData('holidays', v)}
                errors={errors}
            />
            <div className="grid gap-2 border-t pt-3">
                <p className="text-xs text-muted-foreground">
                    {t('Leave empty to use the company-wide value.')}
                </p>
                {Object.entries(settingLabels).map(([key, label]) => {
                    const field = key.replaceAll('.', '__');

                    return (
                        <div
                            key={key}
                            className="grid grid-cols-[1fr_140px] items-center gap-2"
                        >
                            <Label htmlFor={field} className="font-normal">
                                {t(label)}
                            </Label>
                            <Input
                                id={field}
                                type="number"
                                placeholder={String(defaults[key] ?? '')}
                                value={form.data.settings[field]}
                                onChange={(e) =>
                                    form.setData('settings', {
                                        ...form.data.settings,
                                        [field]: e.target.value,
                                    })
                                }
                            />
                        </div>
                    );
                })}
            </div>
        </EditDialog>
    );
}

export default function Branches({
    branches,
    regions,
    defaults,
}: {
    branches: Branch[];
    regions: { id: number; name: string }[];
    defaults: Record<string, number>;
}) {
    const { t } = useTrans();
    const [editing, setEditing] = useState<Branch | 'new' | null>(null);

    return (
        <>
            <Head title={t('Branches')} />
            <div className="p-4">
                <div className="mb-4 flex items-center justify-between">
                    <Heading title="Branches" />
                    <Button onClick={() => setEditing('new')}>
                        <Plus /> {t('New branch')}
                    </Button>
                </div>
                <DataTable
                    rows={branches}
                    onRowClick={setEditing}
                    columns={[
                        {
                            key: 'name',
                            title: 'Name',
                            render: (b) => (
                                <span className="font-medium">{b.name}</span>
                            ),
                        },
                        {
                            key: 'region',
                            title: 'Region',
                            render: (b) => b.region ?? '—',
                        },
                        {
                            key: 'service',
                            title: 'Service name (IVR greeting)',
                            render: (b) => b.service_name ?? '—',
                        },
                        {
                            key: 'active',
                            title: 'Status',
                            render: (b) =>
                                b.is_active ? (
                                    <Badge>{t('Active')}</Badge>
                                ) : (
                                    <Badge variant="secondary">
                                        {t('Disabled')}
                                    </Badge>
                                ),
                        },
                    ]}
                />
            </div>
            {editing && (
                <BranchDialog
                    branch={editing}
                    regions={regions}
                    defaults={defaults}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

Branches.layout = { breadcrumbs: [{ title: 'Branches', href: index() }] };
