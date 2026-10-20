import { Head, usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { Marker } from 'maplibre-gl';
import type { GeoJSONSource, Map as MapLibreMap } from 'maplibre-gl';
import { useCallback, useEffect, useRef, useState } from 'react';
import { TaxiMap } from '@/components/map/taxi-map';
import { useTrans } from '@/hooks/use-trans';
import { cn } from '@/lib/utils';
import { index } from '@/routes/operator';
import type { Branch, MapDriver, Order, TariffClass } from '@/types/operator';
import { ChatPanel } from './chat-panel';
import { OrderForm } from './order-form';
import type { PickTarget } from './order-form';
import { OrdersList } from './orders-list';
import type { PickedPlace } from './place-picker';
import { Softphone } from './softphone';
import type { CallCard } from './softphone';

type Props = {
    branches: Branch[];
    tariffClasses: TariffClass[];
    orders: Order[];
    drivers: MapDriver[];
    map: { tiles: string; center: { lat: number; lng: number } };
};

const ACTIVE: Order['status'][] = [
    'new',
    'searching',
    'assigned',
    'arrived',
    'in_progress',
];

function driversGeoJson(drivers: MapDriver[]): GeoJSON.FeatureCollection {
    return {
        type: 'FeatureCollection',
        features: drivers
            .filter(
                (d) =>
                    d.lat !== null && d.lng !== null && d.status !== 'offline',
            )
            .map((d) => ({
                type: 'Feature',
                geometry: {
                    type: 'Point',
                    coordinates: [d.lng as number, d.lat as number],
                },
                properties: {
                    id: d.id,
                    callsign: d.callsign,
                    status: d.status,
                },
            })),
    };
}

/**
 * Filial kanali (Reverb): buyurtmalar holati va haydovchilar joylashuvi.
 */
function BranchChannel({
    branchId,
    onOrder,
    onDriver,
}: {
    branchId: number;
    onOrder: (order: Order) => void;
    onDriver: (driver: MapDriver) => void;
}) {
    useEcho<{ order: Order }>(
        `branch.${branchId}`,
        'OrderUpdated',
        (e) => onOrder(e.order),
        [onOrder],
    );
    useEcho<MapDriver>(
        `branch.${branchId}`,
        'DriverLocationUpdated',
        onDriver,
        [onDriver],
    );

    return null;
}

/**
 * Operator paneli: chapda faol buyurtmalar, o'rtada xarita, o'ngda yangi buyurtma (reja §8.1).
 * Buyurtmalar va haydovchilar Reverb orqali jonli yangilanadi.
 */
export default function OperatorIndex({
    branches,
    tariffClasses,
    orders: initialOrders,
    drivers: initialDrivers,
    map: mapConfig,
}: Props) {
    const { t } = useTrans();
    const { auth } = usePage<{ auth: { user: { id: number } } }>().props;
    const [orders, setOrders] = useState<Order[]>(initialOrders);
    const [drivers, setDrivers] = useState<MapDriver[]>(initialDrivers);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [picking, setPicking] = useState<PickTarget>(null);
    const [mapClick, setMapClick] = useState<{
        lat: number;
        lng: number;
        seq: number;
    } | null>(null);
    const [incoming, setIncoming] = useState<CallCard | null>(null);
    const mapRef = useRef<MapLibreMap | null>(null);
    const placeMarkers = useRef<Marker[]>([]);

    useEffect(() => setOrders(initialOrders), [initialOrders]);

    const center = branches[0]?.center ?? mapConfig.center;

    const onOrder = useCallback(
        (order: Order) =>
            setOrders((list) => {
                const rest = list.filter((o) => o.id !== order.id);

                return ACTIVE.includes(order.status) ? [order, ...rest] : rest;
            }),
        [],
    );
    const onDriver = useCallback(
        (driver: MapDriver) =>
            setDrivers((list) => [
                ...list.filter((d) => d.id !== driver.id),
                { ...list.find((d) => d.id === driver.id), ...driver },
            ]),
        [],
    );

    // Haydovchilar qatlami
    useEffect(() => {
        const source = mapRef.current?.getSource('drivers') as
            | GeoJSONSource
            | undefined;
        void source?.setData(driversGeoJson(drivers));
    }, [drivers]);

    const onReady = useCallback(
        (map: MapLibreMap) => {
            mapRef.current = map;
            map.addSource('drivers', {
                type: 'geojson',
                data: driversGeoJson(drivers),
            });
            map.addLayer({
                id: 'drivers',
                type: 'circle',
                source: 'drivers',
                paint: {
                    'circle-radius': 7,
                    'circle-stroke-width': 2,
                    'circle-stroke-color': '#ffffff',
                    'circle-color': [
                        'match',
                        ['get', 'status'],
                        'free',
                        '#16a34a',
                        'busy',
                        '#dc2626',
                        '#6b7280',
                    ],
                },
            });
            map.addLayer({
                id: 'drivers-label',
                type: 'symbol',
                source: 'drivers',
                layout: {
                    'text-field': ['get', 'callsign'],
                    'text-size': 11,
                    'text-offset': [0, 1.3],
                    'text-font': ['Noto Sans Medium'],
                },
                paint: { 'text-halo-color': '#ffffff', 'text-halo-width': 1.5 },
            });
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [],
    );

    // Formadagi A va B nuqtalar
    const onPlacesChange = useCallback(
        (pickup: PickedPlace, dropoff: PickedPlace) => {
            const map = mapRef.current;
            placeMarkers.current.forEach((m) => m.remove());
            placeMarkers.current = [];

            if (!map) {
                return;
            }
            for (const [place, color] of [
                [pickup, '#2563eb'],
                [dropoff, '#9333ea'],
            ] as const) {
                if (place.lat !== null && place.lng !== null) {
                    placeMarkers.current.push(
                        new Marker({ color })
                            .setLngLat([place.lng, place.lat])
                            .addTo(map),
                    );
                }
            }
            if (pickup.lat !== null && pickup.lng !== null) {
                map.easeTo({
                    center: [pickup.lng, pickup.lat],
                    zoom: Math.max(map.getZoom(), 15),
                });
            }
        },
        [],
    );

    // Tanlangan buyurtmani xaritada ko'rsatish
    useEffect(() => {
        const order = orders.find((o) => o.id === selectedId);
        if (order?.pickup.lat && order.pickup.lng) {
            mapRef.current?.easeTo({
                center: [order.pickup.lng, order.pickup.lat],
                zoom: 15,
            });
        }
    }, [selectedId, orders]);

    return (
        <>
            <Head title={t('Operator')} />
            {branches.map((branch) => (
                <BranchChannel
                    key={branch.id}
                    branchId={branch.id}
                    onOrder={onOrder}
                    onDriver={onDriver}
                />
            ))}
            <div className="grid h-[calc(100svh-4rem)] grid-cols-1 gap-3 p-3 lg:grid-cols-[320px_1fr_380px]">
                <section className="order-2 overflow-auto lg:order-1">
                    <div className="mb-2 flex items-center justify-between">
                        <h2 className="text-sm font-semibold">
                            {t('Active orders')} ({orders.length})
                        </h2>
                        <ChatPanel
                            branchIds={branches.map((b) => b.id)}
                            userId={auth.user.id}
                        />
                    </div>
                    <OrdersList
                        orders={orders}
                        drivers={drivers}
                        selectedId={selectedId}
                        onSelect={setSelectedId}
                    />
                </section>

                <section
                    className={cn(
                        'relative order-3 min-h-80 overflow-hidden rounded-xl border lg:order-2',
                        // Tanlash rejimida nishon kursor — bosish nuqta qo'yishini ko'rsatadi
                        picking &&
                            '[&_.maplibregl-canvas-container]:!cursor-crosshair',
                    )}
                >
                    <TaxiMap
                        tilesUrl={mapConfig.tiles}
                        center={center}
                        className="absolute inset-0"
                        onReady={onReady}
                        onClick={(point) =>
                            setMapClick({ ...point, seq: Date.now() })
                        }
                    />
                    {picking && (
                        <div className="absolute top-2 left-1/2 -translate-x-1/2 rounded-full bg-primary px-3 py-1 text-xs text-primary-foreground shadow">
                            {t('Click on the map to set the point')}
                        </div>
                    )}
                    <div className="absolute bottom-2 left-2 flex gap-3 rounded-md bg-background/90 px-2 py-1 text-xs shadow">
                        <span>
                            🟢 {t('Free')}:{' '}
                            {drivers.filter((d) => d.status === 'free').length}
                        </span>
                        <span>
                            🔴 {t('Busy')}:{' '}
                            {drivers.filter((d) => d.status === 'busy').length}
                        </span>
                    </div>
                </section>

                <section className="order-1 overflow-auto lg:order-3">
                    <div className="mb-3">
                        <Softphone userId={auth.user.id} onCard={setIncoming} />
                    </div>
                    <h2 className="mb-2 text-sm font-semibold">
                        {t('New order')}{' '}
                        <span className="font-normal text-muted-foreground">
                            (F2)
                        </span>
                    </h2>
                    <OrderForm
                        branches={branches}
                        tariffClasses={tariffClasses}
                        picking={picking}
                        setPicking={setPicking}
                        mapClick={mapClick}
                        onPlacesChange={onPlacesChange}
                        incoming={incoming}
                    />
                </section>
            </div>
        </>
    );
}

OperatorIndex.layout = {
    breadcrumbs: [{ title: 'Operator', href: index() }],
};
