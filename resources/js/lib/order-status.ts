import type { OrderStatus } from '@/types/operator';

export const orderStatusLabel: Record<OrderStatus, string> = {
    new: 'New',
    searching: 'Searching',
    assigned: 'Driver assigned',
    arrived: 'Driver arrived',
    in_progress: 'On the way',
    completed: 'Completed',
    cancelled_by_client: 'Cancelled by client',
    cancelled_by_operator: 'Cancelled',
    cancelled_by_driver: 'Cancelled by driver',
    no_driver_found: 'No driver found',
};

export const orderStatusColor: Record<OrderStatus, string> = {
    new: 'bg-slate-500',
    searching: 'bg-amber-500',
    assigned: 'bg-blue-600',
    arrived: 'bg-violet-600',
    in_progress: 'bg-emerald-600',
    completed: 'bg-emerald-800',
    cancelled_by_client: 'bg-zinc-500',
    cancelled_by_operator: 'bg-zinc-500',
    cancelled_by_driver: 'bg-zinc-500',
    no_driver_found: 'bg-red-600',
};
