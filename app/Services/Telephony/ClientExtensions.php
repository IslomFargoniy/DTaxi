<?php

namespace App\Services\Telephony;

use App\Models\Call;
use App\Models\Client;
use App\Models\Pjsip\PsEndpoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Mijoz ilovasidan WebRTC qo'ng'iroq (qaror #72): mijozga doimiy ichki raqam berilmaydi. "Qo'ng'iroq" bosilganda
 * cl{clientId} raqami yangi parol bilan yoziladi va bir martalik ruxsat (kimga, qaysi buyurtma) keshga qo'yiladi;
 * Asterisk'da u faqat from-client kontekstida (driver / dispatcher) — boshqa raqam terib bo'lmaydi.
 * Qo'ng'iroq tugagach yoki ruxsat ishlatilmay muddati o'tsa raqam o'chiriladi (Asterisk'da faqat gaplashayotganlar).
 */
class ClientExtensions
{
    public const TARGETS = ['driver', 'dispatcher'];

    /** Ruxsat shu vaqt ichida ishlatilishi kerak (sahifa ochilib, INVITE yuborilguncha) */
    private const GRANT_SECONDS = 60;

    /** Ikki tomon ham WebRTC (ilova, operator brauzeri) — Opus o'girishsiz o'tadi */
    private const CODECS = 'opus,alaw,ulaw';

    public function __construct(private readonly Extensions $extensions, private readonly IceServers $iceServers) {}

    public static function extension(int $clientId): string
    {
        return 'cl'.$clientId;
    }

    /**
     * @param  array{target: string, order_id: int|null, branch_id: int|null}  $grant
     * @return array{extension: string, uri: string, password: string, ws: string, ice_servers: list<array{urls: list<string>, username?: string, credential?: string}>, ice_expires_at: int|null}
     */
    public function grant(Client $client, array $grant, string $host): array
    {
        $extension = self::extension($client->id);
        // Har qo'ng'iroqqa yangi parol: oldingi ruxsatdan qolgan parol bilan qayta qo'ng'iroq qilib bo'lmaydi
        $password = Str::random(24);

        Cache::put($this->key($client->id), $grant, self::GRANT_SECONDS);
        $this->extensions->write($extension, $password, 'from-client', '"'.Extensions::callerName((string) ($client->name ?: $client->phone))."\" <{$extension}>", self::CODECS);

        return [
            'extension' => $extension,
            'uri' => "sip:{$extension}@{$host}",
            'password' => $password,
            'ws' => "wss://{$host}/ws",
            ...$this->iceServers->forId($extension),
        ];
    }

    /**
     * Bir martalik ruxsatni olish (Asterisk'dan qo'ng'iroq kelganda): boshqa manzilga yoki ikkinchi marta — null.
     *
     * @return array{target: string, order_id: int|null, branch_id: int|null}|null
     */
    public function claim(int $clientId, string $target): ?array
    {
        $grant = Cache::pull($this->key($clientId));

        if (! is_array($grant) || ($grant['target'] ?? null) !== $target) {
            return null;
        }

        return [
            'target' => $target,
            'order_id' => is_numeric($grant['order_id'] ?? null) ? (int) $grant['order_id'] : null,
            'branch_id' => is_numeric($grant['branch_id'] ?? null) ? (int) $grant['branch_id'] : null,
        ];
    }

    /**
     * Qo'ng'iroq tugadi: raqam o'chiriladi — agar mijoz shu orada yangi qo'ng'iroq so'ramagan bo'lsa.
     */
    public function revoke(int $clientId): void
    {
        if (! Cache::has($this->key($clientId))) {
            $this->extensions->delete(self::extension($clientId));
        }
    }

    /**
     * Ruxsati o'tgan va ochiq qo'ng'irog'i yo'q mijoz raqamlari (ilova qo'ng'iroq qilmay yopilgan, daemon yakunni o'tkazib yuborgan).
     */
    public function prune(): int
    {
        $pruned = 0;
        foreach (PsEndpoint::query()->where('id', 'like', 'cl%')->pluck('id') as $extension) {
            $clientId = (int) substr((string) $extension, 2);
            $talking = Call::query()->withoutGlobalScopes()->where('client_id', $clientId)
                ->whereNull('ended_at')->where('meta->client_app', true)->exists();
            if (! $talking && ! Cache::has($this->key($clientId))) {
                $this->extensions->delete((string) $extension);
                $pruned++;
            }
        }

        return $pruned;
    }

    private function key(int $clientId): string
    {
        return "client-call:{$clientId}";
    }
}
