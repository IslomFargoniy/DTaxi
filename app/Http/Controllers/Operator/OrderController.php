<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operator\StoreOrderRequest;
use App\Models\Call;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use App\Services\Dispatch\Dispatcher;
use App\Services\Orders\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use InvalidArgumentException;

class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function store(StoreOrderRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $call = $request->filled('call_id') ? Call::query()->find($request->integer('call_id')) : null;
        $order = $this->orders->create([...$request->orderData(), 'phone_number_id' => $call?->phone_number_id], $user);
        $call?->forceFill(['order_id' => $order->id])->save();

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

        try {
            $request->boolean('by_client')
                ? $this->orders->cancelByClient($order, $user, $data['reason'])
                : $this->orders->cancelByOperator($order, $user, $data['reason'], $request->boolean('no_driver_found'));
        } catch (InvalidArgumentException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back();
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'comment' => ['sometimes', 'nullable', 'string', 'max:500'],
            'pickup_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:pickup_lng'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:pickup_lat'],
            'dropoff_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'dropoff_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:dropoff_lng'],
            'dropoff_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:dropoff_lat'],
            'tariff_class_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tariff_classes', 'id')->where('branch_id', $order->branch_id)],
            'price_bonus' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        /** @var User $user */
        $user = $request->user();

        try {
            $this->orders->update($order, $data, $user);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Order #:id updated.', ['id' => $order->id])]);
        } catch (InvalidArgumentException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back();
    }

    public function redispatch(Request $request, Order $order): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $this->orders->redispatch($order, $user);
        } catch (InvalidArgumentException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

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

        try {
            $dispatcher->offerDirect($order, Driver::query()->whereKey($data['driver_id'])->firstOrFail(), $user);
        } catch (InvalidArgumentException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back();
    }
}
