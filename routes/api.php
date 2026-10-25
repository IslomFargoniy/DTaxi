<?php

use App\Http\Controllers\Api\Client\AuthController as ClientAuthController;
use App\Http\Controllers\Api\Client\OrderController as ClientOrderController;
use App\Http\Controllers\Api\Client\ProfileController as ClientProfileController;
use App\Http\Controllers\Api\Driver\AuthController;
use App\Http\Controllers\Api\Driver\ChatController;
use App\Http\Controllers\Api\Driver\DriverController;
use App\Http\Controllers\Api\Driver\OrderController;
use App\Http\Controllers\Api\Payments\ClickController;
use App\Http\Controllers\Api\Payments\PaymeController;
use App\Http\Controllers\DriverAppController;
use App\Http\Middleware\TouchDriverPresence;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
 * Haydovchi ilovasi API (reja §8.5). Real vaqt: Reverb (`/api/broadcasting/auth`), ilova yopiq bo'lsa — FCM.
 */
Broadcast::routes(['middleware' => ['auth:sanctum']]);

// To'lov tizimlari callback'lari (qaror #23): haydovchi balansini pozivnoy bo'yicha to'ldirish
Route::prefix('payments')->middleware('throttle:120,1')->group(function () {
    Route::post('click/prepare', [ClickController::class, 'prepare']);
    Route::post('click/complete', [ClickController::class, 'complete']);
    Route::post('payme', PaymeController::class);
});

Route::prefix('driver/v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:driver-login');
    Route::get('app-version', [DriverAppController::class, 'version']);

    Route::middleware(['auth:sanctum', 'abilities:driver', TouchDriverPresence::class])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        // Ilova ochiq turganda har daqiqada — chatda "online" (Telegram kabi)
        Route::post('ping', fn () => response()->noContent());
        Route::get('me', [DriverController::class, 'me']);
        Route::get('shift', [DriverController::class, 'shiftSummary']);
        Route::put('locale', [DriverController::class, 'locale']);
        Route::get('phone/call', [DriverController::class, 'callStatus']);
        Route::post('shift', [DriverController::class, 'shift']);
        Route::post('location', [DriverController::class, 'location'])->middleware('throttle:120,1');
        Route::post('device-token', [DriverController::class, 'deviceToken']);
        Route::get('orders/current', [DriverController::class, 'currentOrder']);
        Route::get('route', [DriverController::class, 'route']);
        Route::get('balance', [DriverController::class, 'transactions']);

        Route::get('orders/history', [OrderController::class, 'history']);
        Route::get('orders/{order}/track', [OrderController::class, 'track'])->whereNumber('order');
        Route::post('orders/{order}/accept', [OrderController::class, 'accept']);
        Route::post('orders/{order}/reject', [OrderController::class, 'reject']);
        Route::post('orders/{order}/arrive', [OrderController::class, 'arrive']);
        Route::post('orders/{order}/start', [OrderController::class, 'start']);
        Route::post('orders/{order}/complete', [OrderController::class, 'complete']);
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);

        Route::get('chat', [ChatController::class, 'index']);
        Route::post('chat', [ChatController::class, 'store']);

        // Dispetcherga qo'ng'iroq (qaror #47): WebRTC ichki raqam
        Route::get('phone', [DriverController::class, 'phone']);
        // Operator → haydovchi qo'ng'irog'ini rad etish (qaror #59)
        Route::post('phone/calls/{call}/decline', [DriverController::class, 'declineCall'])->whereNumber('call');
    });
});

/*
 * Mijoz ilovasi API (qaror #71). Kirish — qo'ng'iroqda aytiladigan kod; real vaqt — private-client.{id}.
 */
Route::prefix('client/v1')->group(function () {
    Route::post('auth/request-code', [ClientAuthController::class, 'requestCode'])->middleware('throttle:client-code');
    Route::post('auth/verify', [ClientAuthController::class, 'verify'])->middleware('throttle:client-verify');

    Route::middleware(['auth:sanctum', 'abilities:client'])->group(function () {
        Route::post('auth/logout', [ClientAuthController::class, 'logout']);
        Route::get('me', [ClientProfileController::class, 'show']);
        Route::put('me', [ClientProfileController::class, 'update']);
        Route::get('places', [ClientProfileController::class, 'places'])->middleware('throttle:60,1');
        Route::get('places/reverse', [ClientProfileController::class, 'reverse'])->middleware('throttle:60,1');
        Route::get('addresses', [ClientProfileController::class, 'addresses']);
        Route::post('addresses', [ClientProfileController::class, 'storeAddress']);
        Route::delete('addresses/{address}', [ClientProfileController::class, 'destroyAddress'])->whereNumber('address');

        Route::post('quote', [ClientOrderController::class, 'quote'])->middleware('throttle:30,1');
        Route::get('orders', [ClientOrderController::class, 'index']);
        Route::post('orders', [ClientOrderController::class, 'store'])->middleware('throttle:10,1');
        Route::get('orders/current', [ClientOrderController::class, 'current']);
        Route::post('orders/{order}/cancel', [ClientOrderController::class, 'cancel'])->whereNumber('order');
        Route::post('orders/{order}/rate', [ClientOrderController::class, 'rate'])->whereNumber('order');
    });
});
