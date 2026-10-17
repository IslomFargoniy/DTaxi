<?php

namespace App\Services\Telephony;

use App\Events\CallUpdated;
use App\Events\OperatorCallEvent;
use App\Jobs\ProcessRecording;
use App\Models\Branch;
use App\Models\Call;
use App\Models\Client;
use App\Models\Driver;
use App\Models\Order;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Services\Sounds\MessageComposer;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Qo'ng'iroq markazi — ARI hodisalari bo'yicha holat mashinasi (andoza: panel.1call.uz Stasis handlerlari).
 * Butun mantiq PHP'da: dialplan faqat Stasis(ibtaxi,...) ga yuboradi.
 *
 * Stasis argumentlari:
 *   inbound,<DID>   — trunkdan kiruvchi (mijoz)
 *   internal,<exten> — operator/haydovchi brauzer/ilovadan tergan raqam
 *   operator,<callId> — navbatdagi qo'ng'iroq uchun operatorga yuborilgan kanal javob berdi
 *   peer,<callId>   — operator chaqirgan tomon (mijoz/haydovchi) javob berdi
 *   tts,<callId>    — avto-qo'ng'iroqda mijoz javob berdi
 *
 * Kanal roli keshda: client (navbatdagi / TTS tomon), operator, peer.
 */
class CallCenter
{
    private const CHANNEL_TTL = 14400;

    /** Avto-qo'ng'iroq xabari necha marta takrorlanadi (§5.4) */
    private const TTS_REPEATS = 2;

    public function __construct(
        private AriClient $ari,
        private OperatorPool $operators,
        private ChannelLimits $limits,
        private Settings $settings,
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
            Log::error('CallCenter: '.$e->getMessage(), ['event' => data_get($event, 'type'), 'exception' => $e]);
        }
    }

    /**
     * Har 2 soniyada: navbatdagi qo'ng'iroqlarni bo'sh operatorlarga taqsimlash, uzoq kutganlarni yakunlash.
     */
    public function tick(): void
    {
        $waiting = Call::query()->withoutGlobalScopes()
            ->where('status', 'waiting')
            ->whereNull('operator_id')
            ->orderBy('started_at')
            ->get();

        $maxWait = (int) $this->settings->get('calls.max_wait_seconds');

        foreach ($waiting as $call) {
            if (data_get($call->meta, 'ivr') || data_get($call->meta, 'closing')) {
                continue;
            }

            if ($maxWait > 0 && $call->started_at->diffInSeconds(now()) >= $maxWait) {
                $this->closeWithMessage($call, 'call-later');

                continue;
            }

            $this->offerToOperator($call);
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
        $this->remember($channelId, $call, 'client');

        if ($client?->is_blacklisted) {
            $call->forceFill(['status' => 'blocked'])->save();
            $this->ari->hangup($channelId, 'busy');

            return;
        }

        if (! $this->limits->canAcceptInbound($number->trunk, $branchId ? Branch::query()->find($branchId) : null)) {
            $this->updateMeta($call, ['reason' => 'channels']);
            $call->forceFill(['status' => 'rejected'])->save();
            $this->ari->answer($channelId);
            $this->playFor($call, $channelId, $this->composer()->phrase('ivr', 'lines-busy'), 'close');

            return;
        }

        $this->ari->answer($channelId);

        if ($ivr) {
            $this->playIvrMenu($call);
        } else {
            $this->playFor($call, $channelId, $this->greeting($branchId), 'greeting');
            $this->offerToOperator($call);
        }

        CallUpdated::dispatch($call);
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
            $this->driverToDispatcher($channelId, $endpoint);

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
        $this->playFor($call, $channelId, $this->composer()->phrase('ivr', 'please-wait'), 'greeting');
        $this->offerToOperator($call);
        CallUpdated::dispatch($call);
    }

    /**
     * Operator tergan raqam: dr{N} — haydovchiga (WebRTC), boshqasi — trunk orqali mijozga (callback).
     */
    private function operatorDials(string $channelId, User $operator, string $exten): void
    {
        $toDriver = (bool) preg_match('/^dr(\d+)$/', $exten, $dm);
        $phone = $toDriver ? $exten : Client::normalizePhone($exten);
        $number = $toDriver ? null : PhoneNumber::forBranch($operator->branch_id);

        if (! $toDriver && ! $number) {
            $this->ari->hangup($channelId, 'congestion');

            return;
        }

        $call = Call::query()->create([
            'channel_id' => $channelId,
            'branch_id' => $operator->branch_id,
            'direction' => $toDriver ? 'internal' : 'outbound',
            'kind' => $toDriver ? 'driver' : 'callback',
            'caller' => $operator->sip_extension,
            'callee' => $phone,
            'phone_number_id' => $number?->id,
            'sip_trunk_id' => $number?->sip_trunk_id,
            'operator_id' => $operator->id,
            'driver_id' => $toDriver ? (int) $dm[1] : null,
            'client_id' => $toDriver ? null : Client::query()->where('phone', $phone)->value('id'),
            'status' => 'ringing',
            'started_at' => now(),
        ]);
        $this->remember($channelId, $call, 'operator');

        if ($number && ! $this->limits->canPlaceOutbound($number->trunk, $operator->branch)) {
            $this->finish($call, 'rejected', ['reason' => 'channels']);

            return;
        }

        $peerId = (string) Str::uuid();
        $this->remember($peerId, $call, 'peer');
        $this->updateMeta($call, ['peer_channel' => $peerId]);
        $this->ari->ring($channelId);

        $ok = $this->ari->originate(
            $toDriver ? "PJSIP/{$exten}" : $number->dialString($phone),
            "peer,{$call->id}",
            $toDriver ? "\"Dispetcher\" <{$operator->sip_extension}>" : $number->number,
            40,
            channelId: $peerId,
        );

        if (! $ok) {
            $this->finish($call, 'failed');

            return;
        }

        CallUpdated::dispatch($call);
    }

    /**
     * Navbatdagi qo'ng'iroqqa bo'sh operator topib, uning brauzerini jiringlatish.
     */
    private function offerToOperator(Call $call): void
    {
        $call->refresh();
        if ($call->status !== 'waiting' || $call->operator_id) {
            return;
        }

        /** @var list<int> $tried */
        $tried = array_map('intval', (array) data_get($call->meta, 'tried', []));
        /** @var list<int> $branchIds */
        $branchIds = array_map('intval', (array) data_get($call->meta, 'branch_ids', array_filter([$call->branch_id])));

        $operator = $this->operators->pick($branchIds, $tried);
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

        $callerId = $call->kind === 'driver'
            ? '"'.($call->driver_id ? 'Haydovchi #'.Driver::query()->withoutGlobalScopes()->whereKey($call->driver_id)->value('callsign') : 'Haydovchi').'" <'.$call->caller.'>'
            : "\"{$call->caller}\" <".preg_replace('/\D/', '', (string) $call->caller).'>';

        $ok = $this->ari->originate(
            "PJSIP/{$operator->sip_extension}",
            "operator,{$call->id}",
            $callerId,
            (int) $this->settings->get('calls.operator_ring_seconds'),
            channelId: $channelId,
        );

        if (! $ok) {
            $call->forceFill(['operator_id' => null])->save();
            $this->updateMeta($call, ['operator_channel' => null]);

            return;
        }

        OperatorCallEvent::dispatch($operator->id, 'incoming', $this->card($call));
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

        $this->bridge($call, $clientChannel, $operatorChannel);
        $call->forceFill([
            'status' => 'answered',
            'answered_at' => now(),
            'wait_seconds' => (int) $call->started_at->diffInSeconds(now()),
        ])->save();

        if ($call->operator_id) {
            OperatorCallEvent::dispatch($call->operator_id, 'connected', $this->card($call));
        }
        CallUpdated::dispatch($call);
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
        $this->bridge($call, $operatorChannel, $peerChannel);
        $call->forceFill([
            'status' => 'answered',
            'answered_at' => now(),
            'wait_seconds' => (int) $call->started_at->diffInSeconds(now()),
        ])->save();

        CallUpdated::dispatch($call);
    }

    private function ttsAnswered(string $channelId, int $callId): void
    {
        $call = Call::query()->withoutGlobalScopes()->find($callId);
        if (! $call) {
            $this->ari->hangup($channelId);

            return;
        }

        $call->forceFill(['status' => 'answered', 'answered_at' => now()])->save();
        $this->playTts($call, $channelId);
        CallUpdated::dispatch($call);
    }

    // ── Boshqa hodisalar ───────────────────────────────────────────────

    private function onChannelDestroyed(string $channelId, int $cause): void
    {
        $ref = Cache::pull("ari:ch:{$channelId}");
        $call = is_array($ref) ? $this->findCall($ref['call']) : Call::query()->withoutGlobalScopes()->where('channel_id', $channelId)->first();
        if (! $call || $call->ended_at) {
            return;
        }
        $role = is_array($ref) ? $ref['role'] : 'client';

        // Navbatdagi qo'ng'iroq: operator javob bermadi → keyingi operator
        if ($role === 'operator' && $call->status === 'waiting') {
            $operatorId = $call->operator_id;
            $call->forceFill(['operator_id' => null])->save();
            $this->updateMeta($call, ['operator_channel' => null]);

            if ($operatorId) {
                OperatorCallEvent::dispatch($operatorId, 'missed', ['call_id' => $call->id]);
                if ($this->settings->get('calls.pause_on_missed_ring') && ($user = User::query()->find($operatorId))) {
                    $this->operators->setStatus($user, 'paused');
                }
            }
            $this->offerToOperator($call);

            return;
        }

        $status = match ($call->status) {
            'answered' => 'completed',
            'blocked', 'rejected' => $call->status,
            // Navbatda kutib, operator javob berguncha qo'yib qo'ydi — "javobsiz" ro'yxati (callback)
            'waiting' => 'missed',
            default => $cause === 17 ? 'busy' : 'no_answer',
        };

        $this->finish($call, $status, [], $channelId);

        if ($call->kind === 'tts' && $status !== 'completed') {
            app(ClientNotifier::class)->retry($call);
        }
    }

    private function onPlaybackFinished(string $playbackId): void
    {
        $ref = Cache::pull("ari:pb:{$playbackId}");
        if (! is_array($ref) || ! ($call = $this->findCall($ref['call'])) || $call->ended_at) {
            return;
        }
        $channelId = (string) $call->channel_id;

        match ($ref['purpose']) {
            'greeting' => $call->status === 'waiting' && $this->ari->startMoh($channelId),
            'close' => $this->ari->hangup($channelId),
            'ivr' => $this->ivrTimeout($call),
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

        if ($call->kind === 'tts' && $digit === '1') {
            if ($playback = data_get($call->meta, 'playback')) {
                $this->ari->stopPlayback((string) $playback);
            }
            $this->updateMeta($call, ['plays' => 0]);
            $this->playTts($call, $channelId);

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

    private function chooseBranch(Call $call, int $branchId): void
    {
        if ($playback = data_get($call->meta, 'playback')) {
            $this->ari->stopPlayback((string) $playback);
        }

        $call->forceFill(['branch_id' => $branchId])->save();
        $this->updateMeta($call, ['ivr' => false, 'branch_ids' => [$branchId]]);
        $this->playFor($call, (string) $call->channel_id, $this->composer()->phrase('ivr', 'please-wait'), 'greeting');
        $this->offerToOperator($call);
        CallUpdated::dispatch($call);
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
            if ($purpose === 'greeting' && $call->status === 'waiting') {
                $this->ari->startMoh($channelId);
            } elseif ($purpose === 'close') {
                $this->ari->hangup($channelId);
            } elseif ($purpose === 'ivr') {
                $this->ivrTimeout($call);
            }

            return;
        }

        $playbackId = (string) Str::uuid();
        Cache::put("ari:pb:{$playbackId}", ['call' => $call->id, 'purpose' => $purpose], self::CHANNEL_TTL);
        $this->updateMeta($call, ['playback' => $playbackId]);
        $this->ari->play($channelId, $media, $playbackId);
    }

    private function bridge(Call $call, string $a, string $b): void
    {
        $bridgeId = $this->ari->createBridge();
        if (! $bridgeId) {
            return;
        }

        $this->ari->addToBridge($bridgeId, [$a, $b]);
        $recording = "call-{$call->id}";
        $this->ari->recordBridge($bridgeId, $recording);
        $this->updateMeta($call, ['bridge' => $bridgeId, 'recording' => $recording]);
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

        foreach (array_filter([$call->channel_id, data_get($call->meta, 'operator_channel'), data_get($call->meta, 'peer_channel')]) as $channel) {
            if ($channel !== $destroyedChannel) {
                Cache::forget("ari:ch:{$channel}");
                $this->ari->hangup((string) $channel);
            }
        }
        if ($bridge = data_get($call->meta, 'bridge')) {
            $this->ari->destroyBridge((string) $bridge);
        }
        if (data_get($call->meta, 'recording') && $call->answered_at) {
            ProcessRecording::dispatch($call->id)->delay(now()->addSeconds(5));
        }

        if ($call->operator_id) {
            User::query()->whereKey($call->operator_id)->update(['operator_status_at' => $now]);
            OperatorCallEvent::dispatch($call->operator_id, 'ended', ['call_id' => $call->id, 'status' => $status]);
        }
        CallUpdated::dispatch($call);
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

    private function composer(): MessageComposer
    {
        // Daemon uzoq ishlaydi — panelda yangilangan bo'laklar ko'rinishi uchun har safar yangi
        return app(MessageComposer::class);
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
        $call->forceFill(['meta' => [...($call->meta ?? []), ...$values]])->save();
    }
}
