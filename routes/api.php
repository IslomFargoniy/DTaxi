<?php

use App\Http\Controllers\Api\Driver\AuthController;
use App\Http\Controllers\Api\Driver\ChatController;
use App\Http\Controllers\Api\Driver\DriverController;
use App\Http\Controllers\Api\Driver\OrderController;
use App\Http\Controllers\DriverAppController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
 * Haydovchi ilovasi API (reja §8.5). Real vaqt: Reverb (`/api/broadcasting/auth`), ilova yopiq bo'lsa — FCM.
 */
Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::prefix('driver/v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('app-version', [DriverAppController::class, 'version']);

    Route::middleware(['auth:sanctum', 'abilities:driver'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [DriverController::class, 'me']);
        Route::post('shift', [DriverController::class, 'shift']);
        Route::post('location', [DriverController::class, 'location'])->middleware('throttle:120,1');
        Route::post('device-token', [DriverController::class, 'deviceToken']);
        Route::get('orders/current', [DriverController::class, 'currentOrder']);
        Route::get('route', [DriverController::class, 'route']);
        Route::get('balance', [DriverController::class, 'transactions']);

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
    });
});
