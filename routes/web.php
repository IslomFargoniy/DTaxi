<?php

use App\Http\Controllers\Admin\CompanySettingsController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Operator\LookupController;
use App\Http\Controllers\Operator\OperatorController;
use App\Http\Controllers\Operator\OrderController;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', fn () => to_route('dashboard'))->name('home');

Route::put('locale', [LocaleController::class, 'update'])->name('locale.update');

Route::get('chat/{message}/attachment', function (ChatMessage $message) {
    abort_unless($message->attachment_path !== null, 404);

    return Storage::response($message->attachment_path);
})->middleware('signed')->name('chat.attachment');

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';

Route::middleware(['auth', 'role:superadmin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::put('integrations/{provider}', [IntegrationController::class, 'update'])->name('integrations.update');

    Route::get('company-settings', [CompanySettingsController::class, 'edit'])->name('company-settings.edit');
    Route::put('company-settings', [CompanySettingsController::class, 'update'])->name('company-settings.update');
});

Route::middleware(['auth', 'permission:orders.manage'])->prefix('operator')->name('operator.')->group(function () {
    Route::get('/', [OperatorController::class, 'index'])->name('index');
    Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
    Route::post('orders/{order}/assign', [OrderController::class, 'assign'])->name('orders.assign');

    Route::get('lookup/client', [LookupController::class, 'client'])->name('lookup.client');
    Route::get('lookup/landmarks', [LookupController::class, 'landmarks'])->name('lookup.landmarks');
    Route::post('landmarks', [LookupController::class, 'storeLandmark'])->name('landmarks.store');
});
