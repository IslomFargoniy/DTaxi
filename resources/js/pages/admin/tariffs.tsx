import { Head, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import TariffController from '@/actions/App/Http/Controllers/Admin/TariffController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTrans } from '@/hooks/use-trans';
import { index } from '@/routes/admin/tariffs';

type Tariff = {
    base_fare: number;
    base_km: string;
    per_km: number;
    rounding_step: number;
    waiting_enabled: boolean;
    waiting_free_min: number;
    waiting_per_min: number;
    night_enabled: boolean;
    night_from: string;
    night_to: string;
    night_coeff: string;
    outside_enabled: boolean;
    outside_per_km: number;
};

type TariffClass = {
    id: number;
    branch_id: number;
    name: string;
    sort: number;
    is_delivery: boolean;
    is_default: boolean;
    is_active: boolean;
    tariff: Tariff | null;
};

function NumberField({
    label,
    value,
    onChange,
    error,
    step,
}: {
    label: string;
    value: string | number;
    onChange: (v: string) => void;
    error?: string;
    step?: string;
}) {
    const { t } = useTrans();

    return (
        <div className="grid gap-1">
            <Label className="text-xs font-normal text-muted-foreground">
                {t(label)}
            </Label>
            <Input
                type="number"
                step={step}
                value={value}
                onChange={(e) => onChange(e.target.value)}
            />
            <InputError message={error} />
        </div>
    );
}

/**
 * Narx formulasi va qo'shimcha qoidalar bitta kartochkada (reja §5b).
 */
function ClassCard({ tariffClass }: { tariffClass: TariffClass }) {
    const { t } = useTrans();
    const tariff = tariffClass.tariff;
    const form = useForm({
        name: tariffClass.name,
        sort: tariffClass.sort,
        is_delivery: tariffClass.is_delivery,
        is_default: tariffClass.is_default,
        is_active: tariffClass.is_active,
        tariff: {
            base_fare: tariff?.base_fare ?? 10000,
            base_km: tariff?.base_km ?? '3',
            per_km: tariff?.per_km ?? 2000,
            rounding_step: tariff?.rounding_step ?? 500,
            waiting_enabled: tariff?.waiting_enabled ?? false,
            waiting_free_min: tariff?.waiting_free_min ?? 3,
            waiting_per_min: tariff?.waiting_per_min ?? 0,
            night_enabled: tariff?.night_enabled ?? false,
            night_from: (tariff?.night_from ?? '23:00').slice(0, 5),
            night_to: (tariff?.night_to ?? '06:00').slice(0, 5),
            night_coeff: tariff?.night_coeff ?? '1.2',
            outside_enabled: tariff?.outside_enabled ?? false,
            outside_per_km: tariff?.outside_per_km ?? 0,
        },
    });
    const errors = form.errors as Record<string, string>;
    const set = (key: keyof typeof form.data.tariff, value: unknown) =>
        form.setData('tariff', { ...form.data.tariff, [key]: value });
    const toggle = (
        key: 'waiting_enabled' | 'night_enabled' | 'outside_enabled',
        label: string,
    ) => (
        <label className="flex items-center gap-2 text-sm font-medium">
            <Checkbox
                checked={form.data.tariff[key]}
                onCheckedChange={(v) => set(key, v === true)}
            />
            {t(label)}
        </label>
    );

    return (
        <Card>
            <CardHeader className="flex flex-row items-center gap-2">
                <Input
                    className="max-w-48 font-semibold"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
                {form.data.is_default && <Badge>{t('Default')}</Badge>}
                {!form.data.is_active && (
                    <Badge variant="secondary">{t('Disabled')}</Badge>
                )}
            </CardHeader>
            <CardContent>
                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(TariffController.update.url(tariffClass.id), {
                            preserveScroll: true,
                        });
                    }}
                >
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <NumberField
                            label="Base fare, so'm"
                            value={form.data.tariff.base_fare}
                            onChange={(v) => set('base_fare', v)}
                            error={errors['tariff.base_fare']}
                        />
                        <NumberField
                            label="Km included"
                            step="0.1"
                            value={form.data.tariff.base_km}
                            onChange={(v) => set('base_km', v)}
                            error={errors['tariff.base_km']}
                        />
                        <NumberField
                            label="Each next km, so'm"
                            value={form.data.tariff.per_km}
                            onChange={(v) => set('per_km', v)}
                            error={errors['tariff.per_km']}
                        />
                        <div className="grid gap-1">
                            <Label className="text-xs font-normal text-muted-foreground">
                                {t("Price rounding step, so'm")}
                            </Label>
                            <select
                                className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                value={form.data.tariff.rounding_step}
                                onChange={(e) =>
                                    set('rounding_step', Number(e.target.value))
                                }
                            >
                                {[1, 100, 500, 1000].map((s) => (
                                    <option key={s} value={s}>
                                        {s}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div className="grid gap-2 rounded-md border p-3">
                        {toggle('waiting_enabled', 'Charge for waiting')}
                        {form.data.tariff.waiting_enabled && (
                            <div className="grid grid-cols-2 gap-3">
                                <NumberField
                                    label="Free minutes"
                                    value={form.data.tariff.waiting_free_min}
                                    onChange={(v) => set('waiting_free_min', v)}
                                />
                                <NumberField
                                    label="Each next minute, so'm"
                                    value={form.data.tariff.waiting_per_min}
                                    onChange={(v) => set('waiting_per_min', v)}
                                />
                            </div>
                        )}
                    </div>
                    <div className="grid gap-2 rounded-md border p-3">
                        {toggle('night_enabled', 'Night rate')}
                        {form.data.tariff.night_enabled && (
                            <div className="grid grid-cols-3 gap-3">
                                <div className="grid gap-1">
                                    <Label className="text-xs font-normal text-muted-foreground">
                                        {t('From')}
                                    </Label>
                                    <Input
                                        type="time"
                                        value={form.data.tariff.night_from}
                                        onChange={(e) =>
                                            set('night_from', e.target.value)
                                        }
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label className="text-xs font-normal text-muted-foreground">
                                        {t('To')}
                                    </Label>
                                    <Input
                                        type="time"
                                        value={form.data.tariff.night_to}
                                        onChange={(e) =>
                                            set('night_to', e.target.value)
                                        }
                                    />
                                </div>
                                <NumberField
                                    label="Coefficient"
                                    step="0.05"
                                    value={form.data.tariff.night_coeff}
                                    onChange={(v) => set('night_coeff', v)}
                                />
                            </div>
                        )}
                    </div>
                    <div className="grid gap-2 rounded-md border p-3">
                        {toggle(
                            'outside_enabled',
                            'Separate price outside the city',
                        )}
                        {form.data.tariff.outside_enabled && (
                            <NumberField
                                label="Each km outside the service area, so'm"
                                value={form.data.tariff.outside_per_km}
                                onChange={(v) => set('outside_per_km', v)}
                            />
                        )}
                    </div>

                    <div className="flex flex-wrap items-center gap-4 text-sm">
                        <label className="flex items-center gap-2">
                            <Checkbox
                                checked={form.data.is_delivery}
                                onCheckedChange={(v) =>
                                    form.setData('is_delivery', v === true)
                                }
                            />
                            {t('Delivery (sender and recipient fields)')}
                        </label>
                        <label className="flex items-center gap-2">
                            <Checkbox
                                checked={form.data.is_default}
                                onCheckedChange={(v) =>
                                    form.setData('is_default', v === true)
                                }
                            />
                            {t('Default')}
                        </label>
                        <label className="flex items-center gap-2">
                            <Checkbox
                                checked={form.data.is_active}
                                onCheckedChange={(v) =>
                                    form.setData('is_active', v === true)
                                }
                            />
                            {t('Active')}
                        </label>
                        <Button className="ml-auto" disabled={form.processing}>
                            {t('Save')}
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

export default function Tariffs({
    branches,
    classes,
}: {
    branches: { id: number; name: string }[];
    classes: TariffClass[];
}) {
    const { t } = useTrans();
    const [branchId, setBranchId] = useState(branches[0]?.id ?? 0);

    return (
        <>
            <Head title={t('Tariffs')} />
            <div className="p-4">
                <div className="mb-4 flex flex-wrap items-center gap-2">
                    <Heading
                        title="Tariffs"
                        description="Base fare includes the first kilometres, then a price per km. Changes apply to new orders only."
                    />
                    <div className="ml-auto flex gap-2">
                        {branches.length > 1 && (
                            <select
                                className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                value={branchId}
                                onChange={(e) =>
                                    setBranchId(Number(e.target.value))
                                }
                            >
                                {branches.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.name}
                                    </option>
                                ))}
                            </select>
                        )}
                        <Button
                            variant="outline"
                            onClick={() => {
                                const name = window.prompt(
                                    t('New tariff class name'),
                                );
                                if (name) {
                                    router.post(
                                        TariffController.store.url(),
                                        { branch_id: branchId, name },
                                        { preserveScroll: true },
                                    );
                                }
                            }}
                        >
                            <Plus /> {t('Add class')}
                        </Button>
                    </div>
                </div>
                <div className="grid gap-4 xl:grid-cols-2">
                    {classes
                        .filter((c) => c.branch_id === branchId)
                        .map((c) => (
                            <ClassCard key={c.id} tariffClass={c} />
                        ))}
                </div>
            </div>
        </>
    );
}

Tariffs.layout = { breadcrumbs: [{ title: 'Tariffs', href: index() }] };
