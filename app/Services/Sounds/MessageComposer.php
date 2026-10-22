<?php

namespace App\Services\Sounds;

use App\Models\Order;
use App\Models\SoundClip;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * Ovozli xabarni bo'laklardan yig'ish (reja §5.4): "Sizga oq Cobalt, raqami 01 A 123 BC, taxminan 5 daqiqada yetib keladi".
 * Natija — Asterisk media ro'yxati (sound:dtaxi/...). Yetishmayotgan bo'lak o'tkazib yuboriladi, xabar baribir yetadi.
 */
class MessageComposer
{
    /** @var Collection<string, SoundClip>|null */
    private ?Collection $clips = null;

    /**
     * @return list<string>
     */
    public function assigned(Order $order, ?int $etaMinutes): array
    {
        return $this->media([
            ['phrase', 'to-you'],
            ...$this->car($order->vehicle),
            ...($etaMinutes ? [['phrase', 'about'], ...$this->number($etaMinutes), ['phrase', 'minutes-arrives']] : []),
        ]);
    }

    /**
     * @return list<string>
     */
    public function arrived(Order $order): array
    {
        return $this->media([['phrase', 'driver-waiting'], ...$this->car($order->vehicle)]);
    }

    /**
     * @return list<string>
     */
    public function phrase(string $category, string $key): array
    {
        return $this->media([[$category, $key]]);
    }

    /**
     * Mashina: rang, model, "raqami", davlat raqami.
     *
     * @return list<array{string, string}>
     */
    public function car(?Vehicle $vehicle): array
    {
        if (! $vehicle) {
            return [];
        }

        return [
            ['color', SoundCatalog::key($vehicle->color)],
            ['car', SoundCatalog::key($vehicle->model)],
            ['phrase', 'car-number'],
            ...$this->plate($vehicle->plate_number),
        ];
    }

    /**
     * Davlat raqami (§5.4): viloyat kodi raqamma-raqam, asosiy raqam son sifatida, harflar alohida.
     *   01A123BC → nol bir · a · bir yuz yigirma uch · be · se
     *   01123ABC → nol bir · bir yuz yigirma uch · a · be · se
     *
     * @return list<array{string, string}>
     */
    public function plate(string $plate): array
    {
        $plate = strtolower(Vehicle::normalizePlate($plate));
        $parts = [];

        if (preg_match('/^(\d{2})(.*)$/', $plate, $m)) {
            foreach (str_split($m[1]) as $digit) {
                $parts[] = ['number', $digit];
            }
            $plate = $m[2];
        }

        preg_match_all('/\d+|[a-z]/', $plate, $tokens);
        foreach ($tokens[0] as $token) {
            if (ctype_digit($token)) {
                $parts = [...$parts, ...$this->number((int) $token)];
            } else {
                $parts[] = ['letter', $token];
            }
        }

        return $parts;
    }

    /**
     * Son o'zbekcha: 123 → bir yuz yigirma uch, 1500 → bir ming besh yuz.
     *
     * @return list<array{string, string}>
     */
    public function number(int $n): array
    {
        if ($n === 0) {
            return [['number', '0']];
        }

        $parts = [];
        if ($n >= 1000) {
            $parts = [...$this->number(intdiv($n, 1000)), ['number', '1000']];
            $n %= 1000;
        }
        if ($n >= 100) {
            $parts[] = ['number', (string) intdiv($n, 100)];
            $parts[] = ['number', '100'];
            $n %= 100;
        }
        if ($n >= 10) {
            $parts[] = ['number', (string) (intdiv($n, 10) * 10)];
            $n %= 10;
        }
        if ($n > 0) {
            $parts[] = ['number', (string) $n];
        }

        return $parts;
    }

    /**
     * @param  list<array{string, string}>  $parts
     * @return list<string>
     */
    public function media(array $parts): array
    {
        $this->clips ??= SoundClip::query()->whereNotNull('file_path')->get()->keyBy(fn (SoundClip $c) => "{$c->category}/{$c->key}");

        $media = [];
        foreach ($parts as [$category, $key]) {
            $clip = $this->clips->get("{$category}/{$key}");
            if ($clip?->media()) {
                $media[] = (string) $clip->media();
            }
        }

        return $media;
    }
}
