<?php

namespace App\Services\Pricing;

use DateTimeInterface;

/**
 * Narx formulasi (reja §5b, qarorlar #21, #33):
 *
 *   narx = boshlang'ich_narx
 *        + max(0, shahar_ichidagi_km − kiritilgan_km) × km_narxi
 *        + shahar_tashqarisidagi_km × tashqi_km_narxi            (yoqilgan bo'lsa)
 *        + max(0, kutish_daqiqasi − bepul_daqiqa) × daqiqa_narxi (yoqilgan bo'lsa)
 *   narx = narx × tungi_koeffitsient                              (yoqilgan va tungi vaqt bo'lsa)
 *   narx = yuqoriga yaxlitlash(narx, qadam)
 *
 * Toza funksiya: haydovchi ilovasi ham aynan shu formulani ishlatadi, sozlamalar API orqali keladi.
 */
class FareCalculator
{
    /**
     * @param  array<string, mixed>  $tariff  Tariff::snapshot()
     * @param  DateTimeInterface  $startedAt  filial vaqt mintaqasida
     */
    public function calculate(
        array $tariff,
        int $insideMeters,
        int $outsideMeters = 0,
        int $waitingSeconds = 0,
        ?DateTimeInterface $startedAt = null,
    ): Fare {
        $baseFare = (int) $tariff['base_fare'];
        $includedKm = (float) $tariff['base_km'];

        $insideKm = $insideMeters / 1000;
        $distance = (int) round(max(0, $insideKm - $includedKm) * (int) $tariff['per_km']);

        $outside = 0;
        if ($tariff['outside_enabled'] ?? false) {
            $outside = (int) round($outsideMeters / 1000 * (int) $tariff['outside_per_km']);
        } else {
            // Qoida o'chiq bo'lsa, shahar tashqarisi ham oddiy km kabi hisoblanadi
            $distance = (int) round(max(0, $insideKm + $outsideMeters / 1000 - $includedKm) * (int) $tariff['per_km']);
        }

        $waiting = 0;
        if ($tariff['waiting_enabled'] ?? false) {
            $paidMinutes = max(0, (int) ceil($waitingSeconds / 60) - (int) $tariff['waiting_free_min']);
            $waiting = $paidMinutes * (int) $tariff['waiting_per_min'];
        }

        $subtotal = $baseFare + $distance + $outside + $waiting;

        $night = 0;
        if (($tariff['night_enabled'] ?? false) && $startedAt && $this->isNight($startedAt, (string) $tariff['night_from'], (string) $tariff['night_to'])) {
            $night = (int) round($subtotal * ((float) $tariff['night_coeff'] - 1));
        }

        $step = max(1, (int) $tariff['rounding_step']);
        $total = (int) (ceil(($subtotal + $night) / $step) * $step);

        return new Fare($total, [
            'base' => $baseFare,
            'distance' => $distance,
            'outside' => $outside,
            'waiting' => $waiting,
            'night' => $night,
            'rounding' => $total - ($subtotal + $night),
        ]);
    }

    /**
     * Tungi oraliq yarim tundan o'tishi mumkin (23:00–06:00).
     */
    private function isNight(DateTimeInterface $at, string $from, string $to): bool
    {
        $time = $at->format('H:i');
        $from = substr($from, 0, 5);
        $to = substr($to, 0, 5);

        return $from <= $to
            ? $time >= $from && $time < $to
            : $time >= $from || $time < $to;
    }
}
