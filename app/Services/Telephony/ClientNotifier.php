<?php

namespace App\Services\Telephony;

use App\Enums\OrderSource;
use App\Jobs\PlaceAutoCall;
use App\Models\Call;
use App\Models\Order;
use App\Services\Orders\OrderEvents;
use App\Support\Settings;

/**
 * Mijozga avtomatik ovozli xabarlar (§5.4, qaror #16): buyurtma hodisasi → avto-qo'ng'iroq navbati.
 */
class ClientNotifier
{
    /** Buyurtma hodisasi → xabar turi */
    private const EVENTS = [
        'assigned' => 'assigned',
        'arrived' => 'arrived',
        'cancelled_by_operator' => 'cancelled',
        'no_driver_found' => 'no_driver',
    ];

    public function __construct(private Settings $settings) {}

    public function onOrderEvent(Order $order, string $type): void
    {
        // Ilovadan berilgan buyurtma — mijoz holatni ilovada ko'radi, qo'ng'iroq bilan bezovta qilinmaydi (qaror #71)
        if ($order->source === OrderSource::App) {
            return;
        }

        if ($type === 'completed' && $this->settings->get('calls.rating_call_enabled', $order->branch_id)) {
            PlaceAutoCall::dispatch($order->id, 'rate')
                ->delay(now()->addMinutes((int) $this->settings->get('calls.rating_call_delay_minutes', $order->branch_id)));

            return;
        }

        $message = self::EVENTS[$type] ?? null;
        if (! $message || ! $this->settings->get('calls.auto_calls_enabled', $order->branch_id)) {
            return;
        }

        PlaceAutoCall::dispatch($order->id, $message);

        if ($message === 'arrived') {
            $minutes = (int) $this->settings->get('calls.still_waiting_minutes', $order->branch_id);
            if ($minutes > 0) {
                PlaceAutoCall::dispatch($order->id, 'still_waiting')->delay(now()->addMinutes($minutes));
            }
        }
    }

    /**
     * Javob bermadi / band: 30 soniyadan keyin qayta urinish, so'ng operator paneliga "yetkazilmadi" belgisi.
     */
    public function retry(Call $call): void
    {
        $message = data_get($call->meta, 'message');
        // Baho so'rovi muhim emas — qayta urinilmaydi
        if (! $call->order_id || ! is_string($message) || $message === 'rate') {
            return;
        }

        if ($call->attempt < (int) $this->settings->get('calls.auto_call_attempts', $call->branch_id)) {
            PlaceAutoCall::dispatch($call->order_id, $message, $call->attempt + 1)
                ->delay(now()->addSeconds((int) $this->settings->get('calls.auto_call_retry_seconds', $call->branch_id)));

            return;
        }

        $order = Order::query()->withoutGlobalScopes()->find($call->order_id);
        if ($order) {
            OrderEvents::record($order, 'client_not_notified', payload: ['message' => $message, 'status' => $call->status]);
        }
    }
}
