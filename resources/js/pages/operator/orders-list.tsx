import { router } from '@inertiajs/react';
import { Ban, Pencil, RefreshCw, UserCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Operator/OrderController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/hooks/use-trans';
import { orderStatusColor, orderStatusLabel } from '@/lib/order-status';
import { cn } from '@/lib/utils';
import type { MapDriver, Order, TariffClass } from '@/types/operator';
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
export function isFresh(driver: MapDriver, ttlSeconds: number): boolean {
    return (
        !!driver.last_seen_at &&
        Date.now() - new Date(driver.last_seen_at).getTime() <=
            ttlSeconds * 1000
    );
}

/** Uzoq qidirilayotgan buyurtma (daqiqa) — sariq bilan ajratiladi */
const SLOW_SEARCH_SECONDS = 120;

/** Hech kim olmagan yangi buyurtma paydo bo'lganda qisqa signal */
function beep() {
    try {
        const ctx = new AudioContext();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.frequency.value = 880;
        gain.gain.value = 0.15;
        osc.connect(gain).connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.35);
        osc.onended = () => void ctx.close();
    } catch {
        // Brauzer ovozni bloklagan bo'lishi mumkin — vizual belgi baribir bor
    }
}

/** Buyurtma yaratilganidan beri o'tgan vaqt (mm:ss) — operator kutish vaqtini ko'radi */
function useElapsed(since: string): string {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const id = setInterval(() => setNow(Date.now()), 1000);

        return () => clearInterval(id);
    }, []);

    const seconds = Math.max(
        0,
        Math.floor((now - new Date(since).getTime()) / 1000),
    );

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

function OrderRow({
    order,
    drivers,
    tariffClasses,
    locationTtlSeconds,
    selected,
    onSelect,
}: {
    order: Order;
    drivers: MapDriver[];
    tariffClasses: TariffClass[];
    locationTtlSeconds: number;
    selected: boolean;
    onSelect: () => void;
}) {
    const { t } = useTrans();
    const elapsed = useElapsed(order.created_at);
    const [assigning, setAssigning] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [editing, setEditing] = useState(false);
    const pickup =
        order.pickup.lat !== null && order.pickup.lng !== null
            ? { lat: order.pickup.lat, lng: order.pickup.lng }
            : null;
    const freeDrivers = drivers
        .filter(
            (d) =>
                d.status === 'free' &&
                d.lat !== null &&
                d.lng !== null &&
                isFresh(d, locationTtlSeconds),
        )
        .map((d) => ({
            ...d,
            distance: pickup
                ? distanceMeters(pickup, { lat: d.lat!, lng: d.lng! })
                : null,
        }))
        .sort((a, b) => (a.distance ?? 0) - (b.distance ?? 0));

    const exhausted =
        order.status === 'searching' && !!order.search_exhausted_at;
    const slow =
        order.status === 'searching' &&
        !exhausted &&
        !!order.searching_at &&
        Date.now() - new Date(order.searching_at).getTime() >
            SLOW_SEARCH_SECONDS * 1000;
    const waiting = order.status === 'new' || order.status === 'searching';

    return (
        <li
            className={cn(
                'cursor-pointer rounded-lg border p-2.5 text-sm transition-colors',
                selected ? 'border-primary bg-accent' : 'hover:bg-accent/50',
                exhausted && 'border-red-500 bg-red-500/10',
                slow && 'border-amber-500',
            )}
            onClick={onSelect}
        >
            <div className="flex items-center justify-between gap-2">
                <span className="font-medium">#{order.id}</span>
                <Badge
                    className={cn('text-white', orderStatusColor[order.status])}
                >
                    {t(orderStatusLabel[order.status])}
                </Badge>
                {order.status === 'new' && order.scheduled_at ? (
                    <span
                        className="ml-auto text-xs font-medium text-amber-600"
                        title={t('Pre-order')}
                    >
                        ⏰{' '}
                        {new Date(order.scheduled_at).toLocaleString([], {
                            day: '2-digit',
                            month: '2-digit',
                            hour: '2-digit',
                            minute: '2-digit',
                        })}
                    </span>
                ) : (
                    <span className="ml-auto font-mono text-xs text-muted-foreground">
                        {elapsed}
                    </span>
                )}
            </div>
            {exhausted && (
                <div className="mt-1 text-xs font-medium text-red-600">
                    {t(
                        'Nobody took the order — assign a driver, add an extra or call the client.',
                    )}
                </div>
            )}
            <div className="mt-1 truncate">{order.pickup.text}</div>
            {order.dropoff?.text && (
                <div className="truncate text-xs text-muted-foreground">
                    → {order.dropoff.text}
                </div>
            )}
            <div className="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
                <span>{order.client?.phone}</span>
                <span>· {order.tariff_class?.name}</span>
                {order.price_estimate && (
                    <span>
                        · ~{order.price_estimate.toLocaleString('ru-RU')}
                    </span>
                )}
                {order.price_bonus ? (
                    <span className="font-medium text-emerald-600">
                        +{order.price_bonus.toLocaleString('ru-RU')}
                    </span>
                ) : null}
            </div>
            {order.driver && (
                <div className="mt-1 text-xs">
                    🚕 {order.driver.callsign} · {order.vehicle?.color}{' '}
                    {order.vehicle?.model} {order.vehicle?.plate_number}
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
                                title={t(
                                    'Send to all drivers again from the smallest radius',
                                )}
                                onClick={() =>
                                    router.post(
                                        OrderController.redispatch.url(
                                            order.id,
                                        ),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <RefreshCw /> {t('Re-dispatch')}
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setEditing(true)}
                        >
                            <Pencil /> {t('Edit')}
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setCancelling(true)}
                        >
                            <Ban /> {t('Cancel')}
                        </Button>
                    </div>
                )}
            {cancelling && (
                <CancelOrderDialog
                    order={order}
                    open={cancelling}
                    onOpenChange={setCancelling}
                />
            )}
            {editing && (
                <EditOrderDialog
                    order={order}
                    tariffClasses={tariffClasses}
                    open={editing}
                    onOpenChange={setEditing}
                />
            )}

            {assigning && (
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
                                className="w-full rounded px-2 py-1 text-left text-xs hover:bg-background"
                                onClick={() =>
                                    router.post(
                                        OrderController.assign.url(order.id),
                                        { driver_id: driver.id },
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                setAssigning(false),
                                        },
                                    )
                                }
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
            )}
        </li>
    );
}

export function OrdersList({
    orders,
    drivers,
    tariffClasses,
    locationTtlSeconds,
    selectedId,
    onSelect,
}: {
    orders: Order[];
    drivers: MapDriver[];
    tariffClasses: TariffClass[];
    locationTtlSeconds: number;
    selectedId: number | null;
    onSelect: (id: number) => void;
}) {
    const { t } = useTrans();
    const alerted = useRef<Set<number>>(new Set());

    // Har "hech kim olmadi" buyurtma uchun bir marta signal
    useEffect(() => {
        let fresh = false;
        for (const order of orders) {
            if (
                order.status === 'searching' &&
                order.search_exhausted_at &&
                !alerted.current.has(order.id)
            ) {
                alerted.current.add(order.id);
                fresh = true;
            }
        }
        if (fresh) {
            beep();
        }
    }, [orders]);

    if (orders.length === 0) {
        return (
            <p className="p-4 text-center text-sm text-muted-foreground">
                {t('No active orders')}
            </p>
        );
    }

    return (
        <ul className="grid gap-2">
            {orders.map((order) => (
                <OrderRow
                    key={order.id}
                    order={order}
                    drivers={drivers}
                    tariffClasses={tariffClasses}
                    locationTtlSeconds={locationTtlSeconds}
                    selected={selectedId === order.id}
                    onSelect={() => onSelect(order.id)}
                />
            ))}
        </ul>
    );
}
