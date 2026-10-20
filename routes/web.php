<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\CallController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\CompanySettingsController;
use App\Http\Controllers\Admin\ComplaintController;
use App\Http\Controllers\Admin\DriverController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\LandmarkController;
use App\Http\Controllers\Admin\OrderHistoryController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SoundController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\StandController;
use App\Http\Controllers\Admin\TariffController;
use App\Http\Controllers\Admin\TelephonyController;
use App\Http\Controllers\Cashier\CashierController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DriverAppController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MapStyleController;
use App\Http\Controllers\Operator\ChatController;
use App\Http\Controllers\Operator\LookupController;
use App\Http\Controllers\Operator\OperatorController;
use App\Http\Controllers\Operator\OrderController;
use App\Http\Controllers\Operator\PhoneController;
use App\Http\Controllers\SetupController;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', fn () => to_route('dashboard'))->name('home');

Route::put('locale', [LocaleController::class, 'update'])->name('locale.update');
Route::get('map/style.json', MapStyleController::class)->name('map.style');

// Haydovchi ilovasi — Google Play'siz (qaror #37)
Route::get('driver-app', [DriverAppController::class, 'page'])->name('driver-app.page');
// Haydovchi ilovasi ichidagi (WebView) dispetcherga qo'ng'iroq sahifasi; token JS ko'prik orqali beriladi
Route::view('driver-app/call', 'driver-call')->name('driver-app.call');
Route::get('driver-app/{abi}.apk', [DriverAppController::class, 'download'])->name('driver-app.download');

Route::get('chat/{message}/attachment', function (ChatMessage $message) {
    abort_unless($message->attachment_path !== null, 404);

    return Storage::response($message->attachment_path);
})->middleware('signed')->name('chat.attachment');

// Birinchi kirish ustasi — superadmin hali yo'q bo'lganda
Route::middleware('guest')->group(function () {
    Route::get('setup', [SetupController::class, 'show'])->name('setup.show');
    Route::post('setup', [SetupController::class, 'store'])->name('setup.store');
});

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/settings.php';

Route::middleware(['auth', 'role:superadmin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::put('integrations/{provider}', [IntegrationController::class, 'update'])->name('integrations.update');

    Route::get('company-settings', [CompanySettingsController::class, 'edit'])->name('company-settings.edit');
    Route::put('company-settings', [CompanySettingsController::class, 'update'])->name('company-settings.update');

    Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
    Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
    Route::put('branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
    Route::get('regions/{region}/children', [BranchController::class, 'children'])->name('regions.children');

    // Telefoniya (§5.5)
    Route::get('telephony', [TelephonyController::class, 'index'])->name('telephony.index');
    Route::post('telephony/trunks', [TelephonyController::class, 'storeTrunk'])->name('telephony.trunks.store');
    Route::put('telephony/trunks/{trunk}', [TelephonyController::class, 'updateTrunk'])->name('telephony.trunks.update');
    Route::delete('telephony/trunks/{trunk}', [TelephonyController::class, 'destroyTrunk'])->name('telephony.trunks.destroy');
    Route::post('telephony/numbers', [TelephonyController::class, 'storeNumber'])->name('telephony.numbers.store');
    Route::put('telephony/numbers/{number}', [TelephonyController::class, 'updateNumber'])->name('telephony.numbers.update');
    Route::delete('telephony/numbers/{number}', [TelephonyController::class, 'destroyNumber'])->name('telephony.numbers.destroy');
    Route::put('telephony/limits', [TelephonyController::class, 'updateLimits'])->name('telephony.limits.update');
    Route::put('telephony/settings', [TelephonyController::class, 'updateSettings'])->name('telephony.settings.update');

    Route::get('sounds', [SoundController::class, 'index'])->name('sounds.index');
    Route::post('sounds/generate', [SoundController::class, 'generate'])->name('sounds.generate');
    Route::post('sounds/generate-missing', [SoundController::class, 'generateMissing'])->name('sounds.generate-missing');
    Route::post('sounds/upload', [SoundController::class, 'upload'])->name('sounds.upload');
    Route::get('sounds/{clip}/play', [SoundController::class, 'play'])->name('sounds.play');

    Route::get('audit', [AuditController::class, 'index'])->name('audit.index');

    Route::get('driver-app', [DriverAppController::class, 'admin'])->name('driver-app.index');
    Route::post('driver-app', [DriverAppController::class, 'publish'])->name('driver-app.publish');
});

Route::middleware(['auth', 'permission:users.manage'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
    Route::put('staff/{user}', [StaffController::class, 'update'])->name('staff.update');
});

Route::middleware(['auth', 'permission:drivers.manage'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('drivers', [DriverController::class, 'index'])->name('drivers.index');
    Route::get('drivers/create', [DriverController::class, 'create'])->name('drivers.create');
    Route::post('drivers', [DriverController::class, 'store'])->name('drivers.store');
    Route::get('drivers/{driver}', [DriverController::class, 'edit'])->name('drivers.edit');
    Route::put('drivers/{driver}', [DriverController::class, 'update'])->name('drivers.update');
    Route::post('drivers/{driver}/approve', [DriverController::class, 'approve'])->name('drivers.approve');
    Route::post('drivers/{driver}/block', [DriverController::class, 'block'])->name('drivers.block');
    Route::post('drivers/{driver}/balance', [DriverController::class, 'adjustBalance'])->name('drivers.balance');
    Route::post('drivers/{driver}/transactions/{transaction}/excuse', [DriverController::class, 'excusePenalty'])->name('drivers.transactions.excuse');
    Route::post('drivers/{driver}/documents', [DriverController::class, 'uploadDocument'])->name('drivers.documents.store');
    Route::get('drivers/{driver}/documents/{document}', [DriverController::class, 'showDocument'])->name('drivers.documents.show');
    Route::put('drivers/{driver}/documents/{document}', [DriverController::class, 'reviewDocument'])->name('drivers.documents.review');
});

Route::middleware(['auth', 'permission:tariffs.manage'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('stands', [StandController::class, 'index'])->name('stands.index');
    Route::post('stands', [StandController::class, 'store'])->name('stands.store');
    Route::put('stands/{stand}', [StandController::class, 'update'])->name('stands.update');
    Route::delete('stands/{stand}', [StandController::class, 'destroy'])->name('stands.destroy');
    Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::put('companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
    Route::get('companies/{company}/invoice.csv', [CompanyController::class, 'invoice'])->name('companies.invoice');
    Route::get('tariffs', [TariffController::class, 'index'])->name('tariffs.index');
    Route::post('tariffs', [TariffController::class, 'store'])->name('tariffs.store');
    Route::put('tariffs/{tariffClass}', [TariffController::class, 'update'])->name('tariffs.update');
});

Route::middleware(['auth', 'permission:orders.view'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('orders', [OrderHistoryController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderHistoryController::class, 'show'])->name('orders.show');
    Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
    Route::get('complaints', [ComplaintController::class, 'index'])->name('complaints.index');
    Route::post('complaints', [ComplaintController::class, 'store'])->name('complaints.store');
    Route::put('complaints/{complaint}', [ComplaintController::class, 'update'])->name('complaints.update');
    Route::post('orders/{order}/rating', [OrderHistoryController::class, 'rate'])->name('orders.rate');
    Route::put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');
    Route::get('landmarks', [LandmarkController::class, 'index'])->name('landmarks.index');
    Route::put('landmarks/{landmark}', [LandmarkController::class, 'update'])->name('landmarks.update');
    Route::delete('landmarks/{landmark}', [LandmarkController::class, 'destroy'])->name('landmarks.destroy');
});

Route::middleware(['auth', 'permission:reports.view'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/{report}.csv', [ReportController::class, 'export'])->name('reports.export');
});

Route::middleware(['auth', 'permission:calls.listen'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('calls', [CallController::class, 'index'])->name('calls.index');
    Route::get('calls/{call}/recording', [CallController::class, 'recording'])->name('calls.recording');
});

Route::middleware(['auth', 'permission:balance.topup'])->prefix('cashier')->name('cashier.')->group(function () {
    Route::get('/', [CashierController::class, 'index'])->name('index');
    Route::get('drivers', [CashierController::class, 'search'])->name('drivers.search');
    Route::post('shift/open', [CashierController::class, 'open'])->name('shift.open');
    Route::post('shift/close', [CashierController::class, 'close'])->name('shift.close');
    Route::post('topup', [CashierController::class, 'topUp'])->name('topup');
});

Route::middleware(['auth', 'permission:orders.manage'])->prefix('operator')->name('operator.')->group(function () {
    Route::get('/', [OperatorController::class, 'index'])->name('index');
    Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
    Route::post('orders/{order}/assign', [OrderController::class, 'assign'])->name('orders.assign');
    Route::patch('orders/{order}', [OrderController::class, 'update'])->name('orders.update');
    Route::post('orders/{order}/redispatch', [OrderController::class, 'redispatch'])->name('orders.redispatch');

    Route::get('lookup/client', [LookupController::class, 'client'])->name('lookup.client');
    Route::get('lookup/landmarks', [LookupController::class, 'landmarks'])->name('lookup.landmarks');
    Route::post('landmarks', [LookupController::class, 'storeLandmark'])->name('landmarks.store');

    Route::get('phone', [PhoneController::class, 'show'])->name('phone.show');
    Route::post('phone/status', [PhoneController::class, 'status'])->name('phone.status');
    Route::get('phone/missed', [PhoneController::class, 'missed'])->name('phone.missed');

    Route::get('chat', [ChatController::class, 'threads'])->name('chat.threads');
    Route::get('chat/{driver}', [ChatController::class, 'messages'])->name('chat.messages');
    Route::post('chat/{driver}', [ChatController::class, 'send'])->name('chat.send');
});
