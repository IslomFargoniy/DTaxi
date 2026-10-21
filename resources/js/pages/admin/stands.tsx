import { Head, router, useForm } from '@inertiajs/react';
import type { GeoJSONSource, Map as MapLibreMap } from 'maplibre-gl';
import { Plus, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import StandController from '@/actions/App/Http/Controllers/Admin/StandController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { TaxiMap } from '@/components/map/taxi-map';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTrans } from '@/hooks/use-trans';
import { cn } from '@/lib/utils';
import { index } from '@/routes/admin/stands';

type Stand = {
    id: number;
    branch_id: number;
    name: string;
    lat: number;
    lng: number;
    radius_m: number;
    is_active: boolean;
    queue: number;
};

type Branch = {
    id: number;
    name: string;
    center: { lat: number; lng: number } | null;
};

/** Radius doirasi (metr) — 48 burchakli ko'pburchak */
function circle(lat: number, lng: number, radius: number): GeoJSON.Polygon {
    const points: [number, number][] = [];
    for (let i = 0; i <= 48; i++) {
        const angle = (i / 48) * 2 * Math.PI;
        points.push([
            lng +
                (radius * Math.sin(angle)) /
                    (111320 * Math.cos((lat * Math.PI) / 180)),
            lat + (radius * Math.cos(angle)) / 110540,
        ]);
    }

    return { type: 'Polygon', coordinates: [points] };
}

export default function Stands({
    stands,
    branches,
    tiles,
}: {
    stands: Stand[];
    branches: Branch[];
    tiles: string;
}) {
    const { t } = useTrans();
    const mapRef = useRef<MapLibreMap | null>(null);
    const [editing, setEditing] = useState<Stand | 'new' | null>(null);
    const existing = editing && editing !== 'new' ? editing : null;
    const form = useForm({
        branch_id: branches[0]?.id ?? 0,
        name: '',
        lat: null as number | null,
        lng: null as number | null,
        radius_m: 200,
        is_active: true,
    });

    const open = (stand: Stand | 'new') => {
        setEditing(stand);
        form.clearErrors();
        form.setData(
            stand === 'new'
                ? {
                      branch_id: branches[0]?.id ?? 0,
                      name: '',
                      lat: null,
                      lng: null,
                      radius_m: 200,
                      is_active: true,
                  }
                : {
                      branch_id: stand.branch_id,
                      name: stand.name,
                      lat: stand.lat,
                      lng: stand.lng,
                      radius_m: stand.radius_m,
                      is_active: stand.is_active,
                  },
        );
        if (stand !== 'new') {
            mapRef.current?.easeTo({
                center: [stand.lng, stand.lat],
                zoom: 16,
            });
        }
    };

    // Xaritadagi doiralar: saqlanganlar + tahrirlanayotgani
    const draw = useCallback(() => {
        const source = mapRef.current?.getSource('stands') as
            | GeoJSONSource
            | undefined;
        const features: GeoJSON.Feature[] = stands
            .filter((s) => s.id !== existing?.id)
            .map((s) => ({
                type: 'Feature',
                geometry: circle(s.lat, s.lng, s.radius_m),
                properties: { editing: 0 },
            }));
        if (editing && form.data.lat !== null && form.data.lng !== null) {
            features.push({
                type: 'Feature',
                geometry: circle(
                    form.data.lat,
                    form.data.lng,
                    form.data.radius_m,
                ),
                properties: { editing: 1 },
            });
        }
        void source?.setData({ type: 'FeatureCollection', features });
    }, [
        stands,
        existing?.id,
        editing,
        form.data.lat,
        form.data.lng,
        form.data.radius_m,
    ]);

    useEffect(() => {
        draw();
    }, [draw]);

    const onReady = (map: MapLibreMap) => {
        mapRef.current = map;
        map.addSource('stands', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] },
        });
        map.addLayer({
            id: 'stands-fill',
            type: 'fill',
            source: 'stands',
            paint: {
                'fill-color': [
                    'case',
                    ['==', ['get', 'editing'], 1],
                    '#2563eb',
                    '#f59e0b',
                ],
                'fill-opacity': 0.25,
            },
        });
        map.addLayer({
            id: 'stands-line',
            type: 'line',
            source: 'stands',
            paint: { 'line-color': '#2563eb', 'line-width': 1.5 },
        });
        draw();
    };

    const center = branches[0]?.center ?? { lat: 40.3864, lng: 71.7864 };

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
        };
        if (existing) {
            form.put(StandController.update.url(existing.id), options);
        } else {
            form.post(StandController.store.url(), options);
        }
    };

    return (
        <>
            <Head title={t('Taxi stands')} />
            <div className="grid h-[calc(100svh-4rem)] gap-4 p-4 lg:grid-cols-[360px_1fr]">
                <div className="grid content-start gap-3 overflow-auto">
                    <div className="flex items-start justify-between gap-2">
                        <Heading
                            title="Taxi stands"
                            description="Airport, station, bazaar: free drivers here wait in turn, orders from here go to the first in line"
                        />
                        <Button size="sm" onClick={() => open('new')}>
                            <Plus /> {t('Add')}
                        </Button>
                    </div>

                    {editing && (
                        <form
                            className="grid gap-3 rounded-lg border p-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                submit();
                            }}
                        >
                            {branches.length > 1 && (
                                <select
                                    className="h-9 rounded-md border bg-background px-2 text-sm"
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
                            )}
                            <div className="grid gap-1.5">
                                <Label htmlFor="name">{t('Name')}</Label>
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                />
                                <InputError message={form.errors.name} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="radius_m">
                                    {t('Radius, m')}
                                </Label>
                                <Input
                                    id="radius_m"
                                    type="number"
                                    min={30}
                                    max={2000}
                                    value={form.data.radius_m}
                                    onChange={(e) =>
                                        form.setData(
                                            'radius_m',
                                            Number(e.target.value),
                                        )
                                    }
                                />
                            </div>
                            <p
                                className={cn(
                                    'text-xs',
                                    form.data.lat === null
                                        ? 'text-amber-600 dark:text-amber-400'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {form.data.lat === null
                                    ? t('Click the map to set the place')
                                    : `${form.data.lat.toFixed(5)}, ${form.data.lng?.toFixed(5)}`}
                            </p>
                            <InputError message={form.errors.lat} />
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={form.data.is_active}
                                    onCheckedChange={(v) =>
                                        form.setData('is_active', v === true)
                                    }
                                />
                                {t('Active')}
                            </label>
                            <div className="flex gap-2">
                                <Button
                                    className="flex-1"
                                    disabled={
                                        form.processing ||
                                        form.data.lat === null
                                    }
                                >
                                    {t('Save')}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setEditing(null)}
                                >
                                    {t('Back')}
                                </Button>
                                {existing && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        title={t('Delete')}
                                        onClick={() =>
                                            window.confirm(t('Delete?')) &&
                                            router.delete(
                                                StandController.destroy.url(
                                                    existing.id,
                                                ),
                                                {
                                                    onSuccess: () =>
                                                        setEditing(null),
                                                },
                                            )
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                )}
                            </div>
                        </form>
                    )}

                    <ul className="grid gap-1.5">
                        {stands.length === 0 && (
                            <li className="text-sm text-muted-foreground">
                                {t('No stands yet')}
                            </li>
                        )}
                        {stands.map((stand) => (
                            <li key={stand.id}>
                                <button
                                    type="button"
                                    className={cn(
                                        'flex w-full items-center justify-between rounded-md border px-3 py-2 text-left text-sm hover:bg-accent',
                                        existing?.id === stand.id &&
                                            'border-primary',
                                    )}
                                    onClick={() => open(stand)}
                                >
                                    <span>
                                        {stand.name}
                                        <span className="ml-2 text-xs text-muted-foreground">
                                            {stand.radius_m} m
                                        </span>
                                    </span>
                                    <span className="flex gap-1">
                                        {!stand.is_active && (
                                            <Badge variant="secondary">
                                                {t('Inactive')}
                                            </Badge>
                                        )}
                                        <Badge variant="outline">
                                            🚕 {stand.queue}
                                        </Badge>
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
                <div className="relative min-h-80 overflow-hidden rounded-xl border">
                    <TaxiMap
                        tilesUrl={tiles}
                        center={center}
                        className="absolute inset-0"
                        onReady={onReady}
                        onClick={(point) => {
                            if (editing) {
                                form.setData((data) => ({
                                    ...data,
                                    lat: point.lat,
                                    lng: point.lng,
                                }));
                            }
                        }}
                    />
                </div>
            </div>
        </>
    );
}

Stands.layout = { breadcrumbs: [{ title: 'Taxi stands', href: index() }] };
