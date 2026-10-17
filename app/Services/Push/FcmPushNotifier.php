<?php

namespace App\Services\Push;

use App\Enums\IntegrationProvider;
use App\Models\DeviceToken;
use App\Models\Integration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Firebase Cloud Messaging (HTTP v1). Kalit — firmaning o'z service account JSON'i (Integratsiyalar).
 * Faqat "data" xabar yuboriladi: ilova o'zi ovozli signal va to'liq ekranli taklifni ko'rsatadi.
 */
class FcmPushNotifier
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * @param  array<string, scalar>  $data
     */
    public function sendToUser(int $userId, array $data): int
    {
        $account = $this->serviceAccount();
        if (! $account) {
            return 0;
        }

        $sent = 0;
        foreach (DeviceToken::query()->where('user_id', $userId)->pluck('token') as $token) {
            $response = Http::withToken($this->accessToken($account))
                ->timeout(5)
                ->post("https://fcm.googleapis.com/v1/projects/{$account['project_id']}/messages:send", [
                    'message' => [
                        'token' => $token,
                        'data' => array_map('strval', $data),
                        'android' => ['priority' => 'HIGH', 'ttl' => '30s'],
                    ],
                ]);

            if ($response->successful()) {
                $sent++;
            } elseif (in_array($response->json('error.status'), ['NOT_FOUND', 'UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
                // Ilova o'chirilgan yoki token eskirgan
                DeviceToken::query()->where('token', $token)->delete();
            } else {
                Log::warning('FCM xatosi', ['status' => $response->status(), 'body' => $response->body()]);
            }
        }

        return $sent;
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
