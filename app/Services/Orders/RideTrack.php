<?php

namespace App\Services\Orders;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Haydovchining yo'ldagi GPS treki (driver_locations). Imkonsiz sakrashlar (soatiga ~200 km dan tez — GPS xatosi
 * yoki bitta akkaunt ikki telefonda) tashlab yuboriladi: aks holda bitta sakrash yuzlab km "trek" va noto'g'ri
 * komissiya, xaritada esa shahar bo'ylab chiziq beradi.
 */
final class RideTrack
{
    /** Xaritaga yuboriladigan nuqtalar chegarasi: ilova uchun yengil, yo'l shakli saqlanadi */
    public const MAX_POINTS = 300;

    /**
     * Sakrashsiz bo'laklar: har nuqta va undan oldingisi orasidagi masofa (d) va vaqt (dt).
     */
    private const SEGMENTS = <<<'SQL'
        SELECT location, recorded_at,
               ST_Distance(location, LAG(location) OVER w) AS d,
               EXTRACT(EPOCH FROM recorded_at - LAG(recorded_at) OVER w) AS dt
        FROM driver_locations
        WHERE driver_id = ? AND recorded_at BETWEEN ? AND ?
        WINDOW w AS (ORDER BY recorded_at)
        SQL;

    private const PLAUSIBLE = 'dt > 0 AND d <= 55 * dt + 30';

    /**
     * Trek uzunligi (metr); nuqta bo'lmasa — null.
     */
    public function length(int $driverId, DateTimeInterface $from, DateTimeInterface $to): ?int
    {
        $length = DB::scalar(
            'SELECT SUM(d) FROM ('.self::SEGMENTS.') AS segments WHERE '.self::PLAUSIBLE,
            [$driverId, $from, $to],
        );

        return $length === null ? null : (int) round((float) $length);
    }

    /**
     * Xarita uchun soddalashtirilgan chiziq: [[lat, lng], ...], ko'pi bilan MAX_POINTS nuqta.
     * Nuqtalar o'chirilgan (locations:prune) yoki umuman yo'q bo'lsa — bo'sh ro'yxat.
     *
     * @return list<array{0: float, 1: float}>
     */
    public function polyline(int $driverId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        // Douglas-Peucker: ~1 m dan boshlab, nuqtalar chegaradan oshsa bag'rikenglik ikki barobar oshiriladi
        $tolerance = 0.00001;
        $geoJson = null;
        for ($i = 0; $i < 16; $i++) {
            $row = DB::selectOne(
                'WITH pts AS (SELECT location, recorded_at FROM ('.self::SEGMENTS.') AS s WHERE d IS NULL OR '.self::PLAUSIBLE.'),
                      line AS (SELECT ST_Simplify(ST_MakeLine(location::geometry ORDER BY recorded_at), ?) AS g FROM pts)
                 SELECT ST_AsGeoJSON(g) AS json, ST_NPoints(g) AS n FROM line WHERE g IS NOT NULL',
                [$driverId, $from, $to, $tolerance],
            );
            if (! $row) {
                return [];
            }
            $geoJson = (string) $row->json;
            if ((int) $row->n <= self::MAX_POINTS) {
                break;
            }
            $tolerance *= 2;
        }

        /** @var array{type?: string, coordinates?: list<list<float>>|list<float>} $geometry */
        $geometry = json_decode((string) $geoJson, true, flags: JSON_THROW_ON_ERROR);
        $coordinates = $geometry['coordinates'] ?? [];
        // Bitta nuqta bo'lsa PostGIS Point qaytaradi — chiziq chizib bo'lmaydi
        if (($geometry['type'] ?? '') !== 'LineString') {
            return [];
        }

        $points = [];
        foreach ($coordinates as $c) {
            if (is_array($c)) {
                $points[] = [round((float) $c[1], 6), round((float) $c[0], 6)];
            }
        }

        return count($points) >= 2 ? $points : [];
    }
}
