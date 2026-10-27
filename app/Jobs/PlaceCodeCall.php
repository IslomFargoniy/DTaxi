<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\Call;
use App\Models\Client;
use App\Models\PhoneNumber;
use App\Services\Sounds\MessageComposer;
use App\Services\Telephony\AriClient;
use App\Services\Telephony\CallCenter;
use App\Services\Telephony\ChannelLimits;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Mijoz ilovasiga kirish kodi qo'ng'iroqda (qaror #71): "Ilovaga kirish kodingiz: 4 7 2 1. Takrorlayman: 4 7 2 1".
 * Liniya band bo'lsa ~2 daqiqa kutadi (kod 5 daqiqa amal qiladi).
 */
class PlaceCodeCall implements ShouldQueue
{
    use Queueable;

    public int $tries = 12;

    public function __construct(public string $phone, public string $code)
    {
        $this->onQueue('calls');
    }

    public function handle(AriClient $ari, ChannelLimits $limits, MessageComposer $composer, CallCenter $center): void
    {
        $client = Client::query()->where('phone', $this->phone)->first();
        $number = PhoneNumber::forBranch($client?->last_branch_id);
        $branch = $number?->branches()->first() ?? Branch::query()->orderBy('id')->first();
        if (! $number || ! $branch) {
            Log::warning("Kirish kodi: chiquvchi raqam yo'q", ['phone' => $this->phone]);

            return;
        }

        $digits = array_map(fn (string $d) => ['number', $d], str_split($this->code));
        $media = $composer->media([['phrase', 'login-code'], ...$digits, ['phrase', 'code-repeat'], ...$digits]);
        if ($media === []) {
            Log::warning("Kirish kodi: ovozli bo'laklar yo'q (Ovozlar bo'limida generatsiya qiling)");

            return;
        }

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
            $call = Call::query()->create([
                'channel_id' => (string) Str::uuid(),
                'branch_id' => $branch->id,
                'direction' => 'outbound',
                'kind' => 'tts',
                'caller' => $number->number,
                'callee' => $this->phone,
                'phone_number_id' => $number->id,
                'sip_trunk_id' => $number->sip_trunk_id,
                'client_id' => $client?->id,
                'status' => 'ringing',
                'attempt' => 1,
                'started_at' => now(),
                'meta' => ['message' => 'login_code', 'media' => $media],
            ]);
        } finally {
            $lock->release();
        }

        $channelId = (string) $call->channel_id;
        $center->remember($channelId, $call, 'client');
        if (! $ari->originate($number->dialString($this->phone), "tts,{$call->id}", $number->number, 30, channelId: $channelId)) {
            // Javob kelmay vaqt tugagan bo'lsa ham kanal Asterisk'da yaratilgan bo'lishi mumkin — liniya band qolmasin
            $ari->hangup($channelId);
            $call->forceFill(['status' => 'failed', 'ended_at' => now()])->save();
        }
    }
}
