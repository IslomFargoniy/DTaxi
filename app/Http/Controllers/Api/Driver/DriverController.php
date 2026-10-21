<?php

namespace App\Http\Controllers\Api\Driver;

use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Http\Resources\DriverProfileResource;
use App\Http\Resources\OrderResource;
use App\Models\Call;
use App\Models\DeviceToken;
use App\Models\Driver;
use App\Models\DriverTransaction;
use App\Models\Order;
use App\Models\User;
use App\Services\Drivers\DriverBalance;
use App\Services\Drivers\DriverLocator;
use App\Services\Drivers\ShiftTracker;
use App\Services\Drivers\StandQueue;
use App\Services\Routing\Router;
use App\Services\Telephony\CallCenter;
use App\Services\Telephony\Extensions;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class DriverController extends Controller
{
    public function me(Request $request): DriverProfileResource
    {
        return new DriverProfileResource($this->driver($request)->load(['user', 'vehicle']));
    }

    /**
     * Liniyaga chiqish / chiqish. Tasdiqlanmagan, bloklangan yoki balansi yetmaydigan haydovchi chiqa olmaydi.
     */
    public function shift(Request $request, DriverBalance $balance, ShiftTracker $shifts, StandQueue $queue): JsonResponse
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
        $online ? $shifts->start($driver) : $shifts->end($driver);
        if (! $online) {
            $queue->leave($driver);
        }

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
            'points.*.mock' => ['boolean'],
            // Nuqta olingan vaqt (epoch ms) — internet uzilganda yig'ilganlari uchun
            'points.*.t' => ['nullable', 'integer', 'min:0'],
        ]);
        $driver = $this->driver($request);

        // Soxta GPS: nuqtalar hisobga olinmaydi (yaqin buyurtmalarni "tortib olish" mumkin bo'lmasin), admin ko'radi
        $points = array_values(array_filter((array) $data['points'], 'is_array'));
        $real = array_filter($points, fn (array $p) => empty($p['mock']));
        if (count($real) < count($points)) {
            $driver->forceFill(['mock_location_at' => now()])->save();
            Log::warning('Soxta GPS', ['driver' => $driver->id]);
        }

        $locator->record($driver, array_values(array_map(fn (array $p) => [
            'lat' => (float) $p['lat'],
            'lng' => (float) $p['lng'],
            'speed' => isset($p['speed']) ? (int) $p['speed'] : null,
            'heading' => isset($p['heading']) ? (int) $p['heading'] : null,
            'accuracy' => isset($p['accuracy']) ? (int) $p['accuracy'] : null,
            't' => isset($p['t']) ? (int) $p['t'] : null,
        ], $real)));

        return response()->json(['ok' => true]);
    }

    /**
     * Joriy smena hisoboti va navbat joyidagi o'rin (ilova bosh ekranida).
     */
    public function shiftSummary(Request $request, ShiftTracker $shifts, StandQueue $queue): JsonResponse
    {
        $driver = $this->driver($request);

        return response()->json(['shift' => $shifts->summary($driver), 'stand' => $queue->position($driver)]);
    }

    /**
     * Dispetcherga qo'ng'iroq holati: Asterisk navbat musiqasi uchun darhol javob beradi —
     * ilova vaqtni operator haqiqatan javob bergandan keyin sanashi uchun.
     */
    public function callStatus(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $call = Call::query()->withoutGlobalScopes()
            ->where('driver_id', $driver->id)
            ->where('kind', 'driver')
            ->where('caller', 'dr'.$driver->id)
            ->where('started_at', '>=', now()->subMinutes(30))
            ->latest('id')->first();

        return response()->json([
            'status' => $call?->status,
            'answered_at' => $call?->answered_at?->toIso8601String(),
            'ended' => $call?->ended_at !== null,
        ]);
    }

    /**
     * Ilova tili (uz, oz — kirill, ru, en): server xabarlari va xarita nomlari shu tilda.
     */
    public function locale(Request $request): JsonResponse
    {
        $locale = $request->validate(['locale' => ['required', Rule::in(SetLocale::LOCALES)]])['locale'];
        $request->user()?->forceFill(['locale' => $locale])->save();

        return response()->json(['locale' => $locale, 'map_style' => route('map.style', ['lang' => $locale])]);
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
            withSteps: true,
        );

        return response()->json([
            'distance_m' => $route->distanceMeters,
            'duration_s' => $route->durationSeconds,
            'geometry' => $route->geometry,
            'estimated' => $route->estimated,
            'steps' => $route->steps ?? [],
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

    /**
     * Ilova ichidagi qo'ng'iroq sahifasi uchun SIP ma'lumotlari (qaror #47).
     */
    public function phone(Request $request, Extensions $extensions): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->sip_extension) {
            $extensions->syncFor($user);
        }

        $sip = $extensions->credentials($user, $request->getHost());
        abort_unless($sip !== null, 404);

        return response()->json(['sip' => $sip, 'target' => 'dispatcher']);
    }

    /**
     * Operator qo'ng'irog'ini rad etish (ilovadagi qizil tugma): operator band signalini eshitadi.
     */
    public function declineCall(Request $request, int $call, CallCenter $center): JsonResponse
    {
        $model = Call::query()->withoutGlobalScopes()
            ->whereKey($call)
            ->where('driver_id', $this->driver($request)->id)
            ->firstOrFail();
        $center->driverDeclined($model);

        return response()->json(['ok' => true]);
    }
}
