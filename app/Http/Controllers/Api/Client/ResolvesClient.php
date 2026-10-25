<?php

namespace App\Http\Controllers\Api\Client;

use App\Models\Client;
use Illuminate\Support\Facades\Auth;

/**
 * Token egasi — Client (User emas): $request->user() turi xodimlar provayderiga bog'langan.
 */
trait ResolvesClient
{
    protected function client(): Client
    {
        $client = Auth::guard('sanctum')->user();
        abort_unless($client instanceof Client, 403);

        return $client;
    }
}
