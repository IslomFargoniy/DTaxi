<?php

namespace App\Services\Telephony;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Asterisk REST Interface (andoza: panel.1call.uz AriClient). Hamma qo'ng'iroqlar `ibtaxi` Stasis ilovasida.
 */
class AriClient
{
    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.asterisk.ari_url'), '/').'/ari')
            ->withBasicAuth((string) config('services.asterisk.ari_user'), (string) config('services.asterisk.ari_password'))
            ->acceptJson()
            ->timeout(5);
    }

    public function answer(string $channelId): bool
    {
        return $this->ok($this->http()->post("channels/{$channelId}/answer"));
    }

    public function ring(string $channelId): bool
    {
        return $this->ok($this->http()->post("channels/{$channelId}/ring"));
    }

    public function hangup(string $channelId, string $reason = 'normal'): bool
    {
        return $this->ok($this->http()->delete("channels/{$channelId}", ['reason' => $reason]));
    }

    /**
     * Bir nechta bo'lakni ketma-ket ijro etish (ARI bitta so'rovda ro'yxatni qabul qiladi).
     *
     * @param  list<string>  $media  masalan ['sound:ibtaxi/phrase/sizni-haydovchi-kutmoqda', 'sound:ibtaxi/number/5']
     */
    public function play(string $channelId, array $media, ?string $playbackId = null): ?string
    {
        $playbackId ??= (string) Str::uuid();
        $response = $this->http()->post("channels/{$channelId}/play/{$playbackId}?".http_build_query(['media' => implode(',', $media)]));

        return $this->ok($response) ? $playbackId : null;
    }

    public function stopPlayback(string $playbackId): bool
    {
        return $this->http()->delete("playbacks/{$playbackId}")->successful();
    }

    public function startMoh(string $channelId): bool
    {
        return $this->ok($this->http()->post("channels/{$channelId}/moh"));
    }

    public function stopMoh(string $channelId): bool
    {
        return $this->ok($this->http()->delete("channels/{$channelId}/moh"));
    }

    public function createBridge(): ?string
    {
        $response = $this->http()->post('bridges', ['type' => 'mixing']);

        return $this->ok($response) ? (string) $response->json('id') : null;
    }

    /**
     * @param  list<string>  $channelIds
     */
    public function addToBridge(string $bridgeId, array $channelIds): bool
    {
        return $this->ok($this->http()->post("bridges/{$bridgeId}/addChannel?".http_build_query(['channel' => implode(',', $channelIds)])));
    }

    public function destroyBridge(string $bridgeId): bool
    {
        return $this->ok($this->http()->delete("bridges/{$bridgeId}"));
    }

    /**
     * Ko'prikni yozib olish (qaror #11): /var/spool/asterisk/recording/<name>.wav
     */
    public function recordBridge(string $bridgeId, string $name): bool
    {
        return $this->ok($this->http()->post("bridges/{$bridgeId}/record?".http_build_query([
            'name' => $name,
            'format' => 'wav',
            'ifExists' => 'overwrite',
        ])));
    }

    /**
     * Chiquvchi kanal: operatorga (PJSIP/op12) yoki trunk orqali mijozga (PJSIP/998901234567@trunk-sarkor).
     *
     * @param  array<string, string>  $variables
     */
    public function originate(string $endpoint, string $appArgs, string $callerId, int $timeout = 30, array $variables = [], ?string $channelId = null): ?string
    {
        $response = $this->http()->post('channels?'.http_build_query(array_filter([
            'endpoint' => $endpoint,
            'app' => config('services.asterisk.app'),
            'appArgs' => $appArgs,
            'callerId' => $callerId,
            'timeout' => $timeout,
            'channelId' => $channelId,
        ])), $variables === [] ? [] : ['variables' => $variables]);

        if (! $this->ok($response)) {
            return null;
        }
        $id = $response->json('id');

        return is_string($id) && $id !== '' ? $id : ($channelId ?? '');
    }

    /**
     * Asterisk va ARI javob beryaptimi. Endpoint'lar ro'yxati bunga yaramaydi: endpoint bo'lmasa ARI 404 qaytaradi.
     */
    public function ping(): bool
    {
        return rescue(fn () => $this->http()->get('asterisk/info')->successful(), false, false);
    }

    /**
     * Brauzer/ilovada ro'yxatdan o'tgan (onlayn) ichki raqamlar.
     *
     * @return list<string>
     */
    public function onlineEndpoints(): array
    {
        return array_keys(array_filter($this->endpointStates(), fn (string $state) => $state === 'online'));
    }

    /**
     * PJSIP endpointlar holati: resource => online | offline | unknown (trunk holati paneli uchun ham).
     *
     * @return array<string, string>
     */
    public function endpointStates(): array
    {
        $response = $this->http()->get('endpoints/PJSIP');
        if (! $response->successful()) {
            return [];
        }

        $states = [];
        foreach ((array) $response->json() as $endpoint) {
            if (is_array($endpoint) && isset($endpoint['resource'])) {
                $states[(string) $endpoint['resource']] = (string) ($endpoint['state'] ?? 'unknown');
            }
        }

        return $states;
    }

    /**
     * Joriy kanallar soni — trunk/filial limitlari uchun (qaror #13).
     *
     * @return list<array<string, mixed>>
     */
    public function channels(): array
    {
        $response = $this->http()->get('channels');

        return $response->successful() ? array_values((array) $response->json()) : [];
    }

    private function ok(Response $response): bool
    {
        if (! $response->successful()) {
            Log::warning('ARI', ['status' => $response->status(), 'url' => (string) $response->effectiveUri(), 'body' => $response->body()]);
        }

        return $response->successful();
    }
}
