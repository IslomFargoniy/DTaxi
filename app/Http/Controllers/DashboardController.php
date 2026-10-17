<?php

namespace App\Http\Controllers;

use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bosh sahifa: admin uchun bugungi ko'rsatkichlar; operator va kassir o'z ish joyiga yo'naltiriladi.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->can('reports.view')) {
            return match (true) {
                $user->can('orders.manage') => to_route('operator.index'),
                $user->can('balance.topup') => to_route('cashier.index'),
                default => Inertia::render('dashboard', ['stats' => null]),
            };
        }

        $today = now()->startOfDay();
        $orders = Order::query()->where('created_at', '>=', $today);

        $completed = (clone $orders)->where('status', OrderStatus::Completed->value);
        $cancelled = (clone $orders)->whereIn('status', [
            OrderStatus::CancelledByClient->value, OrderStatus::CancelledByOperator->value, OrderStatus::NoDriverFound->value,
        ]);

        return Inertia::render('dashboard', [
            'stats' => [
                'orders_today' => (clone $orders)->count(),
                'completed_today' => (clone $completed)->count(),
                'cancelled_today' => $cancelled->count(),
                'active' => Order::query()->active()->count(),
                'revenue_today' => (int) (clone $completed)->sum('price_final'),
                'commission_today' => (int) (clone $completed)->sum('commission'),
                // O'rtacha: buyurtmadan haydovchi tayinlanguncha (soniya)
                'avg_assign_seconds' => (int) round((float) (clone $orders)->whereNotNull('assigned_at')
                    ->value(DB::raw('AVG(EXTRACT(EPOCH FROM (assigned_at - created_at)))'))),
                'drivers_free' => Driver::query()->where('status', DriverStatus::Free->value)->count(),
                'drivers_busy' => Driver::query()->where('status', DriverStatus::Busy->value)->count(),
                'drivers_pending' => Driver::query()->where('is_approved', false)->count(),
                'by_hour' => (clone $orders)
                    ->selectRaw('EXTRACT(HOUR FROM created_at)::int AS hour, COUNT(*) AS total')
                    ->groupBy('hour')->orderBy('hour')->pluck('total', 'hour'),
            ],
        ]);
    }
}
