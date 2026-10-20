<?php

namespace App\Jobs;

use App\Enums\OfferResponse;
use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderOffer;
use App\Services\Dispatch\Dispatcher;
use App\Services\Dispatch\DriverDiscipline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Operator tayinlagan taklifga haydovchi vaqtida javob bermadi → jarima va buyurtma efirga qaytadi.
 */
class ExpireDirectOffer implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $offerId)
    {
        $this->onQueue('dispatch');
    }

    public function handle(Dispatcher $dispatcher, DriverDiscipline $discipline): void
    {
        $offer = OrderOffer::query()->find($this->offerId);
        if (! $offer || $offer->response !== null) {
            return;
        }

        $offer->forceFill(['response' => OfferResponse::Ignored, 'responded_at' => now()])->save();

        $order = Order::query()->withoutGlobalScopes()->findOrFail($offer->order_id);
        $driver = Driver::query()->withoutGlobalScopes()->findOrFail($offer->driver_id);

        // Shu orada buyurtmani boshqasi olgan yoki bekor qilingan — haydovchining aybi yo'q
        if ($order->status !== OrderStatus::Searching) {
            return;
        }

        $discipline->directOfferMissed($driver, $order, 'ignored');
        $dispatcher->broadcast($order);
    }
}
