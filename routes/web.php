<?php

use App\Http\Controllers\Admin\CompanySettingsController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => to_route('dashboard'))->name('home');

Route::put('locale', [LocaleController::class, 'update'])->name('locale.update');

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
