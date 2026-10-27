<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Call;
use App\Models\Order;
use App\Models\PhoneNumber;
use App\Services\Routing\Router;
use App\Services\Sounds\MessageComposer;
use App\Services\Telephony\AriClient;
use App\Services\Telephony\CallCenter;
use App\Services\Telephony\ChannelLimits;
use App\Services\Telephony\ClientNotifier;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Bitta avto-qo'ng'iroq (§5.4). Liniya band bo'lsa yoki mijoz hali operator bilan gaplashayotgan bo'lsa —
 * rad etilmaydi, navbatda kutadi (§5.3).
 */
class PlaceAutoCall implements ShouldQueue
{
    use Queueable;

    /** Liniya bo'shashini ~10 daqiqagacha kutadi */
    public int $tries = 60;

    public function __construct(public int $orderId, public string $message, public int $attempt = 1)
    {
        $this->onQueue('calls');
    }

    public function handle(AriClient $ari, ChannelLimits $limits, MessageComposer $composer, Router $router, CallCenter $center, ClientNotifier $notifier): void
    {
        $order = Order::query()->withoutGlobalScopes()->with(['client', 'vehicle', 'driver'])->find($this->orderId);
        if (! $order || ! $this->stillRelevant($order)) {
            return;
        }

        // Mijoz hali liniyada (masalan operator bilan) — suhbat tugagach (qaror #16)
        $onLine = Call::query()->withoutGlobalScopes()
            ->whereIn('status', Call::ACTIVE)
            ->where(fn ($q) => $q->where('client_id', $order->client_id)->orWhere('caller', $order->client->phone)->orWhere('callee', $order->client->phone))
            ->exists();
        if ($onLine) {
            $this->release(10);

            return;
        }

        $number = ($order->phone_number_id ? PhoneNumber::query()->where('is_active', true)->find($order->phone_number_id) : null)
            ?? PhoneNumber::forBranch($order->branch_id);
        if (! $number) {
            Log::warning("Avto-qo'ng'iroq: chiquvchi raqam yo'q", ['order' => $order->id]);

            return;
        }

        $branch = Branch::query()->findOrFail($order->branch_id);
        // Qulfsiz tez tekshiruv: liniya band bo'lsa xabar yig'ilmaydi (yo'nalish so'rovi ham yo'q)
        if (! $limits->canPlaceAutoCall($number->trunk, $branch)) {
            $this->release(10);

            return;
        }

        $media = $this->media($order, $composer, $router);
        if ($media === []) {
            Log::warning("Avto-qo'ng'iroq: ovozli bo'laklar yo'q", ['order' => $order->id, 'message' => $this->message]);

            return;
        }

        // Tekshiruv va qator yozish bitta qulf ostida: Horizon'ning bir nechta jarayoni bir vaqtda oxirgi bo'sh liniyani ko'rib,
        // limitdan oshib ketmasin (qator 'ringing' bo'lib yozilgach, keyingisi uni sanaydi)
        $lock = Cache::lock("autocall:trunk:{$number->sip_trunk_id}", 15);
        try {
            $lock->block(3);
        } catch (LockTimeoutException) {
            $this->release(5);

            return;
        }

        try {
            if (! $limits->canPlaceAutoCall($number->trunk, $branch)) {
                $this->release(10);

                return;
            }
            $call = $this->createCall($order, $number, $media);
        } finally {
            $lock->release();
        }
        $channelId = (string) $call->channel_id;
        $center->remember($channelId, $call, 'client');

        if (! $ari->originate($number->dialString($order->client->phone), "tts,{$call->id}", $number->number, 30, channelId: $channelId)) {
            // Javob kelmay vaqt tugagan bo'lsa ham kanal Asterisk'da yaratilgan bo'lishi mumkin — liniya band qolmasin
            $ari->hangup($channelId);
            $call->forceFill(['status' => 'failed', 'ended_at' => now()])->save();
            $notifier->retry($call);
        }
    }

    /**
     * @param  list<string>  $media
     */
    private function createCall(Order $order, PhoneNumber $number, array $media): Call
    {
        return Call::query()->create([
            'channel_id' => (string) Str::uuid(),
            'branch_id' => $order->branch_id,
            'direction' => 'outbound',
            'kind' => 'tts',
            'caller' => $number->number,
            'callee' => $order->client->phone,
            'phone_number_id' => $number->id,
            'sip_trunk_id' => $number->sip_trunk_id,
            'client_id' => $order->client_id,
            'order_id' => $order->id,
            'status' => 'ringing',
            'attempt' => $this->attempt,
            'started_at' => now(),
            'meta' => ['message' => $this->message, 'media' => $media],
        ]);
    }

    private function stillRelevant(Order $order): bool
    {
        return match ($this->message) {
            'assigned' => $order->status === OrderStatus::Assigned,
            'arrived', 'still_waiting' => $order->status === OrderStatus::Arrived,
            'cancelled' => $order->status === OrderStatus::CancelledByOperator,
            'no_driver' => $order->status === OrderStatus::NoDriverFound,
            'rate' => $order->status === OrderStatus::Completed && $order->client_rating === null,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    private function media(Order $order, MessageComposer $composer, Router $router): array
    {
        return match ($this->message) {
            'assigned' => $composer->assigned($order, $this->etaMinutes($order, $router)),
            'arrived' => $composer->arrived($order),
            'still_waiting' => [...$composer->phrase('phrase', 'still-waiting'), ...$composer->media($composer->car($order->vehicle))],
            'cancelled' => $composer->phrase('phrase', 'cancelled'),
            'no_driver' => $composer->phrase('phrase', 'no-driver'),
            'rate' => $composer->phrase('phrase', 'rate-trip'),
            default => [],
        };
    }

    private function etaMinutes(Order $order, Router $router): ?int
    {
        $from = $order->driver?->last_location;
        if (! $from) {
            return null;
        }

        return max(1, (int) ceil($router->route($from, $order->pickup_location)->durationSeconds / 60));
    }
}
