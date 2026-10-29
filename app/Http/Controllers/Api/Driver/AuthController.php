<?php

namespace App\Http\Controllers\Api\Driver;

use App\Enums\DriverStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DriverProfileResource;
use App\Models\Client;
use App\Models\DeviceToken;
use App\Models\Driver;
use App\Models\User;
use App\Rules\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Haydovchi kirishi: telefon raqami + admin bergan parol (SMS yo'q — qaror #4).
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:25', new Phone],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::query()->where('phone', Client::normalizePhone($data['phone']))->first();
        $driver = $user ? Driver::query()->withoutGlobalScopes()->where('user_id', $user->id)->first() : null;

        if (! $user || ! $driver || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['phone' => __('auth.failed')]);
        }

        return response()->json([
            'token' => $user->createToken($data['device_name'], ['driver'])->plainTextToken,
            'driver' => new DriverProfileResource($driver->load(['user', 'vehicle'])),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $driver = Driver::query()->withoutGlobalScopes()->where('user_id', $user->id)->first();
        $driver?->forceFill(['status' => DriverStatus::Offline])->save();

        // Chiqqan telefonga takliflar va qo'ng'iroqlar push'i ketmasin
        DeviceToken::query()->where('user_id', $user->id)->delete();
        $user->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }
}
