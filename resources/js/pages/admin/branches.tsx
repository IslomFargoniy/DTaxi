import { Head, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Marker } from 'maplibre-gl';
import type { GeoJSONSource, Map as MapLibreMap } from 'maplibre-gl';
import { useCallback, useEffect, useRef, useState } from 'react';
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
import { TaxiMap } from '@/components/map/taxi-map';
import { useTrans } from '@/hooks/use-trans';
import { getJson } from '@/lib/http';
import { index } from '@/routes/admin/branches';
import { children as regionChildren } from '@/routes/admin/regions';
import { MoneyInput } from '@/components/money-input';
import { formatNumber } from '@/lib/format';

type Branch = {
    id: number;
    name: string;
    service_name: string | null;
    greeting_text: string | null;
    timezone: string;
    is_active: boolean;
    region_id: number | null;
    center: { lat: number; lng: number } | null;
    service_radius_km: number | null;
    parent_region_id: number | null;
    region: string | null;
    work_schedule: Schedule | null;
    holidays: string[];
    settings: Record<string, number | null>;
};

type DayHours = { from: string; to: string } | null;
type Schedule = Record<string, DayHours>;

type Point = { lat: number; lng: number };

/** Markaz atrofidagi aylana (xaritada ko'rsatish uchun; server hududni PostGIS ST_Buffer bilan quradi) */
function circle(center: Point, km: number): GeoJSON.Feature<GeoJSON.Polygon> {
    const steps = 64;
    const dLat = km / 111.32;
    const dLng = km / (111.32 * Math.cos((center.lat * Math.PI) / 180));
    const ring = Array.from({ length: steps + 1 }, (_, i) => {
        const a = (2 * Math.PI * i) / steps;

        return [
            center.lng + dLng * Math.sin(a),
            center.lat + dLat * Math.cos(a),
        ];
    });

    return {
        type: 'Feature',
        properties: {},
        geometry: { type: 'Polygon', coordinates: [ring] },
    };
}

/**
 * Filial joylashuvi va xizmat radiusi: xaritani bosib markaz qo'yiladi, aylana — xizmat hududi.
 */
function AreaPicker({
    tiles,
    center,
    radiusKm,
    onCenter,
}: {
    tiles: string;
    center: Point | null;
    radiusKm: number | null;
    onCenter: (p: Point) => void;
}) {
    const mapRef = useRef<MapLibreMap | null>(null);
    const markerRef = useRef<Marker | null>(null);
    const [ready, setReady] = useState(false);
    // Xarita bir marta yaratiladi — boshlang'ich markaz keyingi bosishlarda o'zgarmaydi
    const [initial] = useState(center);

    const onReady = useCallback((map: MapLibreMap) => {
        mapRef.current = map;
        map.addSource('area', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] },
        });
        map.addLayer({
            id: 'area-fill',
            type: 'fill',
            source: 'area',
            paint: { 'fill-color': '#FFC800', 'fill-opacity': 0.15 },
        });
        map.addLayer({
            id: 'area-line',
            type: 'line',
            source: 'area',
            paint: { 'line-color': '#E0A800', 'line-width': 2 },
        });
        setReady(true);
    }, []);

    useEffect(() => {
        const map = mapRef.current;
        if (!ready || !map) {
            return;
        }
        if (center) {
            markerRef.current ??= new Marker({ color: '#111111' });
            markerRef.current.setLngLat([center.lng, center.lat]).addTo(map);
        } else {
            markerRef.current?.remove();
        }
        const source = map.getSource<GeoJSONSource>('area');
        void source?.setData({
            type: 'FeatureCollection',
            features: center && radiusKm ? [circle(center, radiusKm)] : [],
        });
        if (center && radiusKm) {
            const c = circle(center, radiusKm).geometry.coordinates[0];
            const lngs = c.map((p) => p[0]);
            const lats = c.map((p) => p[1]);
            map.fitBounds(
                [
                    [Math.min(...lngs), Math.min(...lats)],
                    [Math.max(...lngs), Math.max(...lats)],
                ],
                { padding: 24, duration: 300 },
            );
        }
    }, [ready, center, radiusKm]);

    useEffect(
        () => () => {
            markerRef.current?.remove();
        },
        [],
    );

    return (
        <div className="relative h-80 overflow-hidden rounded-md border">
            <TaxiMap
                tilesUrl={tiles}
                center={initial}
                zoom={12}
                className="absolute inset-0 cursor-crosshair"
                onReady={onReady}
                onClick={onCenter}
            />
        </div>
    );
}

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
                            className="grid grid-cols-[110px_minmax(0,1fr)_minmax(0,1fr)] items-center gap-2 text-sm"
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

function SectionTitle({ children }: { children: React.ReactNode }) {
    return (
        <h3 className="border-b pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
            {children}
        </h3>
    );
}

// Pul kalitlari — MoneyInput; qolganlari son (chegaralar BranchController::SETTINGS bilan bir xil)
const MONEY_SETTINGS = ['drivers.min_balance', 'drivers.cancel_penalty'];
const settingLimits: Record<
    string,
    { min: number; max?: number; step?: number }
> = {
    'drivers.commission_percent': { min: 0, max: 100, step: 0.1 },
    'drivers.max_cancels_per_shift': { min: 0, max: 100 },
    'drivers.block_minutes': { min: 0, max: 1440 },
    'dispatch.step_seconds': { min: 5, max: 120 },
    'dispatch.direct_offer_seconds': { min: 5, max: 120 },
    'dispatch.low_rating_threshold': { min: 0, max: 100 },
    'pricing.surge_coeff': { min: 1, max: 5, step: 0.05 },
    'pricing.surge_from_hour': { min: 0, max: 23 },
    'pricing.surge_to_hour': { min: 0, max: 24 },
};

const settingLabels: Record<string, string> = {
    'drivers.commission_percent': 'Driver commission, %',
    'drivers.min_balance': "Minimum driver balance, so'm",
    'drivers.cancel_penalty': "Cancellation penalty, so'm",
    'drivers.max_cancels_per_shift': 'Cancels before a temporary block',
    'drivers.block_minutes': 'Block duration, minutes',
    'dispatch.step_seconds': 'Seconds before widening the search',
    'dispatch.direct_offer_seconds': 'Seconds to answer an assigned order',
    'dispatch.low_rating_threshold':
        'Rating below which the nearest radius is skipped',
    'pricing.surge_coeff': 'Peak-hour price coefficient (1 — off)',
    'pricing.surge_from_hour': 'Peak hours from (hour)',
    'pricing.surge_to_hour': 'Peak hours to (hour; same as from — all day)',
};

function BranchDialog({
    branch,
    regions,
    defaults,
    tiles,
    onClose,
}: {
    branch: Branch | 'new';
    regions: { id: number; name: string }[];
    tiles: string;
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
        // Hudud viloyatning o'zi bo'lsa — shahar/tuman maydoni bo'sh
        region_id:
            existing?.region_id &&
            existing.region_id !== existing.parent_region_id
                ? existing.region_id
                : ('' as number | ''),
        center_lat: existing?.center?.lat ?? (null as number | null),
        center_lng: existing?.center?.lng ?? (null as number | null),
        service_radius_km: existing?.service_radius_km ?? ('' as number | ''),
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
            className="sm:max-w-6xl"
            processing={form.processing}
            onSubmit={() => {
                // Shahar/tuman tanlanmasa — butun viloyat
                form.transform((data) => ({
                    ...data,
                    region_id: data.region_id || parent,
                }));
                if (existing) {
                    form.put(BranchController.update.url(existing.id), {
                        onSuccess: onClose,
                    });
                } else {
                    form.post(BranchController.store.url(), {
                        onSuccess: onClose,
                    });
                }
            }}
        >
            <div className="grid items-start gap-6 lg:grid-cols-3">
                <section className="grid content-start gap-3">
                    <SectionTitle>{t('Main')}</SectionTitle>
                    <div className="grid gap-1.5">
                        <Label htmlFor="name">{t('Name')}</Label>
                        <Input
                            id="name"
                            maxLength={100}
                            autoComplete="off"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="service_name">
                            {t('Service name (IVR greeting)')}
                        </Label>
                        <Input
                            id="service_name"
                            maxLength={100}
                            autoComplete="off"
                            placeholder="Ibrat taksi"
                            value={form.data.service_name}
                            onChange={(e) =>
                                form.setData('service_name', e.target.value)
                            }
                        />
                        <InputError message={errors.service_name} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="greeting">{t('Greeting text')}</Label>
                        <Input
                            id="greeting"
                            placeholder={t(
                                'Assalomu alaykum, … xizmatiga xush kelibsiz',
                            )}
                            maxLength={500}
                            value={form.data.greeting_text}
                            onChange={(e) =>
                                form.setData('greeting_text', e.target.value)
                            }
                        />
                        <InputError message={errors.greeting_text} />
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
                </section>
                <section className="grid content-start gap-3">
                    <SectionTitle>
                        {t('Location and service radius')}
                    </SectionTitle>
                    <div className="grid grid-cols-2 items-start gap-2">
                        <div className="grid gap-1.5">
                            <Label>{t('Region')}</Label>
                            <select
                                className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                value={parent}
                                onChange={(e) => {
                                    setParent(Number(e.target.value) || '');
                                    // Boshqa viloyatning shahri saqlanib qolmasin
                                    form.setData('region_id', '');
                                }}
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
                            'The service area is filled from the boundary of the chosen city or district; if none is chosen — the whole region.',
                        )}
                    </p>
                    <div className="grid gap-1.5">
                        <AreaPicker
                            tiles={tiles}
                            center={
                                form.data.center_lat !== null &&
                                form.data.center_lng !== null
                                    ? {
                                          lat: form.data.center_lat,
                                          lng: form.data.center_lng,
                                      }
                                    : null
                            }
                            radiusKm={
                                Number(form.data.service_radius_km) || null
                            }
                            onCenter={(p) =>
                                form.setData((data) => ({
                                    ...data,
                                    center_lat: Number(p.lat.toFixed(6)),
                                    center_lng: Number(p.lng.toFixed(6)),
                                }))
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Click on the map to set the branch center. With a radius, the service area is this circle instead of the city boundary.',
                            )}
                        </p>
                        <InputError message={errors.center_lat} />
                        <div className="grid grid-cols-[minmax(0,1fr)_140px] items-center gap-2">
                            <Label htmlFor="radius" className="font-normal">
                                {t('Service radius, km')}
                            </Label>
                            <Input
                                id="radius"
                                type="number"
                                inputMode="decimal"
                                min={0.5}
                                max={300}
                                step={0.5}
                                placeholder="—"
                                value={form.data.service_radius_km}
                                onChange={(e) =>
                                    form.setData(
                                        'service_radius_km',
                                        e.target.value === ''
                                            ? ''
                                            : Number(e.target.value),
                                    )
                                }
                            />
                        </div>
                        <InputError message={errors.service_radius_km} />
                    </div>
                </section>
                <section className="grid content-start gap-3">
                    <SectionTitle>{t('Branch settings')}</SectionTitle>
                    <div className="grid gap-2">
                        <p className="text-xs text-muted-foreground">
                            {t('Leave empty to use the company-wide value.')}
                        </p>
                        {Object.entries(settingLabels).map(([key, label]) => {
                            const field = key.replaceAll('.', '__');
                            const limits = settingLimits[key];
                            const setValue = (value: string) =>
                                form.setData('settings', {
                                    ...form.data.settings,
                                    [field]: value,
                                });

                            return (
                                <div
                                    key={key}
                                    className="grid grid-cols-[minmax(0,1fr)_120px] items-center gap-x-2"
                                >
                                    <Label
                                        htmlFor={field}
                                        className="font-normal"
                                    >
                                        {t(label)}
                                    </Label>
                                    {MONEY_SETTINGS.includes(key) ? (
                                        <MoneyInput
                                            id={field}
                                            currency={false}
                                            placeholder={formatNumber(
                                                Number(defaults[key] ?? 0),
                                            )}
                                            value={form.data.settings[field]}
                                            onChange={(v) =>
                                                setValue(
                                                    v === null ? '' : String(v),
                                                )
                                            }
                                        />
                                    ) : (
                                        <Input
                                            id={field}
                                            type="number"
                                            inputMode={
                                                limits?.step
                                                    ? 'decimal'
                                                    : 'numeric'
                                            }
                                            min={limits?.min}
                                            max={limits?.max}
                                            step={limits?.step ?? 1}
                                            placeholder={String(
                                                defaults[key] ?? '',
                                            )}
                                            value={form.data.settings[field]}
                                            onChange={(e) =>
                                                setValue(e.target.value)
                                            }
                                        />
                                    )}
                                    <InputError
                                        className="col-span-2"
                                        message={errors[`settings.${field}`]}
                                    />
                                </div>
                            );
                        })}
                    </div>
                </section>
            </div>
        </EditDialog>
    );
}

export default function Branches({
    branches,
    regions,
    defaults,
    tiles,
}: {
    branches: Branch[];
    regions: { id: number; name: string }[];
    defaults: Record<string, number>;
    tiles: string;
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
                    tiles={tiles}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

Branches.layout = { breadcrumbs: [{ title: 'Branches', href: index() }] };
