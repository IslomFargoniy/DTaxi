<?php

namespace App\Services\Telephony;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * WebRTC ICE serverlari (operator brauzeri, haydovchi ilovasi): STUN doim, TURN — faqat coturn
 * o'rnatilgan bo'lsa (install.sh --turn). Server NAT ortida yoki foydalanuvchi tarmog'i UDP'ni
 * yopsa ovoz TURN orqali (TCP/TLS) o'tadi.
 *
 * TURN paroli bazada saqlanmaydi: coturn use-auth-secret (TURN REST API) — login "<muddat>:<id>",
 * parol base64(hmac-sha1(secret, login)); muddati o'tgach coturn o'zi rad etadi.
 */
class IceServers
{
    /**
     * @return array{ice_servers: list<array{urls: list<string>, username?: string, credential?: string}>, ice_expires_at: int|null}
     */
    public function for(User $user): array
    {
        return $this->forId((string) $user->id);
    }

    /**
     * @param  string  $id  TURN login ichidagi egasi: foydalanuvchi id yoki mijozning vaqtinchalik raqami (cl{N})
     * @return array{ice_servers: list<array{urls: list<string>, username?: string, credential?: string}>, ice_expires_at: int|null}
     */
    public function forId(string $id): array
    {
        $servers = [];
        $stun = trim((string) config('services.webrtc.stun'));
        if ($stun !== '') {
            $servers[] = ['urls' => [$stun]];
        }

        $urls = $this->turnUrls();
        $secret = (string) config('services.webrtc.turn_secret');
        if ($urls === [] || $secret === '') {
            return ['ice_servers' => $servers, 'ice_expires_at' => null];
        }

        $expiresAt = Carbon::now()->getTimestamp() + max(600, (int) config('services.webrtc.turn_ttl'));
        $username = $expiresAt.':'.$id;
        $servers[] = [
            'urls' => $urls,
            'username' => $username,
            'credential' => self::credential($secret, $username),
        ];

        return ['ice_servers' => $servers, 'ice_expires_at' => $expiresAt];
    }

    public static function credential(string $secret, string $username): string
    {
        return base64_encode(hash_hmac('sha1', $username, $secret, true));
    }

    /**
     * @return list<string>
     */
    private function turnUrls(): array
    {
        $urls = array_map('trim', explode(',', (string) config('services.webrtc.turn_url')));

        return array_values(array_filter($urls, fn (string $url) => str_starts_with($url, 'turn:') || str_starts_with($url, 'turns:')));
    }
}
