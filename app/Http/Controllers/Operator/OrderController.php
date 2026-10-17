<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operator\StoreOrderRequest;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use App\Services\Dispatch\Dispatcher;
use App\Services\Orders\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function store(StoreOrderRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $order = $this->orders->create($request->orderData(), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Order #:id created.', ['id' => $order->id])]);

        return back();
    }

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'by_client' => ['boolean'],
            'no_driver_found' => ['boolean'],
        ]);
        /** @var User $user */
        $user = $request->user();

        $request->boolean('by_client')
            ? $this->orders->cancelByClient($order, $user, $data['reason'])
            : $this->orders->cancelByOperator($order, $user, $data['reason'], $request->boolean('no_driver_found'));

        return back();
    }

    /**
     * Qo'lda tayinlash: haydovchiga to'g'ridan-to'g'ri taklif (qaror #29).
     */
    public function assign(Request $request, Order $order, Dispatcher $dispatcher): RedirectResponse
    {
        $data = $request->validate([
            'driver_id' => ['required', 'integer', Rule::exists('drivers', 'id')->where('branch_id', $order->branch_id)],
        ]);
        /** @var User $user */
        $user = $request->user();

        $dispatcher->offerDirect($order, Driver::query()->whereKey($data['driver_id'])->firstOrFail(), $user);

        return back();
    }
}
