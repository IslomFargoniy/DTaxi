import { Head, router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { Marker } from 'maplibre-gl';
import type {
    ExpressionSpecification,
    GeoJSONSource,
    Map as MapLibreMap,
} from 'maplibre-gl';
import { useCallback, useEffect, useRef, useState } from 'react';
import { TaxiMap } from '@/components/map/taxi-map';
import { globalSip } from '@/components/webphone/sip-state';
import { useResyncOnReconnect } from '@/hooks/use-resync-on-reconnect';
import { useServerNow } from '@/hooks/use-server-now';
import { useTrans } from '@/hooks/use-trans';
import { markGpsEvent } from '@/lib/gps-health';
import { serverNow, syncServerClock } from '@/lib/server-clock';
import { cn } from '@/lib/utils';
import { index } from '@/routes/operator';
import type { Branch, MapDriver, Order, TariffClass } from '@/types/operator';
import { addCarImages, bearing, distanceMeters } from '@/lib/car-marker';
import { OrderForm } from './order-form';
import type { PickTarget } from './order-form';
import { isFresh, OrdersList } from './orders-list';
import type { PickedPlace } from './place-picker';
import type { CallCard } from '@/components/webphone/webphone';

type Props = {
    branches: Branch[];
    tariffClasses: TariffClass[];
    orders: Order[];
    drivers: MapDriver[];
    map: { tiles: string; center: { lat: number; lng: number } | null };
    locationTtlSeconds: number;
    /** Server vaqti (ms) — GPS eskirganini shu bo'yicha hisoblash */
    serverTime?: number;
};

const ACTIVE: Order['status'][] = [
    'new',
    'searching',
    'assigned',
    'arrived',
    'in_progress',
];

function areaBounds(
    area: NonNullable<Branch['area']>,
): [[number, number], [number, number]] {
    const points = (
        area.type === 'Polygon' ? area.coordinates : area.coordinates.flat()
    ).flat();
    const lngs = points.map((p) => p[0]);
    const lats = points.map((p) => p[1]);

    return [
        [Math.min(...lngs), Math.min(...lats)],
        [Math.max(...lngs), Math.max(...lats)],
    ];
}

// Tanlangan filial hududi ajralib turadi, qolganlari xira
function highlightBranch(map: MapLibreMap, branchId: number): void {
    const selected: ExpressionSpecification = ['==', ['get', 'id'], branchId];
    map.setPaintProperty('branch-areas-fill', 'fill-opacity', [
        'case',
        selected,
        0.1,
        0.03,
    ]);
    map.setPaintProperty('branch-areas-line', 'line-opacity', [
        'case',
        selected,
        1,
        0.35,
    ]);
}

/** Xarita buyurtma formasida tanlangan filialga o'tadi (hudud bo'lsa — butun hudud, aks holda markaz) */
function focusBranch(
    map: MapLibreMap,
    branch: Branch | undefined,
    animate: boolean,
): void {
    if (!branch) {
        return;
    }
    highlightBranch(map, branch.id);
    const duration = animate ? 600 : 0;
    if (branch.area) {
        map.fitBounds(areaBounds(branch.area), { padding: 24, duration });
    } else if (branch.center) {
        map.easeTo({
            center: [branch.center.lng, branch.center.lat],
            zoom: 12,
            duration,
        });
    }
}

/**
 * Mashina belgisi yo'nalishi: telefon yuborgani, bo'lmasa oldingi joydan yangisiga (10 m dan ko'p siljigan bo'lsa),
 * aks holda oldingi burchak — to'xtab turgan mashina aylanib ketmasin.
 */
function headingOf(
    prev: MapDriver | undefined,
    next: MapDriver,
): number | null {
    if (next.heading !== null && next.heading !== undefined) {
        return next.heading;
    }
    if (
        prev?.lat != null &&
        prev.lng != null &&
        next.lat !== null &&
        next.lng !== null
    ) {
        const from = { lat: prev.lat, lng: prev.lng };
        const to = { lat: next.lat, lng: next.lng };
        if (distanceMeters(from, to) > 10) {
            return bearing(from, to);
        }
    }

    return prev?.heading ?? null;
}

function driversGeoJson(
    drivers: MapDriver[],
    ttlSeconds: number,
    now: number,
): GeoJSON.FeatureCollection {
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
                    heading: d.heading ?? 0,
                    // GPS eskirgan — kulrang (bo'sh deb sanalmaydi)
                    status: isFresh(d, ttlSeconds, now) ? d.status : 'stale',
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
        // Hodisada filial yo'q — kanal filiali qo'shiladi (tayinlash ro'yxati filial bo'yicha)
        (driver) => onDriver({ ...driver, branch_id: branchId }),
        [onDriver, branchId],
    );

    return null;
}

/** Xarita ostidagi bo'sh/band sanog'i — GPS eskirgani vaqt o'tishi bilan ham hisobga olinsin */
function DriversLegend({
    drivers,
    locationTtlSeconds,
}: {
    drivers: MapDriver[];
    locationTtlSeconds: number;
}) {
    const { t } = useTrans();
    const now = useServerNow(5000);

    return (
        <div className="absolute bottom-2 left-2 flex gap-3 rounded-md bg-background/90 px-2 py-1 text-xs shadow">
            <span>
                🟢 {t('Free')}:{' '}
                {
                    drivers.filter(
                        (d) =>
                            d.status === 'free' &&
                            isFresh(d, locationTtlSeconds, now),
                    ).length
                }
            </span>
            <span>
                🔴 {t('Busy')}:{' '}
                {drivers.filter((d) => d.status === 'busy').length}
            </span>
        </div>
    );
}

/** Kechikkan reload yangiroq real vaqt holatini ustidan yozmasin: kim yangiroq bo'lsa o'sha qoladi */
function newerOrSame(
    local: string | null | undefined,
    server: string | null | undefined,
): boolean {
    return !!local && (!server || Date.parse(local) > Date.parse(server));
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
    locationTtlSeconds,
    serverTime,
}: Props) {
    const { t } = useTrans();
    // Render paytida: birinchi chizishdayoq haydovchi ranglari server vaqti bo'yicha
    syncServerClock(serverTime);
    const [orders, setOrders] = useState<Order[]>(initialOrders);
    const [drivers, setDrivers] = useState<MapDriver[]>(initialDrivers);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [picking, setPicking] = useState<PickTarget>(null);
    const [mapClick, setMapClick] = useState<{
        lat: number;
        lng: number;
        seq: number;
        target?: 'pickup' | 'dropoff';
    } | null>(null);
    const [incoming, setIncoming] = useState<CallCard | null>(null);
    // Buyurtma formasida tanlangan filial — xarita shu filialga o'tadi
    const [branchId, setBranchId] = useState<number | undefined>(
        branches[0]?.id,
    );
    const selectedBranch = branches.find((b) => b.id === branchId);
    const branchRef = useRef(selectedBranch);
    branchRef.current = selectedBranch;

    // Kiruvchi qo'ng'iroq kartochkasi webfondan (layout'da) — buyurtma formasi mijoz raqami bilan ochiladi.
    // Qo'ng'iroq boshqa sahifada kelgan bo'lsa — sahifa ochilganda global holatdan olinadi
    useEffect(() => {
        const pending = globalSip.takePendingCard();
        if (pending) {
            setIncoming(pending);
        }
        const onCard = (e: Event) => {
            globalSip.takePendingCard();
            setIncoming((e as CustomEvent<CallCard>).detail);
        };
        window.addEventListener('dtaxi:call-card', onCard);

        return () => window.removeEventListener('dtaxi:call-card', onCard);
    }, []);
    const mapRef = useRef<MapLibreMap | null>(null);
    const placeMarkers = useRef<Marker[]>([]);
    // Ro'yxatdan chiqqan (yakunlangan) buyurtmalar: kechikkan eski hodisa ularni qaytarmasin
    const finished = useRef<Map<number, number>>(new Map());

    // Server ro'yxati (sahifa/qisman qayta yuklash) real vaqt holati bilan birlashtiriladi:
    // so'rov paytida kelgan yangiroq hodisa eski javob bilan almashtirilmaydi, yakunlangani qaytmaydi
    const snapshotAt = useRef(serverTime);
    snapshotAt.current = serverTime;
    useEffect(() => {
        setOrders((list) => {
            const local = new Map(list.map((o) => [o.id, o]));
            const merged: Order[] = [];
            const seen = new Set<number>();
            for (const order of initialOrders) {
                seen.add(order.id);
                const closedAt = finished.current.get(order.id);
                if (
                    closedAt !== undefined &&
                    (!order.updated_at ||
                        Date.parse(order.updated_at) <= closedAt)
                ) {
                    continue;
                }
                const mine = local.get(order.id);
                merged.push(
                    mine && newerOrSame(mine.updated_at, order.updated_at)
                        ? mine
                        : order,
                );
            }
            // Javob tayyorlangandan keyin paydo bo'lgan (faqat hodisadan ma'lum) buyurtmalar ham qoladi
            const since = snapshotAt.current;
            for (const order of list) {
                if (
                    !seen.has(order.id) &&
                    since !== undefined &&
                    order.updated_at &&
                    Date.parse(order.updated_at) > since
                ) {
                    merged.push(order);
                }
            }

            return merged;
        });
    }, [initialOrders]);
    useEffect(() => {
        setDrivers((list) => {
            const local = new Map(list.map((d) => [d.id, d]));

            return initialDrivers.map((driver) => {
                const mine = local.get(driver.id);

                return mine &&
                    newerOrSame(mine.last_seen_at, driver.last_seen_at)
                    ? { ...driver, ...mine }
                    : driver;
            });
        });
    }, [initialDrivers]);

    // Uzilish paytida kelmagan buyurtma holatlari va haydovchi joylashuvlari serverdan olinadi
    useResyncOnReconnect(
        useCallback(
            () => router.reload({ only: ['orders', 'drivers', 'serverTime'] }),
            [],
        ),
    );

    // Filial markazi kiritilmagan bo'lsa — xarita fayli qamragan hudud
    const center = branches[0]?.center ?? mapConfig.center;

    const onOrder = useCallback(
        (order: Order) =>
            setOrders((list) => {
                const at = order.updated_at
                    ? Date.parse(order.updated_at)
                    : null;
                const index = list.findIndex((o) => o.id === order.id);
                const prev = index >= 0 ? list[index] : null;
                const closedAt = finished.current.get(order.id);
                if (
                    !prev &&
                    closedAt !== undefined &&
                    (at === null || at <= closedAt)
                ) {
                    return list;
                }
                // Tartibsiz kelgan eski hodisa yangi holat ustidan yozilmaydi
                if (
                    prev?.updated_at &&
                    at !== null &&
                    at < Date.parse(prev.updated_at)
                ) {
                    return list;
                }
                if (!ACTIVE.includes(order.status)) {
                    finished.current.set(order.id, at ?? Date.now());

                    return prev ? list.filter((o) => o.id !== order.id) : list;
                }
                if (!prev) {
                    // Yangi buyurtma — ro'yxatdagi joyini OrdersList tanlangan tartibga ko'ra belgilaydi
                    return [order, ...list];
                }
                // Yangilangan qator joyida qoladi (ro'yxat sakramasin)
                const next = [...list];
                next[index] = order;

                return next;
            }),
        [],
    );
    // GPS hodisalari to'planib 250 ms da bir marta chiziladi: yuzlab haydovchida har hodisaga
    // butun sahifa qayta chizilsa panel qotardi
    const pendingDrivers = useRef<Map<number, MapDriver>>(new Map());
    const flushTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    useEffect(
        () => () => {
            if (flushTimer.current !== null) {
                clearTimeout(flushTimer.current);
            }
        },
        [],
    );
    const onDriver = useCallback((driver: MapDriver) => {
        markGpsEvent();
        const pending = pendingDrivers.current;
        pending.set(driver.id, { ...pending.get(driver.id), ...driver });
        if (flushTimer.current !== null) {
            return;
        }
        flushTimer.current = setTimeout(() => {
            flushTimer.current = null;
            const batch = pendingDrivers.current;
            pendingDrivers.current = new Map();
            setDrivers((list) => {
                const byId = new Map(list.map((d) => [d.id, d]));
                for (const d of batch.values()) {
                    const prev = byId.get(d.id);
                    byId.set(d.id, {
                        ...prev,
                        ...d,
                        heading: headingOf(prev, d),
                    });
                }

                return [...byId.values()];
            });
        }, 250);
    }, []);

    // Haydovchilar qatlami; rang (GPS eskirgani) yangi hodisa kelmasa ham vaqt o'tishi bilan yangilanadi
    // (sahifani qayta chizmasdan — faqat xarita manbasi)
    const driversRef = useRef(drivers);
    useEffect(() => {
        driversRef.current = drivers;
        const draw = () => {
            const source = mapRef.current?.getSource('drivers') as
                | GeoJSONSource
                | undefined;
            void source?.setData(
                driversGeoJson(
                    driversRef.current,
                    locationTtlSeconds,
                    serverNow(),
                ),
            );
        };
        draw();
        const id = setInterval(draw, 5000);

        return () => clearInterval(id);
    }, [drivers, locationTtlSeconds]);

    const onReady = useCallback(
        (map: MapLibreMap) => {
            mapRef.current = map;
            // Filiallar xizmat hududi — haydovchi va buyurtma qatlamlari ostida
            const areas = branches.flatMap((b) =>
                b.area
                    ? [
                          {
                              type: 'Feature' as const,
                              properties: { id: b.id },
                              geometry: b.area,
                          },
                      ]
                    : [],
            );
            map.addSource('branch-areas', {
                type: 'geojson',
                data: { type: 'FeatureCollection', features: areas },
            });
            map.addLayer({
                id: 'branch-areas-fill',
                type: 'fill',
                source: 'branch-areas',
                paint: { 'fill-color': '#FFC800', 'fill-opacity': 0.08 },
            });
            map.addLayer({
                id: 'branch-areas-line',
                type: 'line',
                source: 'branch-areas',
                paint: {
                    'line-color': '#E0A800',
                    'line-width': 2,
                    'line-dasharray': [2, 2],
                },
            });
            focusBranch(map, branchRef.current, false);
            map.addSource('drivers', {
                type: 'geojson',
                data: driversGeoJson(drivers, locationTtlSeconds, serverNow()),
            });
            // Haydovchi — yo'nalishi bo'yicha burilgan mashina, rangi holati (bo'sh / band / GPS eskirgan)
            void addCarImages(map).then(() => {
                if (map.getLayer('drivers')) {
                    return;
                }
                map.addLayer({
                    id: 'drivers',
                    type: 'symbol',
                    source: 'drivers',
                    layout: {
                        'icon-image': ['concat', 'car-', ['get', 'status']],
                        'icon-rotate': ['get', 'heading'],
                        'icon-rotation-alignment': 'map',
                        // ~32 px uzunlikda: haydovchilar ko'p bo'lsa ham bir-birini yopmasin
                        'icon-size': 0.6,
                        'icon-allow-overlap': true,
                        'icon-ignore-placement': true,
                    },
                });
                map.addLayer({
                    id: 'drivers-label',
                    type: 'symbol',
                    source: 'drivers',
                    layout: {
                        'text-field': ['get', 'callsign'],
                        'text-size': 11,
                        'text-offset': [0, 1.8],
                        'text-font': ['Noto Sans Medium'],
                    },
                    paint: {
                        'text-halo-color': '#ffffff',
                        'text-halo-width': 1.5,
                    },
                });
            });
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [],
    );

    // Filial almashtirilganda (qo'lda yoki kiruvchi qo'ng'iroq raqami bo'yicha) xarita o'sha filialga o'tadi
    useEffect(() => {
        const map = mapRef.current;
        if (map?.getLayer('branch-areas-fill')) {
            focusBranch(map, selectedBranch, true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [branchId]);

    // Formadagi A va B nuqtalar
    const onPlacesChange = useCallback(
        (pickup: PickedPlace, dropoff: PickedPlace) => {
            const map = mapRef.current;
            placeMarkers.current.forEach((m) => m.remove());
            placeMarkers.current = [];

            if (!map) {
                return;
            }
            for (const [place, color, target] of [
                [pickup, '#2563eb', 'pickup'],
                [dropoff, '#9333ea', 'dropoff'],
            ] as const) {
                if (place.lat !== null && place.lng !== null) {
                    // Nuqtani surib aniqlashtirish mumkin
                    const marker = new Marker({ color, draggable: true })
                        .setLngLat([place.lng, place.lat])
                        .addTo(map);
                    marker.on('dragend', () => {
                        const { lat, lng } = marker.getLngLat();
                        setMapClick({ lat, lng, seq: Date.now(), target });
                    });
                    placeMarkers.current.push(marker);
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

    // Tanlangan buyurtmani xaritada ko'rsatish — faqat tanlov o'zgarganda
    // (har buyurtma hodisasida xarita operator qo'lidan sakrab ketmasin)
    const ordersRef = useRef(orders);
    useEffect(() => {
        ordersRef.current = orders;
    }, [orders]);
    useEffect(() => {
        const order = ordersRef.current.find((o) => o.id === selectedId);
        if (order?.pickup.lat && order.pickup.lng) {
            mapRef.current?.easeTo({
                center: [order.pickup.lng, order.pickup.lat],
                zoom: 15,
            });
        }
    }, [selectedId]);

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
                    <h2 className="mb-2 text-sm font-semibold">
                        {t('Active orders')} ({orders.length})
                    </h2>
                    <OrdersList
                        orders={orders}
                        drivers={drivers}
                        branches={branches}
                        tariffClasses={tariffClasses}
                        locationTtlSeconds={locationTtlSeconds}
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
                    {!picking &&
                        selectedBranch &&
                        !selectedBranch.area &&
                        !selectedBranch.center && (
                            <div className="absolute top-2 left-1/2 max-w-[90%] -translate-x-1/2 rounded-md bg-amber-100 px-3 py-1 text-center text-xs text-amber-900 shadow dark:bg-amber-950 dark:text-amber-200">
                                {t(
                                    'This branch has no service area yet — choose its city or district on the Branches page.',
                                )}
                            </div>
                        )}
                    <DriversLegend
                        drivers={drivers}
                        locationTtlSeconds={locationTtlSeconds}
                    />
                </section>

                <section className="order-1 overflow-auto lg:order-3">
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
                        onShowOrder={setSelectedId}
                        onBranchChange={setBranchId}
                    />
                </section>
            </div>
        </>
    );
}

OperatorIndex.layout = {
    breadcrumbs: [{ title: 'Operator', href: index() }],
};
