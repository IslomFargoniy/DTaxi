import { router } from '@inertiajs/react';
import { Ban, UserCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Operator/OrderController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/hooks/use-trans';
import { orderStatusColor, orderStatusLabel } from '@/lib/order-status';
import { cn } from '@/lib/utils';
import type { MapDriver, Order } from '@/types/operator';

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
    selected,
    onSelect,
}: {
    order: Order;
    drivers: MapDriver[];
    selected: boolean;
    onSelect: () => void;
}) {
    const { t } = useTrans();
    const elapsed = useElapsed(order.created_at);
    const [assigning, setAssigning] = useState(false);
    const freeDrivers = drivers.filter((d) => d.status === 'free');

    const cancel = () => {
        const reason = window.prompt(t('Cancellation reason'));
        if (reason) {
            router.post(
                OrderController.cancel.url(order.id),
                { reason, by_client: true },
                { preserveScroll: true },
            );
        }
    };

    return (
        <li
            className={cn(
                'cursor-pointer rounded-lg border p-2.5 text-sm transition-colors',
                selected ? 'border-primary bg-accent' : 'hover:bg-accent/50',
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
                <span className="ml-auto font-mono text-xs text-muted-foreground">
                    {elapsed}
                </span>
            </div>
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
            </div>
            {order.driver && (
                <div className="mt-1 text-xs">
                    🚕 {order.driver.callsign} · {order.vehicle?.color}{' '}
                    {order.vehicle?.model} {order.vehicle?.plate_number}
                </div>
            )}

            {selected && ['new', 'searching'].includes(order.status) && (
                <div
                    className="mt-2 flex flex-wrap gap-1.5"
                    onClick={(e) => e.stopPropagation()}
                >
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setAssigning(!assigning)}
                    >
                        <UserCheck /> {t('Assign')}
                    </Button>
                    <Button size="sm" variant="outline" onClick={cancel}>
                        <Ban /> {t('Cancel')}
                    </Button>
                </div>
            )}
            {selected && ['assigned', 'arrived'].includes(order.status) && (
                <div className="mt-2" onClick={(e) => e.stopPropagation()}>
                    <Button size="sm" variant="outline" onClick={cancel}>
                        <Ban /> {t('Cancel')}
                    </Button>
                </div>
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
    selectedId,
    onSelect,
}: {
    orders: Order[];
    drivers: MapDriver[];
    selectedId: number | null;
    onSelect: (id: number) => void;
}) {
    const { t } = useTrans();

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
                    selected={selectedId === order.id}
                    onSelect={() => onSelect(order.id)}
                />
            ))}
        </ul>
    );
}
