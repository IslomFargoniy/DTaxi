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

    public function route(Point $from, Point $to, bool $withGeometry = false, bool $withSteps = false): Route
    {
        $url = rtrim((string) config('services.osrm.url'), '/');
        $coordinates = sprintf('%F,%F;%F,%F', $from->getLongitude(), $from->getLatitude(), $to->getLongitude(), $to->getLatitude());

        try {
            $response = Http::timeout(3)->get("{$url}/route/v1/driving/{$coordinates}", [
                // Navigatsiyada chiziq yo'l bo'ylab aniq bo'lishi kerak
                'overview' => $withGeometry ? ($withSteps ? 'full' : 'simplified') : 'false',
                'geometries' => 'geojson',
                'steps' => $withSteps ? 'true' : 'false',
            ]);

            if ($response->successful() && $response->json('code') === 'Ok') {
                $route = $response->json('routes.0');

                return new Route(
                    (int) round($route['distance']),
                    (int) round($route['duration']),
                    $withGeometry ? $route['geometry']['coordinates'] : null,
                    steps: $withSteps ? $this->steps((array) data_get($route, 'legs.0.steps', [])) : null,
                );
            }
        } catch (ConnectionException $e) {
            Log::warning('OSRM ishlamayapti', ['error' => $e->getMessage()]);
        }

        return $this->estimate($from, $to);
    }

    /**
     * OSRM qadamlari → ilova uchun ixcham manevrlar (tur, yo'nalish, ko'cha, manevrgacha masofa).
     *
     * @param  array<int, mixed>  $steps
     * @return list<array{type: string, modifier: string|null, name: string, distance: int, lat: float, lng: float, exit: int|null}>
     */
    private function steps(array $steps): array
    {
        $result = [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $result[] = [
                'type' => (string) data_get($step, 'maneuver.type'),
                'modifier' => data_get($step, 'maneuver.modifier'),
                'name' => (string) ($step['name'] ?? ''),
                // Shu qadam yo'li uzunligi (keyingi manevrgacha)
                'distance' => (int) round((float) ($step['distance'] ?? 0)),
                'lng' => (float) data_get($step, 'maneuver.location.0'),
                'lat' => (float) data_get($step, 'maneuver.location.1'),
                'exit' => data_get($step, 'maneuver.exit'),
            ];
        }

        return $result;
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
