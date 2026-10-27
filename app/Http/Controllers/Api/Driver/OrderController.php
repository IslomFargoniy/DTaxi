<?php

namespace App\Http\Controllers\Api\Driver;

use App\Enums\OfferResponse;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Driver;
use App\Models\Landmark;
use App\Models\Order;
use App\Services\Dispatch\Dispatcher;
use App\Services\Orders\OrderService;
use App\Services\Orders\RideTrack;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $at = $this->position($request);

        return $this->transition($request, $order, fn (Order $o, Driver $d) => $this->orders->start($o, $d, $at));
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
        $at = $this->position($request);

        return $this->transition($request, $order, fn (Order $o, Driver $d) => $this->orders->complete($o, $d, $data['inside_m'], $data['outside_m'] ?? 0, $at));
    }

    public function cancel(Request $request, int $order): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->transition($request, $order, fn (Order $o, Driver $d) => $this->orders->cancelByDriver($o, $d, $data['reason']));
    }

    /**
     * Buyurtmalar tarixi: yakunlangan va tayinlangandan keyin bekor qilinganlar, eng yangisi birinchi.
     * Haydovchi o'zi voz kechgan buyurtma ham ko'rinadi (lekin keyingi haydovchining narxi va yo'li emas).
     */
    public function history(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $finished = [OrderStatus::Completed, OrderStatus::CancelledByClient, OrderStatus::CancelledByOperator, OrderStatus::CancelledByDriver];

        // OR + EXISTS katta bazada butun jadvalni o'qirdi; UNION'ning ikki qismi ham o'z indeksidan foydalanadi
        $ids = DB::table('orders')->select('id')->where('driver_id', $driver->id)
            ->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, $finished))
            ->union(DB::table('order_offers')->select('order_id')->where('driver_id', $driver->id)->where('response', OfferResponse::Cancelled->value));

        $page = Order::query()->withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->simplePaginate(20);

        return response()->json([
            'items' => collect($page->items())->map(fn (Order $o) => $this->historyItem($o, $driver))->values(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    /**
     * Tarixdagi buyurtmaning haqiqiy yo'li (xaritada chizish uchun). GPS nuqtalari o'chirilgan bo'lsa
     * (drivers.locations_retention_days) — bo'sh, ilova boshlanish va tugash nuqtasini to'g'ri chiziq bilan ulaydi.
     */
    public function track(Request $request, int $order, RideTrack $track): JsonResponse
    {
        $driver = $this->driver($request);
        $model = Order::query()->withoutGlobalScopes()->where('driver_id', $driver->id)->findOrFail($order);
        $end = $model->completed_at ?? $model->cancelled_at ?? now();

        return response()->json([
            'points' => $model->started_at ? $track->polyline($driver->id, $model->started_at, $end) : [],
            'start' => $this->latLng($model->start_location),
            'finish' => $this->latLng($model->finish_location),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function historyItem(Order $order, Driver $driver): array
    {
        $mine = $order->driver_id === $driver->id;
        // Voz kechgan buyurtma: haydovchi uchun bu "siz bekor qildingiz", keyingi taqdiri unga tegishli emas
        $status = $mine ? $order->status : OrderStatus::CancelledByDriver;
        $start = $mine ? $order->start_location : null;
        $finish = $mine ? $order->finish_location : null;

        return [
            'id' => $order->id,
            'status' => $status->value,
            'created_at' => $order->created_at->toIso8601String(),
            'started_at' => $mine ? $order->started_at?->toIso8601String() : null,
            'completed_at' => $mine ? $order->completed_at?->toIso8601String() : null,
            'cancelled_at' => $mine ? $order->cancelled_at?->toIso8601String() : null,
            'pickup_text' => $order->pickup_text,
            'pickup' => $this->latLng($order->pickup_location),
            'dropoff_text' => $order->dropoff_text,
            'dropoff' => $this->latLng($order->dropoff_location),
            'start' => $this->latLng($start),
            'start_name' => $start ? $this->placeName($order->start_name, $start) : null,
            'finish' => $this->latLng($finish),
            'finish_name' => $finish ? $this->placeName($order->finish_name, $finish) : null,
            'distance_m' => $mine ? $order->distance_m : null,
            'duration_s' => $mine ? $order->duration_s : null,
            'price' => $mine ? $order->price_final : null,
            'commission' => $mine ? $order->commission : null,
            'cashless' => $order->company_id !== null,
        ];
    }

    /**
     * Nom safar paytida yozilgan; faqat eski buyurtmalarda (ustun bo'sh) joyida qidiriladi. Bo'sh satr — nom topilmagan.
     */
    private function placeName(?string $stored, Point $point): ?string
    {
        $name = $stored ?? Landmark::nameAt($point);

        return $name === '' ? null : $name;
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function latLng(?Point $point): ?array
    {
        return $point ? ['lat' => $point->getLatitude(), 'lng' => $point->getLongitude()] : null;
    }

    /**
     * Ilova yuborgan joriy joy (ixtiyoriy): yo'lovchi qayerda olingani/tushirilgani tarixda ko'rinadi.
     */
    private function position(Request $request): ?Point
    {
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);

        return isset($data['lat'], $data['lng']) ? Point::makeGeodetic((float) $data['lat'], (float) $data['lng']) : null;
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
