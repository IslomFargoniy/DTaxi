<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Call;
use App\Models\Complaint;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Services\Orders\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Buyurtmalar tarixi va har bir buyurtmaning to'liq hodisalar tarixi (nizolarni hal qilish uchun).
 */
class OrderHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'q' => ['nullable', 'string', 'max:50'],
        ]);

        $orders = Order::query()
            ->with(['client', 'driver.user', 'vehicle', 'tariffClass'])
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<', now()->parse($to)->addDay()))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $digits = preg_replace('/\D/', '', $term);
                $q->where(fn ($w) => $w
                    ->when(ctype_digit($term), fn ($w) => $w->orWhere('id', (int) $term))
                    ->orWhere('pickup_text', 'ilike', "%{$term}%")
                    ->when($digits !== '', fn ($w) => $w->orWhereHas('client', fn ($c) => $c->where('phone', 'like', "%{$digits}%"))));
            })
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('admin/orders/index', [
            'orders' => $orders->through(fn (Order $o) => (new OrderResource($o))->resolve()),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, Order $order): Response
    {
        $order->load(['client', 'driver.user', 'vehicle', 'tariffClass', 'delivery', 'offers.driver']);

        return Inertia::render('admin/orders/show', [
            'order' => (new OrderResource($order))->resolve(),
            'events' => $order->events()->with('actor:id,name')->orderBy('created_at')->get()->map(fn (OrderEvent $e) => [
                'type' => $e->type,
                'actor' => $e->actor?->name,
                'payload' => $e->payload,
                'created_at' => $e->created_at?->toIso8601String(),
            ]),
            // Shu buyurtma bo'yicha qo'ng'iroqlar (yozuvni tinglash huquqi bo'lsa — havola)
            'calls' => Call::query()->where('order_id', $order->id)->orderBy('started_at')->get()
                ->map(fn (Call $call) => [
                    'id' => $call->id,
                    'direction' => $call->direction,
                    'started_at' => $call->started_at->toIso8601String(),
                    'duration_s' => $call->talk_seconds,
                    'recording_url' => $call->recording_path && $request->user()?->can('calls.listen')
                        ? route('admin.calls.recording', $call) : null,
                ]),
            'complaints' => Complaint::query()->where('order_id', $order->id)->with('author:id,name')->latest()->get()
                ->map(fn (Complaint $c) => [
                    'id' => $c->id,
                    'type' => $c->type->value,
                    'text' => $c->text,
                    'status' => $c->status,
                    'author' => $c->author?->name,
                    'created_at' => $c->created_at->toIso8601String(),
                ]),
            'clientRating' => $order->client_rating,
            'offers' => $order->offers->map(fn ($offer) => [
                'callsign' => $offer->driver->callsign,
                'distance_m' => $offer->distance_m,
                'is_direct' => $offer->is_direct,
                'response' => $offer->response?->value,
                'offered_at' => $offer->offered_at->toIso8601String(),
                'responded_at' => $offer->responded_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Mijoz bahosi (1–5) — operator qo'ng'iroqdan keyin kiritadi.
     */
    public function rate(Request $request, Order $order, OrderService $orders): RedirectResponse
    {
        $orders->rateByClient($order, (int) $request->validate(['rating' => ['required', 'integer', 'between:1,5']])['rating']);

        return back();
    }
}
