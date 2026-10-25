<?php

namespace App\Services\Push;

use App\Enums\IntegrationProvider;
use App\Models\ClientDeviceToken;
use App\Models\DeviceToken;
use App\Models\Integration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Firebase Cloud Messaging (HTTP v1). Kalit — firmaning o'z service account JSON'i (Integratsiyalar).
 * Haydovchiga faqat "data" xabar (ilova o'zi ovozli signal va to'liq ekranli taklifni ko'rsatadi), mijozga — oddiy bildirishnoma.
 */
class FcmPushNotifier
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * @param  array<string, scalar|null>  $data
     */
    public function sendToUser(int $userId, array $data): int
    {
        return $this->deliver(
            DeviceToken::query()->where('user_id', $userId)->pluck('token')->all(),
            ['data' => self::stringify($data), 'android' => ['priority' => 'HIGH', 'ttl' => '30s']],
            fn (string $token) => DeviceToken::query()->where('token', $token)->delete(),
        );
    }

    /**
     * Mijoz ilovasi (qaror #71): oddiy bildirishnoma — ilova yopiq bo'lsa ham telefon o'zi ko'rsatadi.
     *
     * @param  array<string, scalar|null>  $data
     */
    public function sendToClient(int $clientId, string $title, string $body, array $data = []): int
    {
        return $this->deliver(
            ClientDeviceToken::query()->where('client_id', $clientId)->pluck('token')->all(),
            [
                'notification' => ['title' => $title, 'body' => $body],
                'data' => self::stringify($data),
                'android' => ['priority' => 'HIGH', 'notification' => ['channel_id' => 'orders']],
            ],
            fn (string $token) => ClientDeviceToken::query()->where('token', $token)->delete(),
        );
    }

    /**
     * @param  array<string>  $tokens
     * @param  array<string, mixed>  $message
     * @param  callable(string): mixed  $forget  eskirgan token (ilova o'chirilgan) o'chiriladi
     */
    private function deliver(array $tokens, array $message, callable $forget): int
    {
        $account = $this->serviceAccount();
        if (! $account || $tokens === []) {
            return 0;
        }

        $sent = 0;
        foreach ($tokens as $token) {
            $response = Http::withToken($this->accessToken($account))
                ->timeout(5)
                ->post("https://fcm.googleapis.com/v1/projects/{$account['project_id']}/messages:send", [
                    'message' => ['token' => $token, ...$message],
                ]);

            if ($response->successful()) {
                $sent++;
            } elseif (in_array($response->json('error.status'), ['NOT_FOUND', 'UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
                $forget($token);
            } else {
                Log::warning('FCM xatosi', ['status' => $response->status(), 'body' => $response->body()]);
            }
        }

        return $sent;
    }

    /**
     * FCM data faqat matn oladi. null kalit umuman yuborilmaydi: strval(null) = "" ilovada son sifatida o'qilmaydi.
     *
     * @param  array<string, scalar|null>  $data
     * @return array<string, string>
     */
    public static function stringify(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            $result[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return $result;
    }

    /**
     * @return array{project_id: string, client_email: string, private_key: string, token_uri?: string}|null
     */
    private function serviceAccount(): ?array
    {
        $json = Integration::credential(IntegrationProvider::Firebase, 'service_account');
        $account = $json ? json_decode($json, true) : null;

        return is_array($account) && isset($account['project_id'], $account['client_email'], $account['private_key'])
            ? $account
            : null;
    }

    /**
     * @param  array{project_id: string, client_email: string, private_key: string, token_uri?: string}  $account
     */
    private function accessToken(array $account): string
    {
        return Cache::remember('fcm-token:'.md5($account['client_email']), now()->addMinutes(50), function () use ($account) {
            $tokenUri = $account['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $now = time();
            $segments = [
                $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
                $this->base64Url(json_encode([
                    'iss' => $account['client_email'],
                    'scope' => self::SCOPE,
                    'aud' => $tokenUri,
                    'iat' => $now,
                    'exp' => $now + 3600,
                ], JSON_THROW_ON_ERROR)),
            ];

            if (! openssl_sign(implode('.', $segments), $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Firebase service account kaliti noto\'g\'ri.');
            }
            $segments[] = $this->base64Url($signature);

            return (string) Http::asForm()->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ])->throw()->json('access_token');
        });
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
