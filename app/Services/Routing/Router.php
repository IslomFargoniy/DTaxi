<?php

namespace App\Services\Routing;

use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Yo'l masofasi va vaqti — o'z serverimizdagi OSRM (reja §4). OSRM ishlamasa,
 * to'g'ri chiziq × 1.3 va 30 km/soat bilan taxminiy hisob qaytariladi — buyurtma to'xtab qolmaydi.
 */
class Router
{
    private const DETOUR_FACTOR = 1.3;

    private const FALLBACK_SPEED_MPS = 30 / 3.6;

    public function route(Point $from, Point $to, bool $withGeometry = false): Route
    {
        $url = rtrim((string) config('services.osrm.url'), '/');
        $coordinates = sprintf('%F,%F;%F,%F', $from->getLongitude(), $from->getLatitude(), $to->getLongitude(), $to->getLatitude());

        try {
            $response = Http::timeout(3)->get("{$url}/route/v1/driving/{$coordinates}", [
                'overview' => $withGeometry ? 'simplified' : 'false',
                'geometries' => 'geojson',
            ]);

            if ($response->successful() && $response->json('code') === 'Ok') {
                $route = $response->json('routes.0');

                return new Route(
                    (int) round($route['distance']),
                    (int) round($route['duration']),
                    $withGeometry ? $route['geometry']['coordinates'] : null,
                );
            }
        } catch (ConnectionException $e) {
            Log::warning('OSRM ishlamayapti', ['error' => $e->getMessage()]);
        }

        return $this->estimate($from, $to);
    }

    public function estimate(Point $from, Point $to): Route
    {
        $meters = (int) round(self::haversine($from, $to) * self::DETOUR_FACTOR);

        return new Route($meters, (int) round($meters / self::FALLBACK_SPEED_MPS), estimated: true);
    }

    public static function haversine(Point $a, Point $b): float
    {
        $earth = 6371000;
        $dLat = deg2rad($b->getLatitude() - $a->getLatitude());
        $dLon = deg2rad($b->getLongitude() - $a->getLongitude());
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a->getLatitude())) * cos(deg2rad($b->getLatitude())) * sin($dLon / 2) ** 2;

        return 2 * $earth * asin(min(1, sqrt($h)));
    }
}
