<?php

namespace App\Services\Telephony;

use App\Enums\DriverStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Events\CallUpdated;
use App\Events\IncomingCall;
use App\Events\IncomingCallEnded;
use App\Events\OperatorCallEvent;
use App\Jobs\ProcessRecording;
use App\Jobs\SendPushNotification;
use App\Models\Branch;
use App\Models\Call;
use App\Models\Client;
use App\Models\DeviceToken;
use App\Models\Driver;
use App\Models\Order;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Sounds\MessageComposer;
use App\Support\Settings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Qo'ng'iroq markazi — ARI hodisalari bo'yicha holat mashinasi (andoza: panel.1call.uz Stasis handlerlari).
 * Butun mantiq PHP'da: dialplan faqat Stasis(dtaxi,...) ga yuboradi.
 *
 * Stasis argumentlari:
 *   inbound,<DID>   — trunkdan kiruvchi (mijoz)
 *   internal,<exten> — operator/haydovchi brauzer/ilovadan tergan raqam
 *                      (haydovchi ilovasi answer-<callId> — operator qo'ng'irog'iga javob)
 *   operator,<callId> — navbatdagi qo'ng'iroq uchun operatorga yuborilgan kanal javob berdi
 *   peer,<callId>   — operator chaqirgan tomon (mijoz/haydovchi) javob berdi
 *   tts,<callId>    — avto-qo'ng'iroqda mijoz javob berdi
 *   client,<target> — mijoz ilovasi (vaqtinchalik cl{N}): driver — biriktirilgan haydovchiga, dispatcher — navbatga
 *
 * Kanal roli keshda: client (navbatdagi / TTS tomon), operator, peer.
 */
class CallCenter
{
    use DetectsLostConnections;

    /** Chaqiruv ohangi (gudok): Asterisk indications.conf, ru zonasi — O'zbekistondagi bilan bir xil */
    private const RINGBACK = 'tone:ring;tonezone=ru';

    private const CHANNEL_TTL = 14400;

    /** Avto-qo'ng'iroq xabari necha marta takrorlanadi (§5.4) */
    private const TTS_REPEATS = 2;

    /** Solishtirishda shundan yangi qo'ng'iroqlar yopilmaydi (kanal hali yaratilayotgan bo'lishi mumkin) */
    private const RECONCILE_GRACE_SECONDS = 10;

    /**
     * Shu ijrolar faqat PlaybackFinished bilan davom etadi (tick() ularni kutish chegarasidan chetlaydi) —
     * hodisa yo'qolsa mijoz abadiy osilib qolmasligi uchun muddat
     */
    private const GUARDED_PLAYBACKS = ['close', 'ivr', 'auto_offer', 'tts', 'greeting'];

    private const PLAYBACK_DEADLINE_SECONDS = 120;

    public function __construct(
        private AriClient $ari,
        private OperatorPool $operators,
        private ChannelLimits $limits,
        private ClientExtensions $clientExtensions,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): void
    {
        try {
            match (data_get($event, 'type')) {
                'StasisStart' => $this->onStasisStart($event),
                // Dialplan orqali kirgan kanal uchun faqat StasisEnd keladi, javob bermagan chiquvchi kanal uchun — ChannelDestroyed.
                // Ikkalasi ham "kanal tugadi"; ikkinchisi idempotent tarzda e'tiborsiz qoladi.
                'StasisEnd', 'ChannelDestroyed' => $this->onChannelDestroyed((string) data_get($event, 'channel.id'), (int) data_get($event, 'cause', 0)),
                'PlaybackFinished' => $this->onPlaybackFinished((string) data_get($event, 'playback.id')),
                'ChannelDtmfReceived' => $this->onDtmf((string) data_get($event, 'channel.id'), (string) data_get($event, 'digit', '')),
                default => null,
            };
        } catch (\Throwable $e) {
            // Baza uzilgan — ari:listen qayta ulanib hodisani takrorlaydi (kanal uzilmaydi)
            if ($e instanceof QueryException && $this->causedByLostConnection($e)) {
                throw $e;
            }
            Log::error('CallCenter: '.$e->getMessage(), ['event' => data_get($event, 'type'), 'exception' => $e]);

            // Ishlov yarim qolgan kanal Stasis'da abadiy osilib qolmasin (mijoz jimlikni eshitib turardi)
            if (data_get($event, 'type') === 'StasisStart' && is_string($channelId = data_get($event, 'channel.id'))) {
                rescue(fn () => $this->ari->hangup($channelId), report: false);
            }
        }
    }

    /**
     * Har 2 soniyada: navbatdagi qo'ng'iroqlarni bo'sh operatorlarga taqsimlash, uzoq kutganlarni yakunlash.
     */
    public function tick(): void
    {
        // Operator jiringlayotgan qo'ng'iroq ham olinadi: kutish chegarasi unga ham tegishli
        $waiting = Call::query()->withoutGlobalScopes()
            ->where('status', 'waiting')
            ->orderBy('started_at')
            ->get();

        $maxWait = (int) $this->settings()->get('calls.max_wait_seconds');

        foreach ($waiting as $call) {
            if (data_get($call->meta, 'ivr') || data_get($call->meta, 'closing')) {
                continue;
            }
            if (data_get($call->meta, 'auto_offer')) {
                // Taklif eshittirildi, 5 soniya ichida tugma bosilmadi — operator navbatiga
                $until = data_get($call->meta, 'auto_offer_until');
                if ($until && now()->getTimestamp() >= (int) $until) {
                    $this->leaveAutoOffer($call);
                }

                continue;
            }

            if ($maxWait > 0 && $call->started_at->diffInSeconds(now()) >= $maxWait) {
                $this->closeWithMessage($call, 'call-later');

                continue;
            }

            $this->offerToOperator($call);
        }

        $this->expirePlaybacks();
        $this->expireDriverRings();
    }

    /**
     * PlaybackFinished kelmagan (yo'qolgan yoki Asterisk ijro qilmagan) IVR / yakuniy xabar / taklif / TTS —
     * muddat o'tgach xuddi ijro tugagandek emas, xavfsiz tomonga: navbatga yoki yakun.
     */
    private function expirePlaybacks(): void
    {
        $stale = Call::query()->withoutGlobalScopes()
            ->whereNull('ended_at')
            ->whereRaw("(meta->>'playback_until')::bigint <= ?", [now()->getTimestamp()])
            ->orderBy('id')
            ->get();

        foreach ($stale as $call) {
            $purpose = (string) data_get($call->meta, 'playback_purpose');
            Log::warning('CallCenter: ijro tugashi hodisasi kelmadi', ['call' => $call->id, 'purpose' => $purpose]);
            if ($playback = data_get($call->meta, 'playback')) {
                Cache::forget("ari:pb:{$playback}");
                $this->ari->stopPlayback((string) $playback);
            }
            $this->updateMeta($call, ['playback_until' => null]);

            match (true) {
                $purpose === 'ivr' && $call->status === 'waiting' && (bool) data_get($call->meta, 'ivr') => $this->ivrFallback($call),
                $purpose === 'auto_offer' && $call->status === 'waiting' && (bool) data_get($call->meta, 'auto_offer') => $this->leaveAutoOffer($call),
                $purpose === 'close', $purpose === 'tts' => $this->finish($call, $this->endStatus($call, 0)),
                // Salomlashuv tugagani bilmay qolinsa ham mijoz jimlikda qolmaydi
                $purpose === 'greeting' && $call->status === 'waiting' => $this->ari->startMoh((string) $call->channel_id),
                default => null,
            };
        }
    }

    /**
     * Haydovchi ilovada javob bermagan operator qo'ng'iroqlari (calls.driver_ring_seconds) — operatorga "javob yo'q".
     */
    private function expireDriverRings(): void
    {
        $ringing = Call::query()->withoutGlobalScopes()
            ->where('status', 'ringing')
            ->where('kind', 'driver')
            ->whereNull('ended_at')
            ->get();

        foreach ($ringing as $call) {
            $until = data_get($call->meta, 'driver_ring_until');
            if ($until && now()->getTimestamp() >= (int) $until) {
                $this->endDriverRing($call, 'no_answer', 'no_answer', 'timeout');
            }
        }
    }

    /**
     * ARI'ga (qayta) ulanganda va har daqiqada: bazadagi ochiq qo'ng'iroqlarni Asterisk'dagi haqiqiy kanallar bilan solishtirish.
     * Uzilish yoki daemon qayta ishga tushishi paytida yo'qolgan hodisalar sababli osilib qolgan qo'ng'iroqlar yopiladi
     * (operator bo'shaydi), tirik kanallar bog'lanishi tiklanadi, hech bir ochiq qo'ng'iroqqa tegishli bo'lmagan ko'priklar yopiladi.
     */
    public function reconcile(): void
    {
        // Avval baza, keyin Asterisk: ro'yxat olingach yaratilgan qo'ng'iroq "kanalsiz" deb yopilmasin
        $calls = Call::query()->withoutGlobalScopes()->whereNull('ended_at')->orderBy('id')->get();
        $channels = $this->ari->channels();
        if ($channels === null) {
            return;
        }

        $live = [];
        foreach ($channels as $channel) {
            $live[(string) ($channel['id'] ?? '')] = (string) ($channel['state'] ?? '');
        }
        // Kanal yaratilishi (originate) bilan qator yozilishi orasida — bunday yangi qo'ng'iroqlarga tegilmaydi
        $settled = now()->subSeconds(self::RECONCILE_GRACE_SECONDS);

        foreach ($calls as $call) {
            $this->reconcileCall($call, $live, $call->started_at->lessThan($settled));
        }

        $this->reconcileBridges();
        $this->clientExtensions->prune();
    }

    /**
     * @param  array<string, string>  $live  kanal => holati (Up, Ringing, ...)
     */
    private function reconcileCall(Call $call, array $live, bool $settled): void
    {
        /** @var array<string, string> $roles kanal => rol (remember() dagidek) */
        $roles = [];
        foreach ([
            [$call->channel_id, data_get($call->meta, 'peer_channel') ? 'operator' : 'client'],
            [data_get($call->meta, 'operator_channel'), 'operator'],
            [data_get($call->meta, 'peer_channel'), 'peer'],
        ] as [$channel, $role]) {
            if (is_string($channel) && $channel !== '') {
                $roles[$channel] = $role;
            }
        }

        $dead = null;
        foreach ($roles as $channel => $role) {
            $channel = (string) $channel;
            if (isset($live[$channel])) {
                $this->remember($channel, $call, $role);
            } else {
                $dead ??= $channel;
            }
        }
        if ($dead === null && $roles !== []) {
            // Operator javob bergan, lekin StasisStart yo'qolgan — mijoz navbatda, operator jimlikda qolmasin
            $operatorChannel = data_get($call->meta, 'operator_channel');
            if ($settled && $call->status === 'waiting' && is_string($operatorChannel) && ($live[$operatorChannel] ?? null) === 'Up') {
                $this->operatorAnswered($operatorChannel, $call->id);
            }

            return;
        }
        if (! $settled) {
            return;
        }

        Log::info("CallCenter: Asterisk'da kanali yo'q qo'ng'iroq yopiladi", ['call' => $call->id, 'channel' => $dead]);
        if ($dead === null) {
            $this->finish($call, $this->endStatus($call, 0));

            return;
        }

        // Yo'qolgan ChannelDestroyed hodisasi o'rniga: rolga qarab yopiladi yoki (operator jiringlashi bo'lsa) keyingi operatorga
        $this->remember($dead, $call, $roles[$dead]);
        $this->onChannelDestroyed($dead, 0);
    }

    /**
     * Asterisk'da qolib ketgan bizning (Stasis) ko'priklarimiz — ochiq qo'ng'iroqqa tegishli bo'lmasa yopiladi.
     */
    private function reconcileBridges(): void
    {
        $bridges = $this->ari->bridges();
        if ($bridges === null) {
            return;
        }

        $inUse = Call::query()->withoutGlobalScopes()->whereNull('ended_at')->get()
            ->map(fn (Call $call) => data_get($call->meta, 'bridge'))->filter()->map(fn ($id) => (string) $id)->all();

        foreach ($bridges as $bridge) {
            $id = (string) ($bridge['id'] ?? '');
            $ours = strtolower((string) ($bridge['bridge_class'] ?? '')) === 'stasis' || ($bridge['creator'] ?? null) === 'Stasis';
            if ($id !== '' && $ours && ! in_array($id, $inUse, true)) {
                Log::info("CallCenter: egasiz ko'prik yopiladi", ['bridge' => $id]);
                $this->ari->destroyBridge($id);
            }
        }
    }

    // ── StasisStart ────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $event
     */
    private function onStasisStart(array $event): void
    {
        /** @var array<string, mixed> $channel */
        $channel = $event['channel'];
        $args = array_values((array) ($event['args'] ?? []));
        $kind = (string) ($args[0] ?? '');
        $arg = (string) ($args[1] ?? '');

        match ($kind) {
            'inbound' => $this->inbound($channel, $arg),
            'internal' => $this->internal($channel, $arg),
            'operator' => $this->operatorAnswered((string) $channel['id'], (int) $arg),
            'peer' => $this->peerAnswered((string) $channel['id'], (int) $arg),
            'tts' => $this->ttsAnswered((string) $channel['id'], (int) $arg),
            'client' => $this->fromClientApp($channel, $arg),
            default => $this->ari->hangup((string) $channel['id']),
        };
    }

    /**
     * Mijoz trunk orqali qo'ng'iroq qildi: raqam → filial(lar), qora ro'yxat, liniyalar limiti, salomlashuv, navbat.
     *
     * @param  array<string, mixed>  $channel
     */
    private function inbound(array $channel, string $dialed): void
    {
        $channelId = (string) $channel['id'];
        // Hodisa qayta ijro etilsa (baza uzilib guard() takrorlasa yoki ARI qayta yuborsa) — ikkinchi yozuv ochilmaydi
        if (Call::query()->withoutGlobalScopes()->where('channel_id', $channelId)->exists()) {
            return;
        }
        $callerRaw = (string) data_get($channel, 'caller.number', '');
        $caller = strlen((string) preg_replace('/\D/', '', $callerRaw)) >= 9 ? Client::normalizePhone($callerRaw) : $callerRaw;

        $number = PhoneNumber::findByDialed($dialed) ?? PhoneNumber::query()->where('is_active', true)->orderBy('id')->first();
        if (! $number) {
            Log::warning("CallCenter: noma'lum raqamga qo'ng'iroq", ['dialed' => $dialed]);
            $this->ari->hangup($channelId);

            return;
        }

        $client = Client::query()->where('phone', $caller)->first();
        $branchIds = array_values(array_map('intval', $number->branches()->pluck('branches.id')->all()
            ?: Branch::query()->where('is_active', true)->pluck('id')->all()));
        $ivr = false;
        $branchId = $this->routeBranch($number, $branchIds, $client, $ivr);

        // Avto-qo'ng'iroq joblari bilan bitta qulf: ikkalasi bir vaqtda oxirgi bo'sh liniyani olib limitdan oshmasin.
        // Qulf olinmasa ham mijoz qo'ng'irog'i rad etilmaydi (daemon uzoq kutmaydi)
        $lock = Cache::lock("autocall:trunk:{$number->sip_trunk_id}", 15);
        try {
            $locked = $lock->block(1);
        } catch (LockTimeoutException) {
            $locked = false;
        }
        try {
            [$call, $allowed] = $this->openInbound($channelId, $caller, $number, $client, $branchId, $branchIds, $ivr);
        } finally {
            if ($locked) {
                $lock->release();
            }
        }
        $this->remember($channelId, $call, 'client');

        if ($client?->is_blacklisted) {
            $call->forceFill(['status' => 'blocked'])->save();
            $this->ari->hangup($channelId, 'busy');

            return;
        }

        if (! $allowed) {
            $this->updateMeta($call, ['reason' => 'channels']);
            $call->forceFill(['status' => 'rejected'])->save();
            $this->ari->answer($channelId);
            $this->playFor($call, $channelId, $this->composer()->phrase('ivr', 'lines-busy'), 'close');

            return;
        }

        $this->ari->answer($channelId);

        // Ish vaqtidan tashqari (yoki bayram): xabar va yakun — "javobsiz" ro'yxatiga tushmaydi
        if (! $this->anyOpen($ivr ? $branchIds : array_values(array_map('intval', (array) data_get($call->meta, 'branch_ids', []))))) {
            $call->forceFill(['status' => 'rejected'])->save();
            $this->updateMeta($call, ['reason' => 'closed']);
            $this->playFor($call, $channelId, $this->composer()->phrase('ivr', 'closed'), 'close');

            return;
        }

        if ($ivr) {
            $this->playIvrMenu($call);
        } elseif ($lastOrder = $this->autoOrderCandidate($call, $client)) {
            // Doimiy mijoz: "1" — oxirgi manzilga taksi, "2" yoki jim tursa — operator navbati
            $this->updateMeta($call, ['auto_offer' => $lastOrder->id]);
            $this->playFor($call, $channelId, [...$this->greeting($branchId), ...$this->composer()->phrase('ivr', 'auto-order-offer')], 'auto_offer');
        } else {
            $this->playFor($call, $channelId, $this->greeting($branchId), 'greeting');
            $this->offerInTurn($call);
        }

        $this->broadcast(new CallUpdated($call));
    }

    /**
     * Kiruvchi qo'ng'iroq yozuvi va liniya limiti tekshiruvi (chaqiruvchi trunk qulfini ushlab turadi).
     *
     * @param  list<int>  $branchIds
     * @return array{Call, bool}
     */
    private function openInbound(string $channelId, string $caller, PhoneNumber $number, ?Client $client, ?int $branchId, array $branchIds, bool $ivr): array
    {
        $call = Call::query()->create([
            'channel_id' => $channelId,
            'branch_id' => $branchId,
            'direction' => 'inbound',
            'kind' => 'client',
            'caller' => $caller,
            'callee' => $number->number,
            'phone_number_id' => $number->id,
            'sip_trunk_id' => $number->sip_trunk_id,
            'client_id' => $client?->id,
            'status' => 'waiting',
            'started_at' => now(),
            'meta' => ['branch_ids' => $branchId && ! $ivr && $number->routing_mode !== 'shared_queue' ? [$branchId] : $branchIds, 'ivr' => $ivr],
        ]);

        return [$call, $this->limits->canAcceptInbound($number->trunk, $branchId ? Branch::query()->find($branchId) : null, $call->id)];
    }

    /**
     * IVR avto-buyurtma uchun mijozning oxirgi yakunlangan buyurtmasi (faol buyurtmasi bo'lmasa).
     */
    private function autoOrderCandidate(Call $call, ?Client $client): ?Order
    {
        if (! $client || ! $call->branch_id || ! $this->settings()->get('calls.ivr_auto_order', $call->branch_id)
            || $this->composer()->phrase('ivr', 'auto-order-offer') === []) {
            return null;
        }

        $orders = Order::query()->withoutGlobalScopes()->where('client_id', $client->id);
        if ((clone $orders)->active()->exists()) {
            return null;
        }

        return $orders->where('status', 'completed')
            ->whereIn('branch_id', array_map('intval', (array) data_get($call->meta, 'branch_ids', [$call->branch_id])))
            ->latest('id')->first();
    }

    /**
     * Mijoz "1" ni bosdi: oxirgi buyurtmasi bo'yicha yangi buyurtma, tasdiq xabari va yakun.
     */
    private function placeAutoOrder(Call $call, int $templateId): void
    {
        $template = Order::query()->withoutGlobalScopes()->with('client')->find($templateId);
        if (! $template) {
            $this->leaveAutoOffer($call);

            return;
        }
        if ($playback = data_get($call->meta, 'playback')) {
            $this->ari->stopPlayback((string) $playback);
        }

        $order = app(OrderService::class)->create([
            'branch_id' => $template->branch_id,
            'phone' => $template->client->phone,
            'tariff_class_id' => $template->tariff_class_id,
            'pickup_text' => $template->pickup_text,
            'pickup_lat' => $template->pickup_location->getLatitude(),
            'pickup_lng' => $template->pickup_location->getLongitude(),
            'pickup_landmark_id' => $template->pickup_landmark_id,
            'source' => OrderSource::Ivr,
            'phone_number_id' => $call->phone_number_id,
        ]);

        $call->forceFill(['status' => 'answered', 'answered_at' => now(), 'order_id' => $order->id])->save();
        $this->updateMeta($call, ['auto_offer' => null, 'reason' => 'auto_order']);
        $this->playFor($call, (string) $call->channel_id, $this->composer()->phrase('ivr', 'order-accepted'), 'close');
        $this->broadcast(new CallUpdated($call));
    }

    /**
     * Avto-buyurtma taklifidan voz kechildi (yoki javob yo'q) — oddiy navbat.
     */
    private function leaveAutoOffer(Call $call): void
    {
        if ($playback = data_get($call->meta, 'playback')) {
            $this->ari->stopPlayback((string) $playback);
        }
        $this->updateMeta($call, ['auto_offer' => null, 'auto_offer_until' => null]);
        $this->ari->startMoh((string) $call->channel_id);
        $this->offerInTurn($call);
    }

    /**
     * Raqam bir nechta filialga bog'langanda (§5.2a): mijoz tarixi → IVR → umumiy navbat.
     *
     * @param  list<int>  $branchIds
     */
    private function routeBranch(PhoneNumber $number, array $branchIds, ?Client $client, bool &$ivr): ?int
    {
        if (count($branchIds) === 1) {
            return $branchIds[0];
        }

        $lastBranch = $client?->last_branch_id && in_array($client->last_branch_id, $branchIds, true) ? $client->last_branch_id : null;

        if ($number->routing_mode === 'client_history' && $lastBranch) {
            return $lastBranch;
        }

        if (in_array($number->routing_mode, ['ivr', 'client_history'], true)
            && $number->branches()->wherePivotNotNull('ivr_digit')->exists()) {
            $ivr = true;

            return null;
        }

        // Umumiy navbat: hamma filial operatorlari, buyurtma formasida oxirgi filial oldindan tanlanadi
        return $lastBranch ?? $number->branches()->orderByPivot('is_primary', 'desc')->value('branches.id') ?? ($branchIds[0] ?? null);
    }

    /**
     * Brauzer/ilovadan terilgan raqam: haydovchi → dispetcher navbati; operator → mijozga yoki haydovchiga.
     *
     * @param  array<string, mixed>  $channel
     */
    private function internal(array $channel, string $exten): void
    {
        $channelId = (string) $channel['id'];
        preg_match('#^PJSIP/([^-]+)-#', (string) ($channel['name'] ?? ''), $m);
        $endpoint = $m[1] ?? (string) data_get($channel, 'caller.number', '');

        if (str_starts_with($endpoint, 'dr')) {
            preg_match('/^answer-(\d+)$/', $exten, $am)
                ? $this->driverAnswers($channelId, (int) substr($endpoint, 2), (int) $am[1])
                : $this->driverToDispatcher($channelId, $endpoint);

            return;
        }

        $operator = User::query()->where('sip_extension', $endpoint)->first();
        if (! $operator) {
            $this->ari->hangup($channelId);

            return;
        }

        $this->operatorDials($channelId, $operator, $exten);
    }

    /**
     * Haydovchi ilovadan "Dispetcherga qo'ng'iroq" (qaror #47): o'z filiali navbatiga.
     */
    private function driverToDispatcher(string $channelId, string $endpoint): void
    {
        $driver = Driver::query()->withoutGlobalScopes()->find((int) substr($endpoint, 2));
        if (! $driver) {
            $this->ari->hangup($channelId);

            return;
        }

        $order = Order::query()->withoutGlobalScopes()->where('driver_id', $driver->id)
            ->whereIn('status', ['assigned', 'arrived', 'in_progress'])->latest('id')->first();

        $call = Call::query()->create([
            'channel_id' => $channelId,
            'branch_id' => $driver->branch_id,
            'direction' => 'internal',
            'kind' => 'driver',
            'caller' => $endpoint,
            'callee' => 'dispatcher',
            'driver_id' => $driver->id,
            'order_id' => $order?->id,
            'status' => 'waiting',
            'started_at' => now(),
            'meta' => ['branch_ids' => [$driver->branch_id]],
        ]);
        $this->remember($channelId, $call, 'client');

        $this->ari->answer($channelId);
        // Haydovchiga boradigan ovoz kuchaytiriladi: telefon karnayi mashinada shovqin ichida,
        // brauzer mikrofonidan kelgan operator ovozi esa past (WebView'da 0.01 atrofida). 0 — o'zgartirilmaydi
        if (($gain = (int) $this->settings()->get('calls.driver_gain', $driver->branch_id)) !== 0) {
            $this->ari->setVariable($channelId, 'VOLUME(TX)', (string) $gain);
        }
        // Haydovchi oddiy telefondagidek gudok eshitadi (425 Hz, 1/4 s) — operator javob berganda to'xtaydi
        $this->playFor($call, $channelId, [self::RINGBACK], 'ringback');
        $this->offerInTurn($call);
        $this->broadcast(new CallUpdated($call));
    }

    /**
     * Haydovchi ilovada "Javob berish"ni bosdi: ilova answer-{callId} ga qo'ng'iroq qildi — operator bilan ulanadi.
     * Boshqa haydovchining yoki tugagan qo'ng'iroq bo'lsa rad etiladi.
     */
    private function driverAnswers(string $channelId, int $driverId, int $callId): void
    {
        $call = Call::query()->withoutGlobalScopes()->find($callId);
        if (! $call || $call->kind !== 'driver' || $call->driver_id !== $driverId || $call->status !== 'ringing'
            || $call->ended_at || ! data_get($call->meta, 'driver_ring_until')) {
            $this->ari->hangup($channelId);

            return;
        }

        $operatorChannel = (string) $call->channel_id;
        $this->remember($channelId, $call, 'peer');
        $this->updateMeta($call, ['peer_channel' => $channelId]);

        $this->ari->answer($channelId);
        $this->ari->answer($operatorChannel);
        if (($gain = (int) $this->settings()->get('calls.driver_gain', $call->branch_id)) !== 0) {
            $this->ari->setVariable($channelId, 'VOLUME(TX)', (string) $gain);
        }
        if (! $this->bridge($call, $operatorChannel, $channelId)) {
            $this->finish($call, 'failed', ['reason' => 'bridge']);

            return;
        }
        $call->forceFill([
            'status' => 'answered',
            'answered_at' => now(),
            'wait_seconds' => (int) $call->started_at->diffInSeconds(now()),
        ])->save();

        $this->broadcast(new CallUpdated($call));
    }

    /**
     * Haydovchi ilovada "Rad etish"ni bosdi (API) — operator band signalini eshitadi.
     */
    public function driverDeclined(Call $call): void
    {
        $call->refresh();
        if ($call->status === 'ringing' && ! $call->ended_at && data_get($call->meta, 'driver_ring_until')) {
            $this->endDriverRing($call, 'declined', 'busy', 'declined');
        }
    }

    /**
     * Javob berilmagan operator → haydovchi qo'ng'irog'ini yakunlash: operator kanali sabab bilan uziladi
     * (brauzer "band"/"javob yo'q" ko'rsatadi), ilova ekrani finish() dagi IncomingCallEnded bilan yopiladi.
     */
    private function endDriverRing(Call $call, string $status, string $hangupReason, string $reason): void
    {
        // Shartli UPDATE: haydovchi rad etishi (HTTP), muddat tugashi (daemon) va javob berish bir vaqtda kelsa — faqat bittasi yopadi
        $claimed = $call->getConnection()->update(
            "UPDATE calls SET meta = meta || '{\"driver_ring_closed\": true}'::jsonb, updated_at = ? WHERE id = ? AND status = 'ringing' AND ended_at IS NULL AND meta->>'driver_ring_closed' IS NULL",
            [now(), $call->id],
        );
        if ($claimed !== 1) {
            return;
        }
        $operatorChannel = (string) $call->channel_id;
        $this->ari->hangup($operatorChannel, $hangupReason);
        $this->finish($call, $status, ['reason' => $reason], $operatorChannel);
    }

    /**
     * Operator tergan raqam: dr{N} yoki pozivnoy — haydovchi ilovasiga, boshqasi — trunk orqali mijozga (callback).
     */
    private function operatorDials(string $channelId, User $operator, string $exten): void
    {
        // Qisqa raqam haydovchi pozivnoyi bo'lsa — o'sha haydovchiga ("777" → dr74)
        if (preg_match('/^\d{1,6}$/', $exten)) {
            $driverId = Driver::query()->withoutGlobalScopes()->where('callsign', $exten)
                ->when(! $operator->isSuperadmin(), fn ($q) => $q->whereIn('branch_id', $operator->branchIds()))
                ->value('id');
            if ($driverId) {
                $exten = 'dr'.$driverId;
            }
        }

        if (preg_match('/^dr(\d+)$/', $exten, $dm)) {
            $this->ringDriver($channelId, $operator, (int) $dm[1]);

            return;
        }

        $phone = Client::normalizePhone($exten);

        // Trunk pulli: o'g'irlangan operator paroli bilan xalqaro/pullik raqamlarga qo'ng'iroq qilinmasin
        if (! $this->outboundAllowed($phone) || ! $this->outboundWithinLimit($operator)) {
            Log::warning("CallCenter: chiquvchi qo'ng'iroq rad etildi", ['operator' => $operator->id, 'dialed' => $exten]);
            $this->ari->hangup($channelId, 'congestion');

            return;
        }

        $number = PhoneNumber::forBranch($operator->branch_id);

        if (! $number) {
            $this->ari->hangup($channelId, 'congestion');

            return;
        }

        $call = Call::query()->create([
            'channel_id' => $channelId,
            'branch_id' => $operator->branch_id,
            'direction' => 'outbound',
            'kind' => 'callback',
            'caller' => $operator->sip_extension,
            'callee' => $phone,
            'phone_number_id' => $number->id,
            'sip_trunk_id' => $number->sip_trunk_id,
            'operator_id' => $operator->id,
            'client_id' => Client::query()->where('phone', $phone)->value('id'),
            'status' => 'ringing',
            'started_at' => now(),
        ]);
        $this->remember($channelId, $call, 'operator');

        if (! $this->limits->canPlaceOutbound($number->trunk, $operator->branch, $call->id)) {
            $this->finish($call, 'rejected', ['reason' => 'channels']);

            return;
        }

        $peerId = (string) Str::uuid();
        $this->remember($peerId, $call, 'peer');
        $this->updateMeta($call, ['peer_channel' => $peerId]);
        $this->ari->ring($channelId);

        $ok = $this->ari->originate(
            $number->dialString($phone),
            "peer,{$call->id}",
            $number->number,
            40,
            channelId: $peerId,
        );

        if (! $ok) {
            $this->finish($call, 'failed');

            return;
        }

        $this->broadcast(new CallUpdated($call));
    }

    /**
     * Operator → haydovchi (qaror #59): ilova SIP'da ro'yxatdan o'tmaydi (batareya, NAT) — unga Reverb va FCM orqali
     * "kiruvchi qo'ng'iroq" boradi, javob bersa ilova o'zi answer-{callId} ga qo'ng'iroq qiladi (driverAnswers).
     * Operator kanali javobsiz jiringlaydi: brauzer o'z gudogini chaladi, rad/vaqt o'tsa "band"/"javob yo'q" oladi.
     */
    private function ringDriver(string $channelId, User $operator, int $driverId): void
    {
        $driver = Driver::query()->withoutGlobalScopes()->find($driverId);
        if (! $driver || (! $operator->isSuperadmin() && ! in_array((int) $driver->branch_id, array_map('intval', $operator->branchIds()), true))) {
            $this->ari->hangup($channelId, 'unallocated');

            return;
        }

        $call = Call::query()->create([
            'channel_id' => $channelId,
            'branch_id' => $driver->branch_id,
            'direction' => 'internal',
            'kind' => 'driver',
            'caller' => $operator->sip_extension,
            'callee' => 'dr'.$driver->id,
            'operator_id' => $operator->id,
            'driver_id' => $driver->id,
            'status' => 'ringing',
            'started_at' => now(),
        ]);
        $this->remember($channelId, $call, 'operator');

        $this->alertDriver($call, $driver, $channelId, $operator->name ?: 'Dispetcher');
    }

    /**
     * Haydovchi ilovasiga "kiruvchi qo'ng'iroq" (Reverb + FCM): javob bersa ilova answer-{callId} ga qo'ng'iroq qiladi,
     * chaqiruvchi kanal (operator yoki mijoz ilovasi) shu orada gudok eshitadi.
     */
    private function alertDriver(Call $call, Driver $driver, string $channelId, string $name): void
    {
        // Liniyada emas (Reverb ulanmagan) va push tokeni yo'q — ilovaga yetib bo'lmaydi, 30 soniya kuttirilmaydi
        if ($driver->status === DriverStatus::Offline && ! DeviceToken::query()->where('user_id', $driver->user_id)->exists()) {
            $this->ari->hangup($channelId, 'unallocated');
            $this->finish($call, 'failed', ['reason' => 'unreachable'], $channelId);

            return;
        }

        $seconds = max(5, (int) $this->settings()->get('calls.driver_ring_seconds', $driver->branch_id));
        $this->updateMeta($call, ['driver_ring_until' => now()->addSeconds($seconds)->getTimestamp()]);
        $this->ari->ring($channelId);

        // Reverb ishlamasa ham push yetib borsin (va aksincha)
        $this->broadcast(new IncomingCall($driver->id, $call->id, $name, $seconds));
        SendPushNotification::dispatch($driver->user_id, [
            'type' => 'incoming_call',
            'call_id' => $call->id,
            'operator' => $name,
            'expires_in' => $seconds,
            'sent_at' => now()->getTimestamp(),
        ]);

        $this->broadcast(new CallUpdated($call));
    }

    /**
     * Mijoz ilovasidan qo'ng'iroq (qaror #72): API bergan bir martalik ruxsat bo'yicha — biriktirilgan haydovchiga
     * (operator → haydovchi bilan bir xil yo'l: ilovada kiruvchi qo'ng'iroq) yoki filial dispetcherlari navbatiga.
     * Trunk ishlatilmaydi — liniyalar limitiga kirmaydi.
     *
     * @param  array<string, mixed>  $channel
     */
    private function fromClientApp(array $channel, string $target): void
    {
        $channelId = (string) $channel['id'];
        if (Call::query()->withoutGlobalScopes()->where('channel_id', $channelId)->exists()) {
            return;
        }
        preg_match('#^PJSIP/cl(\d+)-#', (string) ($channel['name'] ?? ''), $m);
        $client = isset($m[1]) ? Client::query()->find((int) $m[1]) : null;
        $grant = $client ? $this->clientExtensions->claim($client->id, $target) : null;

        if (! $client || ! $grant || $client->is_blacklisted) {
            $this->ari->hangup($channelId, 'busy');
            if ($client) {
                $this->clientExtensions->revoke($client->id);
            }

            return;
        }

        $target === 'driver'
            ? $this->clientToDriver($channelId, $client, $grant['order_id'])
            : $this->clientToDispatcher($channelId, $client, $grant['branch_id'], $grant['order_id']);
    }

    private function clientToDriver(string $channelId, Client $client, ?int $orderId): void
    {
        $order = $orderId ? Order::query()->withoutGlobalScopes()->find($orderId) : null;
        $driver = $order?->driver_id ? Driver::query()->withoutGlobalScopes()->with('user')->find($order->driver_id) : null;
        // Ruxsat berilgandan keyin safar tugagan yoki haydovchi almashgan bo'lishi mumkin
        if (! $order || ! $driver || $order->client_id !== $client->id
            || ! in_array($order->status, [OrderStatus::Assigned, OrderStatus::Arrived, OrderStatus::InProgress], true)) {
            $this->ari->hangup($channelId, 'unallocated');
            $this->clientExtensions->revoke($client->id);

            return;
        }

        $call = Call::query()->create([
            'channel_id' => $channelId,
            'branch_id' => $order->branch_id,
            'direction' => 'internal',
            'kind' => 'driver',
            'caller' => $client->phone,
            'callee' => 'dr'.$driver->id,
            'driver_id' => $driver->id,
            'client_id' => $client->id,
            'order_id' => $order->id,
            'status' => 'ringing',
            'started_at' => now(),
            'meta' => ['client_app' => true],
        ]);
        $this->remember($channelId, $call, 'client');

        // Haydovchi ekranida: "Mijoz: Ism" — haydovchi tilida
        $name = __('Client', [], $driver->user->locale ?? null).($client->name ? ': '.$client->name : '');
        $this->alertDriver($call, $driver, $channelId, $name);
    }

    private function clientToDispatcher(string $channelId, Client $client, ?int $branchId, ?int $orderId): void
    {
        $call = Call::query()->create([
            'channel_id' => $channelId,
            'branch_id' => $branchId,
            'direction' => 'inbound',
            'kind' => 'client',
            'caller' => $client->phone,
            'callee' => 'app',
            'client_id' => $client->id,
            'order_id' => $orderId,
            'status' => 'waiting',
            'started_at' => now(),
            'meta' => ['branch_ids' => array_filter([$branchId]), 'client_app' => true],
        ]);
        $this->remember($channelId, $call, 'client');

        $this->ari->answer($channelId);
        // Salomlashuv kerak emas (mijoz ilovada tugmani o'zi bosgan) — operator javob berguncha gudok
        $this->playFor($call, $channelId, [self::RINGBACK], 'ringback');
        $this->offerInTurn($call);
        $this->broadcast(new CallUpdated($call));
    }

    /**
     * Raqam (+998901234567 ko'rinishida) ruxsat ro'yxatidagi andozalardan biriga mosmi (calls.outbound_allowed_patterns).
     */
    private function outboundAllowed(string $phone): bool
    {
        $digits = ltrim($phone, '+');

        foreach ((array) $this->settings()->get('calls.outbound_allowed_patterns') as $pattern) {
            // Panelda xato yozilgan andoza butun chiquvchi aloqani yiqitmasin — u shunchaki mos kelmaydi
            $regex = '/'.str_replace('/', '\/', (string) $pattern).'/';
            if (rescue(fn () => preg_match($regex, $digits) === 1, false, false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Operator uchun chiquvchi qo'ng'iroqlar chastotasi (calls.operator_outbound_limit / _window_minutes; 0 — cheksiz).
     */
    private function outboundWithinLimit(User $operator): bool
    {
        $max = (int) $this->settings()->get('calls.operator_outbound_limit');
        if ($max <= 0) {
            return true;
        }

        $key = "telephony:outbound:{$operator->id}";
        if (RateLimiter::tooManyAttempts($key, $max)) {
            return false;
        }
        RateLimiter::hit($key, max(1, (int) $this->settings()->get('calls.operator_outbound_window_minutes')) * 60);

        return true;
    }

    /**
     * Navbatdagi qo'ng'iroqqa bo'sh operator topib, uning brauzerini jiringlatish.
     */
    private function offerToOperator(Call $call): void
    {
        $call->refresh();
        if ($call->status !== 'waiting' || $call->operator_id || data_get($call->meta, 'closing')) {
            return;
        }

        /** @var list<int> $tried */
        $tried = array_map('intval', (array) data_get($call->meta, 'tried', []));
        $operator = $this->operators->pick($this->queueBranches($call), $tried);
        if (! $operator) {
            // Hamma sinab ko'rilgan bo'lsa — qaytadan aylanadi
            if ($tried !== []) {
                $this->updateMeta($call, ['tried' => []]);
            }

            return;
        }

        $channelId = (string) Str::uuid();
        $call->forceFill(['operator_id' => $operator->id])->save();
        $this->updateMeta($call, ['operator_channel' => $channelId, 'tried' => [...$tried, $operator->id]]);
        $this->remember($channelId, $call, 'operator');

        // Trunkdan kelgan raqam va pozivnoy tashqi/admin matni — qo'shtirnoq yoki <> bilan Caller ID buzilmasin
        $callerId = $call->kind === 'driver'
            ? '"'.Extensions::callerName($call->driver_id ? 'Haydovchi #'.Driver::query()->withoutGlobalScopes()->whereKey($call->driver_id)->value('callsign') : 'Haydovchi').'" <'.Extensions::callerName((string) $call->caller).'>'
            : '"'.Extensions::callerName((string) $call->caller).'" <'.preg_replace('/\D/', '', (string) $call->caller).'>';

        $ok = $this->ari->originate(
            "PJSIP/{$operator->sip_extension}",
            "operator,{$call->id}",
            $callerId,
            (int) $this->settings()->get('calls.operator_ring_seconds'),
            channelId: $channelId,
        );

        if (! $ok) {
            // Asterisk javob bermadi (vaqt tugadi) — kanal baribir yaratilgan bo'lishi mumkin: yopiladi, aks holda operatorda
            // ikkinchi jiringlash bilan birga ochiq qolardi. Operator aybdor emas (o'tkazib yuborilgan/pauza yo'q):
            // keyingi tick'da yana unga taklif qilinishi mumkin
            Cache::forget("ari:ch:{$channelId}");
            $call->forceFill(['operator_id' => null])->save();
            $this->updateMeta($call, ['operator_channel' => null, 'tried' => $tried]);
            $this->ari->hangup($channelId);

            return;
        }

        $this->broadcast(new OperatorCallEvent($operator->id, 'incoming', $this->card($call)));
    }

    private function operatorAnswered(string $operatorChannel, int $callId): void
    {
        $call = Call::query()->withoutGlobalScopes()->find($callId);
        if (! $call || $call->status !== 'waiting' || data_get($call->meta, 'operator_channel') !== $operatorChannel) {
            $this->ari->hangup($operatorChannel);

            return;
        }

        $clientChannel = (string) $call->channel_id;
        $this->ari->stopMoh($clientChannel);
        if ($playback = data_get($call->meta, 'playback')) {
            $this->ari->stopPlayback((string) $playback);
        }

        if (! $this->bridge($call, $clientChannel, $operatorChannel)) {
            $this->requeueAfterFailedBridge($call, $operatorChannel);

            return;
        }
        $call->forceFill([
            'status' => 'answered',
            'answered_at' => now(),
            'wait_seconds' => (int) $call->started_at->diffInSeconds(now()),
        ])->save();

        if ($call->operator_id) {
            $this->broadcast(new OperatorCallEvent($call->operator_id, 'connected', $this->card($call)));
        }
        $this->broadcast(new CallUpdated($call));
    }

    private function peerAnswered(string $peerChannel, int $callId): void
    {
        $call = Call::query()->withoutGlobalScopes()->find($callId);
        if (! $call || $call->status !== 'ringing') {
            $this->ari->hangup($peerChannel);

            return;
        }

        $operatorChannel = (string) $call->channel_id;
        $this->ari->answer($operatorChannel);
        if (! $this->bridge($call, $operatorChannel, $peerChannel)) {
            $this->finish($call, 'failed', ['reason' => 'bridge']);

            return;
        }
        $call->forceFill([
            'status' => 'answered',
            'answered_at' => now(),
            'wait_seconds' => (int) $call->started_at->diffInSeconds(now()),
        ])->save();

        $this->broadcast(new CallUpdated($call));
    }

    private function ttsAnswered(string $channelId, int $callId): void
    {
        $call = Call::query()->withoutGlobalScopes()->find($callId);
        // Originate "muvaffaqiyatsiz" deb yopilgan qo'ng'iroq qayta tirilmaydi: aks holda u faol sanalib, filial avto-qo'ng'iroqlari to'xtardi
        if (! $call || $call->ended_at !== null) {
            $this->ari->hangup($channelId);

            return;
        }

        $call->forceFill(['status' => 'answered', 'answered_at' => now()])->save();
        $this->playTts($call, $channelId);
        $this->broadcast(new CallUpdated($call));
    }

    // ── Boshqa hodisalar ───────────────────────────────────────────────

    private function onChannelDestroyed(string $channelId, int $cause): void
    {
        $ref = Cache::pull("ari:ch:{$channelId}");
        $call = is_array($ref) ? $this->findCall($ref['call']) : Call::query()->withoutGlobalScopes()->where('channel_id', $channelId)->first();
        if (! $call) {
            return;
        }
        if ($call->ended_at) {
            // Yakunlashda ko'prik yopilmay qolgan bo'lsa (Asterisk javob bermagan) — endi yopiladi
            $this->closeBridge($call);

            return;
        }
        $role = is_array($ref) ? $ref['role'] : 'client';

        // Navbatdagi qo'ng'iroq: operator javob bermadi → keyingi operator. Faqat joriy jiringlash kanali:
        // eski (allaqachon almashtirilgan) kanal kech yopilsa, boshqa operatorga "o'tkazib yubordi" yozilmaydi
        if ($role === 'operator' && $call->status === 'waiting') {
            if (data_get($call->meta, 'operator_channel') !== $channelId) {
                return;
            }
            $operatorId = $call->operator_id;
            $call->forceFill(['operator_id' => null])->save();
            $this->updateMeta($call, ['operator_channel' => null]);

            if ($operatorId) {
                $this->broadcast(new OperatorCallEvent($operatorId, 'missed', ['call_id' => $call->id]));
                if ($this->settings()->get('calls.pause_on_missed_ring') && ($user = User::query()->find($operatorId))) {
                    $this->operators->setStatus($user, 'paused');
                } else {
                    // Navbat oxiriga: aks holda keyingi qo'ng'iroq ham yana shu (eng uzoq bo'sh turgan) operatorga borardi
                    User::query()->whereKey($operatorId)->update(['operator_status_at' => now()]);
                }
            }
            $this->offerToOperator($call);

            return;
        }

        $status = $this->endStatus($call, $cause);
        $this->finish($call, $status, [], $channelId);

        if ($call->kind === 'tts' && $status !== 'completed') {
            app(ClientNotifier::class)->retry($call);
        }
    }

    private function endStatus(Call $call, int $cause): string
    {
        return match ($call->status) {
            'answered' => 'completed',
            'blocked', 'rejected' => $call->status,
            // Navbatda kutib, operator javob berguncha qo'yib qo'ydi — "javobsiz" ro'yxati (callback)
            'waiting' => 'missed',
            default => $cause === 17 ? 'busy' : 'no_answer',
        };
    }

    private function onPlaybackFinished(string $playbackId): void
    {
        $ref = Cache::pull("ari:pb:{$playbackId}");
        if (! is_array($ref) || ! ($call = $this->findCall($ref['call'])) || $call->ended_at) {
            return;
        }
        $channelId = (string) $call->channel_id;
        if (data_get($call->meta, 'playback') === $playbackId && data_get($call->meta, 'playback_until')) {
            $this->updateMeta($call, ['playback_until' => null]);
        }

        match ($ref['purpose']) {
            'greeting' => $call->status === 'waiting' && $this->ari->startMoh($channelId),
            'close' => $this->ari->hangup($channelId),
            'ivr' => $this->ivrTimeout($call),
            'auto_offer' => $this->updateMeta($call, ['auto_offer_until' => now()->addSeconds(5)->getTimestamp()]),
            'tts' => $this->ttsPlayed($call, $channelId),
            default => null,
        };
    }

    private function onDtmf(string $channelId, string $digit): void
    {
        $ref = Cache::get("ari:ch:{$channelId}");
        if (! is_array($ref) || ! ($call = $this->findCall($ref['call'])) || $call->ended_at) {
            return;
        }

        // Safardan keyingi baho: 1–5
        if ($call->kind === 'tts' && data_get($call->meta, 'message') === 'rate') {
            if (! in_array($digit, ['1', '2', '3', '4', '5'], true) || data_get($call->meta, 'rated')) {
                return;
            }
            if ($playback = data_get($call->meta, 'playback')) {
                $this->ari->stopPlayback((string) $playback);
            }
            $order = $call->order_id ? Order::query()->withoutGlobalScopes()->find($call->order_id) : null;
            if ($order) {
                app(OrderService::class)->rateByClient($order, (int) $digit);
            }
            $this->updateMeta($call, ['rated' => (int) $digit]);
            $this->playFor($call, $channelId, $this->composer()->phrase('phrase', 'rate-thanks'), 'close');

            return;
        }

        if ($call->kind === 'tts' && $digit === '1') {
            if ($playback = data_get($call->meta, 'playback')) {
                $this->ari->stopPlayback((string) $playback);
            }
            $this->updateMeta($call, ['plays' => 0]);
            $this->playTts($call, $channelId);

            return;
        }

        if (data_get($call->meta, 'auto_offer') && $call->status === 'waiting') {
            if ($digit === '1') {
                $this->placeAutoOrder($call, (int) data_get($call->meta, 'auto_offer'));
            } elseif ($digit === '2') {
                $this->leaveAutoOffer($call);
            }

            return;
        }

        if (data_get($call->meta, 'ivr') && ctype_digit($digit)) {
            $branchId = $call->phone_number_id
                ? PhoneNumber::query()->find($call->phone_number_id)?->branches()->wherePivot('ivr_digit', (int) $digit)->value('branches.id')
                : null;
            if ($branchId) {
                $this->chooseBranch($call, (int) $branchId);
            }
        }
    }

    // ── IVR ────────────────────────────────────────────────────────────

    private function playIvrMenu(Call $call): void
    {
        $composer = $this->composer();
        $parts = [['ivr', 'welcome'], ['ivr', 'choose-branch']];

        $branches = PhoneNumber::query()->find($call->phone_number_id)?->branches()->wherePivotNotNull('ivr_digit')->orderByPivot('ivr_digit')->get() ?? collect();
        foreach ($branches as $branch) {
            /** @var Branch $branch */
            $parts[] = ['branch', "branch-{$branch->id}"];
            $parts[] = ['phrase', 'for'];
            $parts = [...$parts, ...$composer->number((int) $branch->getRelationValue('pivot')->ivr_digit)];
            $parts[] = ['phrase', 'press'];
        }

        $this->playFor($call, (string) $call->channel_id, $composer->media($parts), 'ivr');
    }

    /**
     * Menyu tugadi, tanlov yo'q: bir marta takrorlanadi, so'ng asosiy filial navbatiga.
     */
    private function ivrTimeout(Call $call): void
    {
        if ((int) data_get($call->meta, 'ivr_plays', 0) < 1) {
            $this->updateMeta($call, ['ivr_plays' => 1]);
            $this->playIvrMenu($call);

            return;
        }

        $branchId = PhoneNumber::query()->find($call->phone_number_id)?->branches()->orderByPivot('is_primary', 'desc')->value('branches.id');
        $branchId ? $this->chooseBranch($call, (int) $branchId) : $this->closeWithMessage($call, 'call-later');
    }

    /**
     * Menyu eshittirilmadi (ijro xatosi yoki muddat o'tdi) — qayta urinmasdan asosiy filial navbatiga.
     */
    private function ivrFallback(Call $call): void
    {
        $this->updateMeta($call, ['ivr_plays' => 1]);
        $this->ivrTimeout($call);
    }

    private function chooseBranch(Call $call, int $branchId): void
    {
        if (! $this->anyOpen([$branchId])) {
            $call->forceFill(['branch_id' => $branchId])->save();
            $this->updateMeta($call, ['ivr' => false, 'reason' => 'closed']);
            $call->forceFill(['status' => 'rejected'])->save();
            $this->playFor($call, (string) $call->channel_id, $this->composer()->phrase('ivr', 'closed'), 'close');

            return;
        }

        if ($playback = data_get($call->meta, 'playback')) {
            $this->ari->stopPlayback((string) $playback);
        }

        $call->forceFill(['branch_id' => $branchId])->save();
        $this->updateMeta($call, ['ivr' => false, 'branch_ids' => [$branchId]]);
        $this->playFor($call, (string) $call->channel_id, $this->composer()->phrase('ivr', 'please-wait'), 'greeting');
        $this->offerInTurn($call);
        $this->broadcast(new CallUpdated($call));
    }

    // ── Yordamchilar ───────────────────────────────────────────────────

    private function playTts(Call $call, string $channelId): void
    {
        /** @var list<string> $media */
        $media = array_values((array) data_get($call->meta, 'media', []));
        if ($media === []) {
            $this->ari->hangup($channelId);

            return;
        }

        $this->playFor($call, $channelId, $media, 'tts');
    }

    private function ttsPlayed(Call $call, string $channelId): void
    {
        $plays = (int) data_get($call->meta, 'plays', 0) + 1;
        $this->updateMeta($call, ['plays' => $plays]);

        $plays < self::TTS_REPEATS ? $this->playTts($call, $channelId) : $this->ari->hangup($channelId);
    }

    /**
     * Uzoq kutgan mijozga xabar va qo'ng'iroqni yakunlash — "javobsiz" ro'yxatiga tushadi (operator qayta qo'ng'iroq qiladi).
     */
    private function closeWithMessage(Call $call, string $phrase): void
    {
        $this->updateMeta($call, ['closing' => true, 'reason' => 'timeout']);
        $channelId = (string) $call->channel_id;

        // Jiringlayotgan operator xabar paytida javob berib qo'ymasin; uning kanali yopilishi qayta taklifga olib kelmasin
        if ($operatorChannel = data_get($call->meta, 'operator_channel')) {
            Cache::forget("ari:ch:{$operatorChannel}");
            $this->ari->hangup((string) $operatorChannel);
        }
        if ($operatorId = $call->operator_id) {
            $call->forceFill(['operator_id' => null])->save();
            $this->updateMeta($call, ['operator_channel' => null]);
            $this->broadcast(new OperatorCallEvent($operatorId, 'ended', ['call_id' => $call->id, 'status' => 'missed']));
        }
        $this->ari->stopMoh($channelId);

        $media = $this->composer()->phrase('ivr', $phrase);
        $media === [] ? $this->ari->hangup($channelId) : $this->playFor($call, $channelId, $media, 'close');
    }

    /**
     * @param  list<string>  $media
     */
    private function playFor(Call $call, string $channelId, array $media, string $purpose): void
    {
        if ($media === []) {
            $this->playbackSkipped($call, $channelId, $purpose);

            return;
        }

        $playbackId = (string) Str::uuid();
        Cache::put("ari:pb:{$playbackId}", ['call' => $call->id, 'purpose' => $purpose], self::CHANNEL_TTL);
        $this->updateMeta($call, [
            'playback' => $playbackId,
            'playback_purpose' => $purpose,
            'playback_until' => in_array($purpose, self::GUARDED_PLAYBACKS, true)
                ? now()->addSeconds($purpose === 'greeting' ? 60 : self::PLAYBACK_DEADLINE_SECONDS)->getTimestamp() : null,
        ]);

        if ($this->ari->play($channelId, $media, $playbackId) === null) {
            // PlaybackFinished kelmaydi — kutib o'tirmasdan keyingi qadamga
            Cache::forget("ari:pb:{$playbackId}");
            $this->updateMeta($call, ['playback' => null, 'playback_until' => null]);
            $this->playbackSkipped($call, $channelId, $purpose);
        }
    }

    /**
     * Xabar eshittirilmadi (bo'lak yo'q yoki ARI xatosi): yakuniy xabar — uzish, IVR/taklif — operator navbatiga.
     */
    private function playbackSkipped(Call $call, string $channelId, string $purpose): void
    {
        match ($purpose) {
            'greeting' => $call->status === 'waiting' && $this->ari->startMoh($channelId),
            'close', 'tts' => $this->ari->hangup($channelId),
            'ivr' => $this->ivrFallback($call),
            'auto_offer' => $this->leaveAutoOffer($call),
            default => null,
        };
    }

    /**
     * Ikki kanalni ulash va yozib olish. false — ulanmadi (chaqiruvchi "javob berildi" deb belgilamasin).
     */
    private function bridge(Call $call, string $a, string $b): bool
    {
        $bridgeId = $this->ari->createBridge();
        if (! $bridgeId) {
            return false;
        }

        $recording = "call-{$call->id}";
        $this->updateMeta($call, ['bridge' => $bridgeId, 'recording' => $recording]);
        if (! $this->ari->addToBridge($bridgeId, [$a, $b])) {
            // Yopilmasa reconcileBridges() egasiz ko'prik sifatida yopadi
            $this->ari->destroyBridge($bridgeId);
            $this->updateMeta($call, ['bridge' => null, 'recording' => null]);

            return false;
        }
        $this->ari->recordBridge($bridgeId, $recording, (int) $this->settings()->get('calls.max_recording_seconds'));

        return true;
    }

    /**
     * Operator javob berdi, lekin ulab bo'lmadi: operator kanali uziladi, mijoz navbatda qoladi (keyingi tick).
     */
    private function requeueAfterFailedBridge(Call $call, string $operatorChannel): void
    {
        // Operator kanali yopilishi "javob bermadi" (pauza) deb hisoblanmasin
        Cache::forget("ari:ch:{$operatorChannel}");
        $this->ari->hangup($operatorChannel);

        $operatorId = $call->operator_id;
        $call->forceFill(['operator_id' => null])->save();
        // Operator javob bergan edi — "sinab ko'rilgan" sanalmaydi, keyingi tick'da yana unga taklif qilinishi mumkin
        $tried = array_values(array_diff(array_map('intval', (array) data_get($call->meta, 'tried', [])), [(int) $operatorId]));
        $this->updateMeta($call, ['operator_channel' => null, 'tried' => $tried]);
        if ($operatorId) {
            $this->broadcast(new OperatorCallEvent($operatorId, 'ended', ['call_id' => $call->id, 'status' => 'failed']));
        }
        $this->ari->startMoh((string) $call->channel_id);
    }

    /**
     * Ko'prikni bir marta yopish (keyingi kanal hodisalari 404 so'rov yubormasin).
     */
    private function closeBridge(Call $call): void
    {
        $bridge = data_get($call->meta, 'bridge');
        if (! $bridge || data_get($call->meta, 'bridge_closed')) {
            return;
        }

        if ($this->ari->destroyBridge((string) $bridge)) {
            $this->updateMeta($call, ['bridge_closed' => true]);
        }
    }

    /**
     * Qo'ng'iroqni yakunlash: qolgan kanallarni uzish, ko'prikni yopish, yozuvni navbatga qo'yish, xabar berish.
     *
     * @param  array<string, mixed>  $meta
     */
    private function finish(Call $call, string $status, array $meta = [], ?string $destroyedChannel = null): void
    {
        $now = now();
        $call->forceFill([
            'status' => $status,
            'ended_at' => $now,
            'talk_seconds' => $call->answered_at ? (int) $call->answered_at->diffInSeconds($now) : null,
            'wait_seconds' => $call->wait_seconds ?? ($call->answered_at ? null : (int) $call->started_at->diffInSeconds($now)),
        ])->save();
        if ($meta !== []) {
            $this->updateMeta($call, $meta);
        }

        try {
            foreach (array_filter([$call->channel_id, data_get($call->meta, 'operator_channel'), data_get($call->meta, 'peer_channel')]) as $channel) {
                // Kanal bog'lanishi keshda qoladi: uning yopilish hodisasi qo'ng'iroqni topib, ochiq ko'prikni yopa olsin
                if ($channel !== $destroyedChannel) {
                    $this->ari->hangup((string) $channel);
                }
            }
        } finally {
            // Ko'prik har holda yopiladi — aks holda yozuv davom etib, disk to'lib boradi
            $this->closeBridge($call);
        }
        if (data_get($call->meta, 'recording') && $call->answered_at) {
            ProcessRecording::dispatch($call->id)->delay(now()->addSeconds(5));
        }
        if ($call->client_id && data_get($call->meta, 'client_app')) {
            $this->clientExtensions->revoke($call->client_id);
        }

        if ($call->operator_id) {
            User::query()->whereKey($call->operator_id)->update(['operator_status_at' => $now]);
            $this->broadcast(new OperatorCallEvent($call->operator_id, 'ended', ['call_id' => $call->id, 'status' => $status]));
        }
        // Haydovchi ilovasida jiringlayotgan ekran yopilsin (operator qo'ydi, rad etildi, vaqt o'tdi)
        if ($call->driver_id && ! $call->answered_at && data_get($call->meta, 'driver_ring_until')) {
            $this->notifyRingEnded($call, $status);
        }
        $this->broadcast(new CallUpdated($call));
    }

    private function notifyRingEnded(Call $call, string $status): void
    {
        $driver = Driver::query()->withoutGlobalScopes()->find($call->driver_id);
        if (! $driver) {
            return;
        }

        $this->broadcast(new IncomingCallEnded($driver->id, $call->id, $status));
        SendPushNotification::dispatch($driver->user_id, ['type' => 'incoming_call_ended', 'call_id' => $call->id, 'status' => $status]);
    }

    /**
     * Operator panelidagi pop-up uchun kartochka: mijoz yoki haydovchi.
     *
     * @return array<string, mixed>
     */
    public function card(Call $call): array
    {
        $client = $call->client_id ? Client::query()->find($call->client_id) : null;
        $driver = $call->driver_id ? Driver::query()->withoutGlobalScopes()->with('user')->find($call->driver_id) : null;

        return [
            'call_id' => $call->id,
            'kind' => $call->kind,
            'phone' => $call->kind === 'client' ? $call->caller : null,
            'branch_id' => $call->branch_id,
            'number' => $call->callee,
            'client' => $client ? [
                'id' => $client->id,
                'name' => $client->name,
                'note' => $client->note,
                'orders_count' => $client->orders_count,
            ] : null,
            'driver' => $driver ? ['id' => $driver->id, 'callsign' => $driver->callsign, 'name' => $driver->user->name] : null,
            'order_id' => $call->order_id,
        ];
    }

    /**
     * @return list<string>
     */
    private function greeting(?int $branchId): array
    {
        $composer = $this->composer();

        return ($branchId ? $composer->phrase('greeting', "branch-{$branchId}") : []) ?: $composer->phrase('ivr', 'welcome');
    }

    private function settings(): Settings
    {
        // Settings — scoped: ari:listen har hodisada yangilaydi, panelda o'zgargan qiymat darhol amal qiladi
        return app(Settings::class);
    }

    private function composer(): MessageComposer
    {
        // Daemon uzoq ishlaydi — panelda yangilangan bo'laklar ko'rinishi uchun har safar yangi
        return app(MessageComposer::class);
    }

    /**
     * @param  list<int>  $branchIds
     */
    private function anyOpen(array $branchIds): bool
    {
        return $branchIds === [] || Branch::query()->whereIn('id', $branchIds)->get()->contains(fn (Branch $b) => $b->isOpen());
    }

    private function findCall(mixed $id): ?Call
    {
        return is_numeric($id) ? Call::query()->withoutGlobalScopes()->find((int) $id) : null;
    }

    public function remember(string $channelId, Call $call, string $role): void
    {
        Cache::put("ari:ch:{$channelId}", ['call' => $call->id, 'role' => $role], self::CHANNEL_TTL);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function updateMeta(Call $call, array $values): void
    {
        if ($values === []) {
            return;
        }

        // Bitta jsonb birlashtirish: HTTP tomoni (driverDeclined) yozgan kalitlar butun JSON qayta yozilib yo'qolmasin
        $row = $call->getConnection()->selectOne(
            "UPDATE calls SET meta = COALESCE(meta, '{}'::jsonb) || ?::jsonb, updated_at = ? WHERE id = ? RETURNING meta",
            [json_encode($values, JSON_THROW_ON_ERROR), now(), $call->id],
            false,
        );
        $raw = data_get($row, 'meta');
        $meta = is_string($raw) ? json_decode($raw, true) : null;

        $call->forceFill(['meta' => is_array($meta) ? $meta : [...($call->meta ?? []), ...$values]])->syncOriginalAttribute('meta');
    }

    /**
     * Navbatdagi qo'ng'iroq qaysi filial(lar) operatorlariga taqsimlanadi.
     *
     * @return list<int>
     */
    private function queueBranches(Call $call): array
    {
        return array_values(array_map('intval', (array) data_get($call->meta, 'branch_ids', array_filter([$call->branch_id]))));
    }

    /**
     * Yangi qo'ng'iroq darhol taklif qilinadi, faqat umumiy filialda undan oldin operator kutayotgan qo'ng'iroq bo'lmasa —
     * aks holda u eskisidan oldin bo'sh operatorni egallab olardi; navbat tick() da (eng eskisidan) taqsimlanadi.
     */
    private function offerInTurn(Call $call): void
    {
        $branchIds = $this->queueBranches($call);
        $olderWaiting = Call::query()->withoutGlobalScopes()
            ->where('status', 'waiting')
            ->whereNull('operator_id')
            ->whereNull('ended_at')
            ->where('id', '<', $call->id)
            ->get()
            ->contains(fn (Call $other) => ! data_get($other->meta, 'ivr') && ! data_get($other->meta, 'closing') && ! data_get($other->meta, 'auto_offer')
                && array_intersect($this->queueBranches($other), $branchIds) !== []);

        if (! $olderWaiting) {
            $this->offerToOperator($call);
        }
    }

    /**
     * Reverb ishlamasa qo'ng'iroq to'xtamasin: broadcast xatosi faqat yoziladi (aks holda handle() kanalni uzardi).
     */
    private function broadcast(object $event): void
    {
        rescue(fn () => event($event));
    }
}
