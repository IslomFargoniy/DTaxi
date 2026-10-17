<?php

namespace App\Http\Controllers\Api\Driver;

use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DriverProfileResource;
use App\Http\Resources\OrderResource;
use App\Models\DeviceToken;
use App\Models\Driver;
use App\Models\DriverTransaction;
use App\Models\Order;
use App\Services\Drivers\DriverBalance;
use App\Services\Drivers\DriverLocator;
use App\Services\Routing\Router;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    public function me(Request $request): DriverProfileResource
    {
        return new DriverProfileResource($this->driver($request)->load(['user', 'vehicle']));
    }

    /**
     * Liniyaga chiqish / chiqish. Tasdiqlanmagan, bloklangan yoki balansi yetmaydigan haydovchi chiqa olmaydi.
     */
    public function shift(Request $request, DriverBalance $balance): JsonResponse
    {
        $online = $request->validate(['online' => ['required', 'boolean']])['online'];
        $driver = $this->driver($request);

        if ($driver->status === DriverStatus::Busy) {
            return response()->json(['message' => __('Finish the current order first.')], 422);
        }

        if ($online) {
            $reason = match (true) {
                ! $driver->is_approved || ! $driver->vehicle?->is_approved => __('Your account or car is not approved yet.'),
                $driver->isBlocked() => __('You are temporarily blocked until :time.', ['time' => $driver->blocked_until?->format('H:i')]),
                ! $balance->canTakeOrders($driver) => __('Not enough balance. Please top up.'),
                default => null,
            };
            if ($reason) {
                return response()->json(['message' => $reason], 422);
            }
        }

        $driver->forceFill(['status' => $online ? DriverStatus::Free : DriverStatus::Offline])->save();

        return response()->json(['status' => $driver->status->value]);
    }

    /**
     * GPS: bitta nuqta yoki internet uzilganda yig'ilgan nuqtalar to'plami.
     */
    public function location(Request $request, DriverLocator $locator): JsonResponse
    {
        $data = $request->validate([
            'points' => ['required', 'array', 'min:1', 'max:200'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'points.*.speed' => ['nullable', 'integer', 'min:0'],
            'points.*.heading' => ['nullable', 'integer', 'between:0,360'],
            'points.*.accuracy' => ['nullable', 'integer', 'min:0'],
        ]);
        $driver = $this->driver($request);

        foreach ($data['points'] as $point) {
            $locator->update($driver, (float) $point['lat'], (float) $point['lng'], $point['speed'] ?? null, $point['heading'] ?? null, $point['accuracy'] ?? null);
        }

        return response()->json(['ok' => true]);
    }

    public function deviceToken(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);

        DeviceToken::query()->updateOrCreate(['token' => $data['token']], ['user_id' => $request->user()?->id, 'platform' => 'android']);

        return response()->json(['ok' => true]);
    }

    public function currentOrder(Request $request): JsonResponse
    {
        $order = Order::query()->withoutGlobalScopes()
            ->where('driver_id', $this->driver($request)->id)
            ->whereIn('status', [OrderStatus::Assigned->value, OrderStatus::Arrived->value, OrderStatus::InProgress->value])
            ->with(['client', 'vehicle', 'tariffClass', 'delivery'])
            ->latest()->first();

        return response()->json(['order' => $order ? (new OrderResource($order))->resolve() : null]);
    }

    /**
     * Marshrut chizig'i, masofa va ETA — OSRM orqali (qaror #3).
     */
    public function route(Request $request, Router $router): JsonResponse
    {
        $data = $request->validate([
            'from_lat' => ['required', 'numeric'], 'from_lng' => ['required', 'numeric'],
            'to_lat' => ['required', 'numeric'], 'to_lng' => ['required', 'numeric'],
        ]);

        $route = $router->route(
            Point::makeGeodetic((float) $data['from_lat'], (float) $data['from_lng']),
            Point::makeGeodetic((float) $data['to_lat'], (float) $data['to_lng']),
            withGeometry: true,
        );

        return response()->json([
            'distance_m' => $route->distanceMeters,
            'duration_s' => $route->durationSeconds,
            'geometry' => $route->geometry,
            'estimated' => $route->estimated,
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        return response()->json([
            'balance' => $driver->balance,
            'items' => $driver->transactions()->latest('created_at')->limit(50)->get()
                ->map(fn (DriverTransaction $t) => [
                    'type' => $t->type->value,
                    'source' => $t->source->value,
                    'amount' => $t->amount,
                    'balance_after' => $t->balance_after,
                    'comment' => $t->comment,
                    'order_id' => $t->order_id,
                    'created_at' => $t->created_at?->toIso8601String(),
                ]),
        ]);
    }

    private function driver(Request $request): Driver
    {
        return Driver::query()->withoutGlobalScopes()->where('user_id', $request->user()?->id)->firstOrFail();
    }
}
