<?php

namespace App\Services\Telephony;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Asterisk REST Interface (andoza: panel.1call.uz AriClient). Hamma qo'ng'iroqlar `ibtaxi` Stasis ilovasida.
 *
 * Usullar istisno tashlamaydi: Asterisk javob bermasa false/null qaytadi — chaqiruvchi holatni orqaga qaytara oladi
 * (aks holda masalan operator "band" bo'lib qolardi) va daemon tsikli to'xtamaydi.
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(string $method, string $url, array $data = []): ?Response
    {
        try {
            return match ($method) {
                'get' => $this->http()->get($url),
                'delete' => $this->http()->delete($url, $data),
                default => $this->http()->post($url, $data),
            };
        } catch (ConnectionException|RequestException $e) {
            Log::warning('ARI ulanmadi', ['method' => $method, 'url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function answer(string $channelId): bool
    {
        return $this->ok($this->send('post', "channels/{$channelId}/answer"));
    }

    public function ring(string $channelId): bool
    {
        return $this->ok($this->send('post', "channels/{$channelId}/ring"));
    }

    public function hangup(string $channelId, string $reason = 'normal'): bool
    {
        return $this->ok($this->send('delete', "channels/{$channelId}", ['reason' => $reason]));
    }

    /**
     * Bir nechta bo'lakni ketma-ket ijro etish (ARI bitta so'rovda ro'yxatni qabul qiladi).
     *
     * @param  list<string>  $media  masalan ['sound:ibtaxi/phrase/sizni-haydovchi-kutmoqda', 'sound:ibtaxi/number/5']
     */
    public function play(string $channelId, array $media, ?string $playbackId = null): ?string
    {
        $playbackId ??= (string) Str::uuid();
        $response = $this->send('post', "channels/{$channelId}/play/{$playbackId}?".http_build_query(['media' => implode(',', $media)]));

        return $this->ok($response) ? $playbackId : null;
    }

    public function stopPlayback(string $playbackId): bool
    {
        return (bool) $this->send('delete', "playbacks/{$playbackId}")?->successful();
    }

    public function startMoh(string $channelId): bool
    {
        return $this->ok($this->send('post', "channels/{$channelId}/moh"));
    }

    public function stopMoh(string $channelId): bool
    {
        return $this->ok($this->send('delete', "channels/{$channelId}/moh"));
    }

    public function createBridge(): ?string
    {
        $response = $this->send('post', 'bridges', ['type' => 'mixing']);

        return $this->ok($response) ? (string) $response?->json('id') : null;
    }

    /**
     * @param  list<string>  $channelIds
     */
    public function addToBridge(string $bridgeId, array $channelIds): bool
    {
        return $this->ok($this->send('post', "bridges/{$bridgeId}/addChannel?".http_build_query(['channel' => implode(',', $channelIds)])));
    }

    public function destroyBridge(string $bridgeId): bool
    {
        return $this->ok($this->send('delete', "bridges/{$bridgeId}"));
    }

    /**
     * Ko'prikni yozib olish (qaror #11): /var/spool/asterisk/recording/<name>.wav
     * $maxSeconds — ko'prik biror sabab bilan yopilmay qolsa ham yozuv cheksiz o'smasin (0 — cheksiz).
     */
    public function recordBridge(string $bridgeId, string $name, int $maxSeconds = 0): bool
    {
        return $this->ok($this->send('post', "bridges/{$bridgeId}/record?".http_build_query([
            'name' => $name,
            'format' => 'wav',
            'ifExists' => 'overwrite',
            'maxDurationSeconds' => max(0, $maxSeconds),
        ])));
    }

    /**
     * Chiquvchi kanal: operatorga (PJSIP/op12) yoki trunk orqali mijozga (PJSIP/998901234567@trunk-sarkor).
     *
     * @param  array<string, string>  $variables
     */
    public function originate(string $endpoint, string $appArgs, string $callerId, int $timeout = 30, array $variables = [], ?string $channelId = null): ?string
    {
        $response = $this->send('post', 'channels?'.http_build_query(array_filter([
            'endpoint' => $endpoint,
            'app' => config('services.asterisk.app'),
            'appArgs' => $appArgs,
            'callerId' => $callerId,
            'timeout' => $timeout,
            'channelId' => $channelId,
        ])), $variables === [] ? [] : ['variables' => $variables]);

        if (! $response || ! $this->ok($response)) {
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
        return (bool) $this->send('get', 'asterisk/info')?->successful();
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
        $response = $this->send('get', 'endpoints/PJSIP');
        if (! $response?->successful()) {
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
     * Asterisk'dagi joriy kanallar. null — ro'yxatni olib bo'lmadi (bo'sh ro'yxat bilan adashtirmaslik kerak:
     * aks holda solishtirish hamma qo'ng'iroqni "tugagan" deb yopardi).
     *
     * @return list<array<string, mixed>>|null
     */
    public function channels(): ?array
    {
        return $this->list('channels');
    }

    /**
     * Asterisk'dagi joriy ko'priklar (id, channels, ...). null — ro'yxatni olib bo'lmadi.
     *
     * @return list<array<string, mixed>>|null
     */
    public function bridges(): ?array
    {
        return $this->list('bridges');
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function list(string $resource): ?array
    {
        $response = $this->send('get', $resource);
        if (! $response?->successful() || ! is_array($response->json())) {
            return null;
        }

        /** @var list<array<string, mixed>> */
        return array_values(array_filter($response->json(), 'is_array'));
    }

    private function ok(?Response $response): bool
    {
        if (! $response) {
            return false;
        }
        if (! $response->successful()) {
            Log::warning('ARI', ['status' => $response->status(), 'url' => (string) $response->effectiveUri(), 'body' => $response->body()]);
        }

        return $response->successful();
    }
}
