<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Order;
use App\Services\Push\FcmPushNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Mijoz ilovasiga push (qaror #71): buyurtma hodisasi — mijozning tilida. Ilova ochiq bo'lsa holat Reverb orqali
 * allaqachon ko'rinadi, bildirishnoma ilova yopiq paytlar uchun.
 */
class SendClientPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** Buyurtma hodisasi → xabar */
    public const EVENTS = ['assigned', 'arrived', 'no_driver_found', 'cancelled_by_operator', 'completed'];

    public function __construct(public int $orderId, public string $type)
    {
        $this->onQueue('push');
    }

    public function handle(FcmPushNotifier $notifier): void
    {
        $order = Order::query()->withoutGlobalScopes()->with('vehicle')->find($this->orderId);
        $client = $order ? Client::query()->find($order->client_id) : null;
        if (! $order || ! $client) {
            return;
        }

        $locale = $client->locale;
        $t = function (string $key, array $replace = []) use ($locale): string {
            $text = __($key, $replace, $locale);

            return is_string($text) ? $text : $key;
        };
        $car = $order->vehicle ? trim("{$order->vehicle->color} {$order->vehicle->model} {$order->vehicle->plate_number}") : '';
        [$title, $body] = match ($this->type) {
            'assigned' => [$t('Your driver is on the way'), $t(':car is on the way', ['car' => $car])],
            'arrived' => [$t('Your driver has arrived'), $t(':car is waiting for you', ['car' => $car])],
            'no_driver_found' => [$t('No cars available'), $t('Please try again later or call the dispatcher.')],
            'cancelled_by_operator' => [$t('Your order was cancelled'), $order->cancel_reason !== null ? $t($order->cancel_reason) : ''],
            'completed' => [$t('Trip completed'), $t('Please rate your trip.')],
            default => [null, null],
        };
        if ($title === null) {
            return;
        }

        $notifier->sendToClient($client->id, $title, $body, ['order_id' => $order->id, 'type' => $this->type]);
    }
}
