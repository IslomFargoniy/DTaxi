import { useForm } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PhoneInput } from '@/components/phone-input';
import { useTrans } from '@/hooks/use-trans';
import { formatPlate } from '@/lib/format';
import { maskDocument, maskPlate, maskWords } from '@/lib/masks';
import type { DriverRow } from './index';

export type DriverFormData = {
    branch_id: number;
    name: string;
    phone: string;
    password: string;
    callsign: string;
    license_no: string;
    license_expires_at: string;
    vehicle: {
        brand: string;
        model: string;
        color: string;
        plate_number: string;
        year: number | string;
        tech_passport_no: string;
        tariff_class_ids: number[];
    };
};

type DriverForm = InertiaFormProps<DriverFormData>;
type Branch = { id: number; name: string };
export type TariffClassOption = { id: number; branch_id: number; name: string };

// Mashina maydonlari: klaviatura, uzunlik va yozilishi
const VEHICLE_INPUT: Record<
    'brand' | 'model' | 'color' | 'plate_number' | 'year' | 'tech_passport_no',
    {
        maxLength?: number;
        inputMode?: 'numeric' | 'text';
        placeholder?: string;
        clean?: (value: string) => string;
    }
> = {
    brand: { maxLength: 50 },
    model: { maxLength: 50 },
    color: { maxLength: 30, clean: maskWords },
    // "01 A 123 BC" yoki "01 123 ABC" — har o'ringa faqat mos belgi
    plate_number: {
        maxLength: 11,
        placeholder: '01 A 123 BC',
        clean: maskPlate,
    },
    year: {
        maxLength: 4,
        inputMode: 'numeric',
        placeholder: String(new Date().getFullYear()),
        clean: (v) => v.replace(/\D/g, ''),
    },
    // Texpasport: 3 harfli seriya + 7 raqam
    tech_passport_no: {
        maxLength: 11,
        placeholder: 'AAF 1234567',
        clean: (v) => maskDocument(v, 3, 7),
    },
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

/**
 * Haydovchi va mashina shakli — yangi haydovchi oynasi va profil sahifasi uchun umumiy.
 */
export function useDriverForm(
    driver:
        | (DriverRow & {
              license_no: string | null;
              license_expires_at: string | null;
          })
        | null,
    branches: Branch[],
    nextCallsign?: string,
): DriverForm {
    return useForm<DriverFormData>({
        branch_id: driver?.branch_id ?? branches[0]?.id ?? 0,
        name: driver?.name ?? '',
        phone: driver?.phone ?? '',
        password: '',
        // Yangi haydovchi — keyingi bo'sh pozivnoy oldindan (o'zgartirish mumkin)
        callsign: driver?.callsign ?? nextCallsign ?? '',
        license_no: driver?.license_no ?? '',
        license_expires_at: driver?.license_expires_at ?? '',
        vehicle: {
            brand: driver?.vehicle?.brand ?? 'Chevrolet',
            model: driver?.vehicle?.model ?? '',
            color: driver?.vehicle?.color ?? '',
            plate_number: formatPlate(driver?.vehicle?.plate_number),
            year: driver?.vehicle?.year ?? '',
            tech_passport_no: driver?.vehicle?.tech_passport_no ?? '',
            tariff_class_ids: driver?.vehicle?.tariff_class_ids ?? [],
        },
    });
}

export function DriverFields({
    form,
    branches,
    isNew,
}: {
    form: DriverForm;
    branches: Branch[];
    isNew: boolean;
}) {
    const errors = form.errors as Record<string, string>;

    return (
        <>
            {branches.length > 1 && (
                <Field id="branch" label="Branch" error={errors.branch_id}>
                    <select
                        id="branch"
                        className="h-9 rounded-md border bg-transparent px-2 text-sm"
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
                </Field>
            )}
            <Field id="callsign" label="Callsign" error={errors.callsign}>
                <Input
                    id="callsign"
                    inputMode="numeric"
                    autoComplete="off"
                    maxLength={6}
                    placeholder="101"
                    value={form.data.callsign}
                    onChange={(e) =>
                        form.setData(
                            'callsign',
                            e.target.value.replace(/\D/g, ''),
                        )
                    }
                />
            </Field>
            <Field id="name" label="Full name" error={errors.name}>
                <Input
                    id="name"
                    autoComplete="off"
                    maxLength={100}
                    value={form.data.name}
                    onChange={(e) =>
                        form.setData('name', maskWords(e.target.value))
                    }
                />
            </Field>
            <Field id="phone" label="Phone" error={errors.phone}>
                <PhoneInput
                    id="phone"
                    autoComplete="off"
                    value={form.data.phone}
                    onChange={(v) => form.setData('phone', v)}
                />
            </Field>
            <Field
                id="password"
                label={isNew ? 'Password' : 'New password'}
                error={errors.password}
            >
                <Input
                    id="password"
                    type="password"
                    autoComplete="new-password"
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                />
            </Field>
            <Field
                id="license_no"
                label="Driving licence"
                error={errors.license_no}
            >
                <Input
                    id="license_no"
                    autoComplete="off"
                    maxLength={10}
                    placeholder="AF 1234567"
                    value={form.data.license_no}
                    onChange={(e) =>
                        form.setData(
                            'license_no',
                            maskDocument(e.target.value, 2, 7),
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
                        form.setData('license_expires_at', e.target.value)
                    }
                />
            </Field>
        </>
    );
}

export function VehicleFields({
    form,
    tariffClasses,
}: {
    form: DriverForm;
    tariffClasses: TariffClassOption[];
}) {
    const { t } = useTrans();
    const errors = form.errors as Record<string, string>;
    const classes = tariffClasses.filter(
        (c) => c.branch_id === form.data.branch_id,
    );
    const setVehicle = (key: keyof DriverFormData['vehicle'], value: unknown) =>
        form.setData('vehicle', { ...form.data.vehicle, [key]: value });

    return (
        <>
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
                    error={errors[`vehicle.${key}`]}
                >
                    <Input
                        id={`vehicle-${key}`}
                        autoComplete="off"
                        maxLength={VEHICLE_INPUT[key].maxLength}
                        inputMode={VEHICLE_INPUT[key].inputMode}
                        placeholder={VEHICLE_INPUT[key].placeholder}
                        value={String(form.data.vehicle[key] ?? '')}
                        onChange={(e) =>
                            setVehicle(
                                key,
                                VEHICLE_INPUT[key].clean?.(e.target.value) ??
                                    e.target.value,
                            )
                        }
                    />
                </Field>
            ))}
            <div className="grid gap-2 sm:col-span-2">
                <Label>{t('Tariff classes this car works in')}</Label>
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
                                                  ...form.data.vehicle
                                                      .tariff_class_ids,
                                                  c.id,
                                              ]
                                            : form.data.vehicle.tariff_class_ids.filter(
                                                  (id) => id !== c.id,
                                              ),
                                    )
                                }
                            />
                            {c.name}
                        </label>
                    ))}
                </div>
            </div>
        </>
    );
}
