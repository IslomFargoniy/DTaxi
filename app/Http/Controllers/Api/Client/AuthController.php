<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Rules\UzPhone;
use App\Services\Clients\LoginCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Mijoz ilovasiga kirish (qaror #71): telefon → qo'ng'iroqda 4 xonali kod → token. Parol va SMS yo'q.
 */
class AuthController extends Controller
{
    use ResolvesClient;

    public function __construct(private readonly LoginCodes $codes) {}

    public function requestCode(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:25', new UzPhone]]);

        $this->codes->send(Client::normalizePhone($data['phone']));

        return response()->json(['expires_in' => LoginCodes::TTL_MINUTES * 60], 202);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:25', new UzPhone],
            'code' => ['required', 'string', 'digits:4'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);
        $phone = Client::normalizePhone($data['phone']);

        if (! $this->codes->verify($phone, $data['code'])) {
            throw ValidationException::withMessages(['code' => __('The code is incorrect or expired.')]);
        }

        $client = Client::query()->firstOrCreate(['phone' => $phone]);
        $client->forceFill(['app_login_at' => now()])->save();

        return response()->json([
            'token' => $client->createToken($data['device_name'], ['client'])->plainTextToken,
            'client' => ProfileController::profile($client),
        ]);
    }

    public function logout(): Response
    {
        $this->client()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
