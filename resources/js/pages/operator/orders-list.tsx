import { router, usePage } from '@inertiajs/react';
import {
    ArrowDownUp,
    Ban,
    Pencil,
    Phone,
    RefreshCw,
    UserCheck,
} from 'lucide-react';
import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { toast } from 'sonner';
import OrderController from '@/actions/App/Http/Controllers/Operator/OrderController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { globalSip } from '@/components/webphone/sip-state';
import { dial, useSip } from '@/components/webphone/use-sip';
import { useCan } from '@/hooks/use-can';
import { useServerNow } from '@/hooks/use-server-now';
import { useTrans } from '@/hooks/use-trans';
import { formatNumber, formatPhone, formatPlate } from '@/lib/format';
import { orderStatusColor, orderStatusLabel } from '@/lib/order-status';
import { serverNow } from '@/lib/server-clock';
import { cn } from '@/lib/utils';
import type { Branch, MapDriver, Order, TariffClass } from '@/types/operator';
import { CancelOrderDialog, EditOrderDialog } from './order-dialogs';

/** Ikki nuqta orasidagi masofa (metr) — tayinlash ro'yxatini yaqinidan tartiblash uchun */
function distanceMeters(
    a: { lat: number; lng: number },
    b: { lat: number; lng: number },
): number {
    const rad = Math.PI / 180;
    const dLat = (b.lat - a.lat) * rad;
    const dLng = (b.lng - a.lng) * rad;
    const h =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) ** 2;

    return 6371000 * 2 * Math.asin(Math.sqrt(h));
}

/** GPS'i yangi (TTL ichida) haydovchi — eskisi "bo'sh" deb hisoblanmaydi */
export function isFresh(
    driver: MapDriver,
    ttlSeconds: number,
    now: number = serverNow(),
): boolean {
    return (
        !!driver.last_seen_at &&
        now - new Date(driver.last_seen_at).getTime() <= ttlSeconds * 1000
    );
}

/** Uzoq qidirilayotgan buyurtma (daqiqa) — sariq bilan ajratiladi */
const SLOW_SEARCH_SECONDS = 120;

/** Oldindan buyurtma shuncha qolganda ham haydovchisiz bo'lsa — e'tibor talab qiladi */
const PREORDER_SOON_SECONDS = 30 * 60;

export type OrderFilter =
    | 'all'
    | 'searching'
    | 'assigned'
    | 'in_progress'
    | 'preorders'
    | 'problems';
export type OrderSort = 'newest' | 'waiting' | 'attention';

const FILTERS: { key: OrderFilter; label: string; title: string }[] = [
    { key: 'all', label: 'All', title: 'All active orders' },
    {
        key: 'searching',
        label: 'In search',
        title: 'New and searching for a driver',
    },
    {
        key: 'assigned',
        label: 'Assigned',
        title: 'Driver assigned or arrived',
    },
    { key: 'in_progress', label: 'On the way', title: 'Trip in progress' },
    { key: 'preorders', label: 'Pre-orders', title: 'Scheduled pre-orders' },
    {
        key: 'problems',
        label: 'Problems',
        title: 'Nobody took the order, long search or a pre-order due soon',
    },
];
const SORTS: { key: OrderSort; label: string }[] = [
    { key: 'newest', label: 'Newest first' },
    { key: 'waiting', label: 'Waiting longest' },
    { key: 'attention', label: 'Needs attention first' },
];

const isPreorder = (o: Order) => o.status === 'new' && !!o.scheduled_at;
const time = (iso: string | null | undefined) =>
    iso ? new Date(iso).getTime() : 0;

/**
 * Qanchalik e'tibor kerak: 3 — hech kim olmadi, 2 — uzoq qidirilmoqda,
 * 1 — oldindan buyurtma vaqti yaqin, haydovchi hali yo'q; 0 — hammasi joyida.
 */
export function attentionLevel(o: Order, now: number): number {
    if (o.status === 'searching' && o.search_exhausted_at) {
        return 3;
    }
    if (
        o.status === 'searching' &&
        o.searching_at &&
        now - time(o.searching_at) > SLOW_SEARCH_SECONDS * 1000
    ) {
        return 2;
    }
    if (
        isPreorder(o) &&
        time(o.scheduled_at) - now <= PREORDER_SOON_SECONDS * 1000
    ) {
        return 1;
    }

    return 0;
}

export function matchesFilter(
    filter: OrderFilter,
    o: Order,
    now: number,
): boolean {
    switch (filter) {
        case 'all':
            return true;
        case 'searching':
            return (
                o.status === 'searching' ||
                (o.status === 'new' && !isPreorder(o))
            );
        case 'assigned':
            return o.status === 'assigned' || o.status === 'arrived';
        case 'in_progress':
            return o.status === 'in_progress';
        case 'preorders':
            return isPreorder(o);
        case 'problems':
            return attentionLevel(o, now) > 0;
    }
}

/** Kutish boshlangan vaqt: oldindan buyurtmada — belgilangan vaqt (kelajakdagisi oxirida) */
const waitingSince = (o: Order) =>
    isPreorder(o) ? time(o.scheduled_at) : time(o.created_at);

export function compareOrders(
    sort: OrderSort,
    now: number,
): (a: Order, b: Order) => number {
    const newest = (a: Order, b: Order) =>
        time(b.created_at) - time(a.created_at) || b.id - a.id;
    const waiting = (a: Order, b: Order) =>
        waitingSince(a) - waitingSince(b) || a.id - b.id;

    switch (sort) {
        case 'newest':
            return newest;
        case 'waiting':
            return waiting;
        case 'attention':
            return (a, b) =>
                attentionLevel(b, now) - attentionLevel(a, now) ||
                waiting(a, b);
    }
}

/**
 * Barqaror tartib: mavjud qatorlar joyida qoladi (kursor ostida sakramaydi),
 * yo'qolganlari olib tashlanadi, yangilari tanlangan tartib bo'yicha o'z joyiga qo'yiladi.
 */
export function placeOrders(
    ids: number[],
    orders: Order[],
    compare: (a: Order, b: Order) => number,
): number[] {
    const byId = new Map(orders.map((o) => [o.id, o]));
    const next = ids.filter((id) => byId.has(id));
    const known = new Set(next);
    const added = orders.filter((o) => !known.has(o.id)).sort(compare);

    for (const order of added) {
        const at = next.findIndex(
            (id) => compare(order, byId.get(id) as Order) < 0,
        );
        next.splice(at === -1 ? next.length : at, 0, order.id);
    }

    return next;
}

const sortIds = (orders: Order[], sort: OrderSort) =>
    [...orders].sort(compareOrders(sort, serverNow())).map((o) => o.id);

type ViewPrefs = { filter: OrderFilter; sort: OrderSort };
const DEFAULT_VIEW: ViewPrefs = { filter: 'all', sort: 'newest' };
const viewKey = (userId: number | undefined) =>
    `operator-orders-view:${userId ?? 'guest'}`;

/** Operatorning tanlovi (filtr va tartib) — har operator uchun alohida */
function loadView(userId: number | undefined): ViewPrefs {
    try {
        const raw = localStorage.getItem(viewKey(userId));
        const saved = raw ? (JSON.parse(raw) as Partial<ViewPrefs>) : {};

        return {
            filter: FILTERS.some((f) => f.key === saved.filter)
                ? (saved.filter as OrderFilter)
                : DEFAULT_VIEW.filter,
            sort: SORTS.some((o) => o.key === saved.sort)
                ? (saved.sort as OrderSort)
                : DEFAULT_VIEW.sort,
        };
    } catch {
        return DEFAULT_VIEW;
    }
}

function saveView(userId: number | undefined, view: ViewPrefs) {
    try {
        localStorage.setItem(viewKey(userId), JSON.stringify(view));
    } catch {
        // Saqlab bo'lmasa (maxfiy oyna) — tanlov faqat shu sahifada amal qiladi
    }
}

/** Hech kim olmagan yangi buyurtma paydo bo'lganda qisqa signal */
function beep() {
    try {
        // Webfon bilan umumiy bitta kontekst: har signalga yangisi ochilsa, to'xtatilgan (suspended) kontekstda
        // onended kelmay yopilmay qolardi va brauzer kontekstlar chegarasiga yetardi
        const ctx = globalSip.getAudioContext();
        if (!ctx) {
            return;
        }
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.frequency.value = 880;
        gain.gain.value = 0.15;
        osc.connect(gain).connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.35);
    } catch {
        // Brauzer ovozni bloklagan bo'lishi mumkin — vizual belgi baribir bor
    }
}

/** Bir bosishda haydovchiga yoki mijozga qo'ng'iroq (webfon orqali) */
function CallButtons({ order }: { order: Order }) {
    const { t } = useTrans();
    const { can } = useCan();
    const sip = useSip();

    if (!can('calls.answer') || (!order.driver && !order.client?.phone)) {
        return null;
    }
    const ready = sip.status === 'connected' && sip.callState === 'idle';
    const why =
        sip.tabRole === 'follower'
            ? t('Phone is open in another tab')
            : sip.status !== 'connected'
              ? t('Phone is not connected')
              : sip.callState !== 'idle'
                ? t('A call is already in progress')
                : undefined;

    return (
        <>
            {order.driver && (
                <Button
                    size="sm"
                    variant="outline"
                    disabled={!ready}
                    title={
                        why ?? `${t('Call driver')} #${order.driver.callsign}`
                    }
                    onClick={() => dial(order.driver?.callsign)}
                >
                    <Phone /> {t('Call driver')}
                </Button>
            )}
            {order.client?.phone && (
                <Button
                    size="sm"
                    variant="outline"
                    disabled={!ready}
                    title={
                        why ??
                        `${t('Call client')} ${formatPhone(order.client.phone)}`
                    }
                    onClick={() => dial(order.client?.phone)}
                >
                    <Phone /> {t('Call client')}
                </Button>
            )}
        </>
    );
}

/** Buyurtma yaratilganidan beri o'tgan vaqt (mm:ss) — operator kutish vaqtini ko'radi.
 * Alohida komponent: har soniyada faqat shu matn qayta chiziladi, butun qator emas */
function Elapsed({ since }: { since: string }) {
    const now = useServerNow(1000);
    const seconds = Math.max(
        0,
        Math.floor((now - new Date(since).getTime()) / 1000),
    );

    return (
        <span className="ml-auto font-mono text-xs text-muted-foreground">
            {Math.floor(seconds / 60)}:{String(seconds % 60).padStart(2, '0')}
        </span>
    );
}

/** Tayinlash ro'yxati: faqat ochilganda hisoblanadi; buyurtma filiali, bo'sh va GPS'i yangi haydovchilar */
function AssignList({
    order,
    drivers,
    locationTtlSeconds,
    busy,
    onAssign,
}: {
    order: Order;
    drivers: MapDriver[];
    locationTtlSeconds: number;
    busy: boolean;
    onAssign: (driverId: number) => void;
}) {
    const { t } = useTrans();
    // GPS eskirgan haydovchi yangi hodisa kelmasa ham ro'yxatdan chiqsin
    const now = useServerNow(5000);
    const pickupLat = order.pickup.lat;
    const pickupLng = order.pickup.lng;
    const freeDrivers = useMemo(() => {
        const pickup =
            pickupLat !== null && pickupLng !== null
                ? { lat: pickupLat, lng: pickupLng }
                : null;

        return drivers
            .filter(
                (d) =>
                    d.branch_id === order.branch_id &&
                    d.status === 'free' &&
                    d.lat !== null &&
                    d.lng !== null &&
                    isFresh(d, locationTtlSeconds, now),
            )
            .map((d) => ({
                ...d,
                distance: pickup
                    ? distanceMeters(pickup, { lat: d.lat!, lng: d.lng! })
                    : null,
            }))
            .sort((a, b) => (a.distance ?? 0) - (b.distance ?? 0));
    }, [
        drivers,
        order.branch_id,
        pickupLat,
        pickupLng,
        locationTtlSeconds,
        now,
    ]);

    return (
        <ul
            className="mt-2 grid max-h-40 gap-1 overflow-auto"
            onClick={(e) => e.stopPropagation()}
        >
            {freeDrivers.length === 0 && (
                <li className="text-xs text-muted-foreground">
                    {t('No free drivers')}
                </li>
            )}
            {freeDrivers.map((driver) => (
                <li key={driver.id}>
                    <button
                        type="button"
                        disabled={busy}
                        className="w-full rounded px-2 py-1 text-left text-xs hover:bg-background disabled:opacity-50"
                        onClick={() => onAssign(driver.id)}
                    >
                        {driver.distance !== null && (
                            <span className="mr-1 font-mono text-muted-foreground">
                                {(driver.distance / 1000).toFixed(1)} km
                            </span>
                        )}
                        {driver.callsign} · {driver.vehicle}
                    </button>
                </li>
            ))}
        </ul>
    );
}

export type OrderDialog = { kind: 'cancel' | 'edit'; order: Order };

/** Server xatosi (validatsiya) — jim qolmasin, operatorga ko'rinsin */
function toastFirstError(errors: Record<string, string>) {
    const message = Object.values(errors)[0];
    if (message) {
        toast.error(message);
    }
}

const OrderRow = memo(function OrderRow({
    order,
    drivers,
    locationTtlSeconds,
    selected,
    attention,
    timeZone,
    onSelect,
    onDialog,
}: {
    order: Order;
    /** Faqat tanlangan qatorga beriladi — qolganlari GPS hodisalarida qayta chizilmaydi */
    drivers: MapDriver[];
    locationTtlSeconds: number;
    selected: boolean;
    /** attentionLevel() — ro'yxatda umumiy taymer bo'yicha hisoblanadi */
    attention: number;
    timeZone?: string;
    onSelect: (id: number) => void;
    onDialog: (dialog: OrderDialog) => void;
}) {
    const { t } = useTrans();
    const [assigning, setAssigning] = useState(false);
    // So'rov ketayotganda tugma qayta bosilmaydi (ikki marta tayinlash/qayta efir)
    const [busy, setBusy] = useState(false);

    const exhausted = attention === 3;
    const slow = attention === 2;
    const waiting = order.status === 'new' || order.status === 'searching';

    const redispatch = () => {
        if (busy) {
            return;
        }
        setBusy(true);
        router.post(
            OrderController.redispatch.url(order.id),
            {},
            {
                preserveScroll: true,
                onError: toastFirstError,
                onFinish: () => setBusy(false),
            },
        );
    };

    const assign = (driverId: number) => {
        if (busy) {
            return;
        }
        setBusy(true);
        router.post(
            OrderController.assign.url(order.id),
            { driver_id: driverId },
            {
                preserveScroll: true,
                onSuccess: () => setAssigning(false),
                onError: toastFirstError,
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <li
            className={cn(
                'cursor-pointer rounded-lg border p-2.5 text-sm transition-colors',
                selected ? 'border-primary bg-accent' : 'hover:bg-accent/50',
                exhausted && 'border-red-500 bg-red-500/10',
                slow && 'border-amber-500',
            )}
            onClick={() => onSelect(order.id)}
        >
            <div className="flex items-center justify-between gap-2">
                <span className="font-medium">#{order.id}</span>
                <Badge
                    className={cn('text-white', orderStatusColor[order.status])}
                >
                    {t(orderStatusLabel[order.status])}
                </Badge>
                {order.source === 'app' && (
                    <Badge
                        variant="outline"
                        title={t('Ordered from the client app')}
                    >
                        {t('App')}
                    </Badge>
                )}
                {order.status === 'new' && order.scheduled_at ? (
                    <span
                        className="ml-auto text-xs font-medium text-amber-600 dark:text-amber-400"
                        title={t('Pre-order')}
                    >
                        ⏰{' '}
                        {/* Filial vaqtida — operator kompyuteri boshqa mintaqada bo'lsa ham */}
                        {new Date(order.scheduled_at).toLocaleString([], {
                            day: '2-digit',
                            month: '2-digit',
                            hour: '2-digit',
                            minute: '2-digit',
                            timeZone,
                        })}
                    </span>
                ) : (
                    <Elapsed since={order.created_at} />
                )}
            </div>
            {exhausted && (
                <div className="mt-1 text-xs font-medium text-red-600 dark:text-red-400">
                    {t(
                        'Nobody took the order — assign a driver, add an extra or call the client.',
                    )}
                </div>
            )}
            <div className="mt-1 truncate">{order.pickup.text}</div>
            {order.stops?.map((stop, i) => (
                <div key={i} className="truncate text-xs text-muted-foreground">
                    ⋯ {stop.text}
                </div>
            ))}
            {order.dropoff?.text && (
                <div className="truncate text-xs text-muted-foreground">
                    → {order.dropoff.text}
                </div>
            )}
            <div className="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
                <span className="whitespace-nowrap">
                    {formatPhone(order.client?.phone)}
                </span>
                <span>· {order.tariff_class?.name}</span>
                {order.price_estimate && (
                    <span>· ~{formatNumber(order.price_estimate)}</span>
                )}
                {order.price_bonus ? (
                    <span className="font-medium text-emerald-600 dark:text-emerald-400">
                        +{formatNumber(order.price_bonus)}
                    </span>
                ) : null}
            </div>
            {order.driver && (
                <div className="mt-1 text-xs">
                    🚕 {order.driver.callsign} · {order.vehicle?.color}{' '}
                    {order.vehicle?.model}{' '}
                    {formatPlate(order.vehicle?.plate_number)}
                </div>
            )}

            {selected &&
                !['new', 'searching', 'assigned', 'arrived'].includes(
                    order.status,
                ) && (
                    <div
                        className="mt-2 flex flex-wrap gap-1.5"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <CallButtons order={order} />
                    </div>
                )}
            {selected &&
                ['new', 'searching', 'assigned', 'arrived'].includes(
                    order.status,
                ) && (
                    <div
                        className="mt-2 flex flex-wrap gap-1.5"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <CallButtons order={order} />
                        {waiting && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setAssigning(!assigning)}
                            >
                                <UserCheck /> {t('Assign')}
                            </Button>
                        )}
                        {order.status === 'searching' && (
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={busy}
                                title={t(
                                    'Send to all drivers again from the smallest radius',
                                )}
                                onClick={redispatch}
                            >
                                <RefreshCw /> {t('Re-dispatch')}
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => onDialog({ kind: 'edit', order })}
                        >
                            <Pencil /> {t('Edit')}
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => onDialog({ kind: 'cancel', order })}
                        >
                            <Ban /> {t('Cancel')}
                        </Button>
                    </div>
                )}

            {selected && waiting && assigning && (
                <AssignList
                    order={order}
                    drivers={drivers}
                    locationTtlSeconds={locationTtlSeconds}
                    busy={busy}
                    onAssign={assign}
                />
            )}
        </li>
    );
});

/** Tanlanmagan qatorlar uchun barqaror bo'sh ro'yxat (memo buzilmasin) */
const NO_DRIVERS: MapDriver[] = [];

export function OrdersList({
    orders,
    drivers,
    branches,
    tariffClasses,
    locationTtlSeconds,
    selectedId,
    onSelect,
}: {
    orders: Order[];
    drivers: MapDriver[];
    branches: Branch[];
    tariffClasses: TariffClass[];
    locationTtlSeconds: number;
    selectedId: number | null;
    onSelect: (id: number) => void;
}) {
    const { t } = useTrans();
    const userId = usePage<{ auth: { user: { id: number } | null } }>().props
        .auth.user?.id;
    // Umumiy taymer: "uzoq qidiruv" va filtr sanoqlari har 5 soniyada
    const now = useServerNow(5000);
    // Bekor/tahrirlash oynasi ro'yxat darajasida: buyurtma filtrdan yoki ro'yxatdan chiqsa ham oyna yopilmaydi
    const [dialog, setDialog] = useState<OrderDialog | null>(null);
    const closeDialog = useCallback((open: boolean) => {
        if (!open) {
            setDialog(null);
        }
    }, []);
    const timeZones = useMemo(
        () => new Map(branches.map((b) => [b.id, b.timezone])),
        [branches],
    );
    const [view, setView] = useState<ViewPrefs>(() => loadView(userId));
    // Ko'rsatish tartibi (id'lar): faqat operator tartibni o'zgartirganda qayta saralanadi
    const [ids, setIds] = useState<number[]>(() => sortIds(orders, view.sort));
    const [placedFrom, setPlacedFrom] = useState(orders);
    if (placedFrom !== orders) {
        setPlacedFrom(orders);
        setIds(placeOrders(ids, orders, compareOrders(view.sort, serverNow())));
    }
    const byId = new Map(orders.map((o) => [o.id, o]));
    const chipRefs = useRef<
        Partial<Record<OrderFilter, HTMLButtonElement | null>>
    >({});
    const counts = Object.fromEntries(
        FILTERS.map((f) => [
            f.key,
            orders.filter((o) => matchesFilter(f.key, o, now)).length,
        ]),
    ) as Record<OrderFilter, number>;

    const changeView = (next: ViewPrefs) => {
        if (next.sort !== view.sort) {
            setIds(sortIds(orders, next.sort));
        }
        setView(next);
        saveView(userId, next);
    };
    // Buyurtma → signal berilgan "hech kim olmadi" vaqti
    const alerted = useRef<Map<number, string>>(new Map());

    // Har "hech kim olmadi" holati uchun bir marta signal; qayta qidiruvdan keyin yana tugasa — yana signal
    useEffect(() => {
        let fresh = false;
        for (const order of orders) {
            if (
                order.status === 'searching' &&
                order.search_exhausted_at &&
                alerted.current.get(order.id) !== order.search_exhausted_at
            ) {
                alerted.current.set(order.id, order.search_exhausted_at);
                fresh = true;
            }
        }
        if (fresh) {
            beep();
        }
    }, [orders]);

    // Oynada buyurtmaning eng so'nggi holati; ro'yxatdan chiqqan bo'lsa — oxirgi ma'lum holati
    const dialogOrder = dialog
        ? (byId.get(dialog.order.id) ?? dialog.order)
        : null;
    const dialogs =
        dialog && dialogOrder ? (
            dialog.kind === 'cancel' ? (
                <CancelOrderDialog
                    key={`cancel-${dialogOrder.id}`}
                    order={dialogOrder}
                    open
                    onOpenChange={closeDialog}
                />
            ) : (
                <EditOrderDialog
                    key={`edit-${dialogOrder.id}`}
                    order={dialogOrder}
                    tariffClasses={tariffClasses}
                    open
                    onOpenChange={closeDialog}
                />
            )
        ) : null;

    if (orders.length === 0) {
        return (
            <>
                <p className="p-4 text-center text-sm text-muted-foreground">
                    {t('No active orders')}
                </p>
                {dialogs}
            </>
        );
    }

    const visible = ids
        .map((id) => byId.get(id))
        .filter((o): o is Order => !!o && matchesFilter(view.filter, o, now));

    // Klaviatura: chiplar orasida chap/o'ng strelka, ro'yxatda yuqori/past — oldingi/keyingi buyurtma
    const onChipKey = (e: KeyboardEvent<HTMLButtonElement>, i: number) => {
        const step =
            e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
        if (!step) {
            return;
        }
        e.preventDefault();
        const next = FILTERS[(i + step + FILTERS.length) % FILTERS.length];
        changeView({ ...view, filter: next.key });
        chipRefs.current[next.key]?.focus();
    };
    const onListKey = (e: KeyboardEvent<HTMLUListElement>) => {
        if (e.target !== e.currentTarget) {
            return;
        }
        const step = e.key === 'ArrowDown' ? 1 : e.key === 'ArrowUp' ? -1 : 0;
        if (!step || visible.length === 0) {
            return;
        }
        e.preventDefault();
        const at = visible.findIndex((o) => o.id === selectedId);
        const next =
            at === -1
                ? step > 0
                    ? 0
                    : visible.length - 1
                : Math.min(visible.length - 1, Math.max(0, at + step));
        onSelect(visible[next].id);
    };

    return (
        <div className="grid gap-2">
            <div
                role="toolbar"
                aria-label={t('Filter orders')}
                className="flex flex-wrap gap-1"
            >
                {FILTERS.map((f, i) => {
                    const active = view.filter === f.key;

                    return (
                        <button
                            key={f.key}
                            ref={(el) => {
                                chipRefs.current[f.key] = el;
                            }}
                            type="button"
                            aria-pressed={active}
                            tabIndex={active ? 0 : -1}
                            title={t(f.title)}
                            className={cn(
                                'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                active
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'hover:bg-accent',
                                !active &&
                                    f.key === 'problems' &&
                                    counts.problems > 0 &&
                                    'border-red-500 text-red-600 dark:text-red-400',
                            )}
                            onClick={() =>
                                changeView({ ...view, filter: f.key })
                            }
                            onKeyDown={(e) => onChipKey(e, i)}
                        >
                            {t(f.label)}
                            <span className="font-mono tabular-nums opacity-80">
                                {counts[f.key]}
                            </span>
                        </button>
                    );
                })}
            </div>
            <div className="flex items-center gap-1">
                <label
                    htmlFor="orders-sort"
                    className="text-xs text-muted-foreground"
                >
                    {t('Sort')}
                </label>
                <select
                    id="orders-sort"
                    className="h-7 min-w-0 flex-1 rounded-md border bg-transparent px-1 text-xs"
                    value={view.sort}
                    onChange={(e) =>
                        changeView({
                            ...view,
                            sort: e.target.value as OrderSort,
                        })
                    }
                >
                    {SORTS.map((o) => (
                        <option key={o.key} value={o.key}>
                            {t(o.label)}
                        </option>
                    ))}
                </select>
                <Button
                    size="icon"
                    variant="ghost"
                    className="size-7"
                    title={t('Re-sort the list now')}
                    aria-label={t('Re-sort the list now')}
                    onClick={() => setIds(sortIds(orders, view.sort))}
                >
                    <ArrowDownUp />
                </Button>
            </div>

            {visible.length === 0 ? (
                <p className="p-4 text-center text-sm text-muted-foreground">
                    {t('No orders match this filter')}
                </p>
            ) : (
                <ul
                    className="grid gap-2 rounded-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    tabIndex={0}
                    aria-label={t('Active orders')}
                    onKeyDown={onListKey}
                >
                    {visible.map((order) => (
                        <OrderRow
                            key={order.id}
                            order={order}
                            drivers={
                                selectedId === order.id ? drivers : NO_DRIVERS
                            }
                            locationTtlSeconds={locationTtlSeconds}
                            selected={selectedId === order.id}
                            attention={attentionLevel(order, now)}
                            timeZone={timeZones.get(order.branch_id)}
                            onSelect={onSelect}
                            onDialog={setDialog}
                        />
                    ))}
                </ul>
            )}
            {dialogs}
        </div>
    );
}
