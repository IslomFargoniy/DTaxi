<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Driver;
use App\Models\Order;
use App\Services\Dispatch\Dispatcher;
use App\Services\Orders\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Haydovchi: efirdan qabul qilish va buyurtma bosqichlari (reja §8.4).
 */
class OrderController extends Controller
{
    public function __construct(
        private Dispatcher $dispatcher,
        private OrderService $orders,
    ) {}

    public function accept(Request $request, int $order): JsonResponse
    {
        $driver = $this->driver($request);
        $model = $this->order($order, $driver);

        if (! $this->dispatcher->accept($model, $driver)) {
            return response()->json(['message' => __('The order was taken by another driver.')], 409);
        }

        return $this->respond($model->refresh());
    }

    public function reject(Request $request, int $order): JsonResponse
    {
        $driver = $this->driver($request);
        $this->dispatcher->reject($this->order($order, $driver), $driver);

        return response()->json(['ok' => true]);
    }

    public function arrive(Request $request, int $order): JsonResponse
    {
        return $this->transition($request, $order, fn (Order $o, Driver $d) => $this->orders->arrive($o, $d));
    }

    public function start(Request $request, int $order): JsonResponse
    {
        return $this->transition($request, $order, fn (Order $o, Driver $d) => $this->orders->start($o, $d));
    }

    /**
     * Taksometr natijasi: shahar ichidagi va tashqarisidagi metrlar (qaror #33).
     */
    public function complete(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'inside_m' => ['required', 'integer', 'min:0', 'max:1000000'],
            'outside_m' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        return $this->transition($request, $order, fn (Order $o, Driver $d) => $this->orders->complete($o, $d, $data['inside_m'], $data['outside_m'] ?? 0));
    }

    public function cancel(Request $request, int $order): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->transition($request, $order, fn (Order $o, Driver $d) => $this->orders->cancelByDriver($o, $d, $data['reason']));
    }

    /**
     * @param  callable(Order, Driver): mixed  $action
     */
    private function transition(Request $request, int $orderId, callable $action): JsonResponse
    {
        $driver = $this->driver($request);
        $order = Order::query()->withoutGlobalScopes()->where('driver_id', $driver->id)->findOrFail($orderId);

        try {
            $action($order, $driver);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->respond($order->refresh());
    }

    private function respond(Order $order): JsonResponse
    {
        return response()->json(['order' => (new OrderResource($order->load(['client', 'vehicle', 'tariffClass', 'delivery'])))->resolve()]);
    }

    /**
     * Haydovchi faqat o'ziga taklif qilingan buyurtmani ko'ra oladi.
     */
    private function order(int $id, Driver $driver): Order
    {
        return Order::query()->withoutGlobalScopes()
            ->whereKey($id)
            ->whereHas('offers', fn ($q) => $q->where('driver_id', $driver->id))
            ->firstOrFail();
    }

    private function driver(Request $request): Driver
    {
        return Driver::query()->withoutGlobalScopes()->where('user_id', $request->user()?->id)->firstOrFail();
    }
}
