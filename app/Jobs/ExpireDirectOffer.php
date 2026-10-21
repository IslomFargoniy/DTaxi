<?php

namespace App\Jobs;

use App\Enums\OfferResponse;
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
        // Shartli UPDATE: haydovchi aynan shu soniyada rad etsa, jarima ikki marta yozilmaydi
        $changed = $offer ? OrderOffer::query()->whereKey($offer->id)->whereNull('response')
            ->update(['response' => OfferResponse::Ignored->value, 'responded_at' => now()]) : 0;
        if (! $offer || $changed !== 1) {
            return;
        }

        $order = Order::query()->withoutGlobalScopes()->findOrFail($offer->order_id);
        $driver = Driver::query()->withoutGlobalScopes()->findOrFail($offer->driver_id);

        $dispatcher->directOfferFailed($order, $driver, 'ignored');
    }
}
