<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Dispatch\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpandOrderSearch implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $orderId, public int $step)
    {
        $this->onQueue('dispatch');
    }

    public function handle(Dispatcher $dispatcher): void
    {
        $order = Order::query()->withoutGlobalScopes()->find($this->orderId);

        if ($order) {
            $dispatcher->expand($order, $this->step);
        }
    }
}
