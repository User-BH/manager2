<?php

use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GoodPayerController;
use App\Http\Controllers\Api\MessengerController;
use App\Http\Controllers\Api\MyBillController;
use App\Http\Controllers\Api\ResidentController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\System\BackupController as SystemBackupController;
use App\Http\Controllers\Api\System\ComplexController as SystemComplexController;
use App\Http\Controllers\Api\System\SmsController;
use App\Http\Controllers\Api\UnitController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API اپلیکیشن React
|--------------------------------------------------------------------------
|
| این مسیرها در bootstrap/app.php با پیشوند /api و روی گروه میدل‌ور «web»
| ثبت می‌شوند. یعنی نشست و کوکی و حفاظت CSRF همان لاراول است و نیازی به
| توکن bearer نیست، چون SPA از همین دامنه سرو می‌شود.
|
*/

// --- مهمان ---
Route::post('login', [AuthController::class, 'login'])->name('login');
Route::post('login/otp/request', [AuthController::class, 'requestOtp'])->name('login.otp.request');
Route::post('login/otp/verify', [AuthController::class, 'verifyOtp'])->name('login.otp.verify');
Route::post('register', [AuthController::class, 'register'])->name('register');

// وضعیت کاربر برای مهمان هم قابل فراخوانی است و null برمی‌گرداند؛ کلاینت
// هنگام بالا آمدن یک‌بار صدایش می‌زند تا بفهمد نشست فعالی هست یا نه.
Route::get('me', [AuthController::class, 'me'])->name('me');
Route::get('csrf-token', [AuthController::class, 'csrfToken'])->name('csrf-token');

// --- واردشده ---
Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // --- مشترک بین همه‌ی نقش‌ها ---
    Route::get('messenger', [MessengerController::class, 'index'])->name('messenger.index');
    Route::post('messenger', [MessengerController::class, 'store'])->name('messenger.store');
    Route::patch('messenger/{message}/toggle-hide', [MessengerController::class, 'toggleHide'])
        ->name('messenger.toggle-hide');

    Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
    Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])
        ->name('announcements.destroy');

    Route::get('good-payers', [GoodPayerController::class, 'index'])->name('good-payers.index');

    // --- صورت‌حساب‌های خود کاربر (مدیر هم می‌تواند ببیند) ---
    Route::get('my-bills', [MyBillController::class, 'index'])->name('my-bills.index');
    Route::get('my-bills/{bill}', [MyBillController::class, 'show'])->name('my-bills.show');

    // --- مدیریت مجتمع ---
    // همان میدل‌ور نقشی که پنل Blade استفاده می‌کند، تا سطح دسترسی در هر دو
    // مسیر یکسان بماند.
    Route::middleware('role:super_admin,complex_admin')->group(function () {
        Route::apiResource('units', UnitController::class)->except('show');
        Route::apiResource('residents', ResidentController::class)->except('show');
        Route::patch('residents/{resident}/toggle-active', [ResidentController::class, 'toggleActive'])
            ->name('residents.toggle-active');

        Route::get('bills', [BillController::class, 'index'])->name('bills.index');
        Route::post('bills/generate', [BillController::class, 'generate'])->name('bills.generate');

        // --- تنظیمات مجتمع ---
        Route::get('settings', [SettingController::class, 'show'])->name('settings.show');
        Route::put('settings', [SettingController::class, 'update'])->name('settings.update');

        Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
        Route::post('backups', [BackupController::class, 'store'])->name('backups.store');
        Route::get('backups/{backup}/download', [BackupController::class, 'download'])
            ->name('backups.download');
    });

    // --- بخش سیستم: فقط ادمین کل ---
    Route::middleware('role:super_admin')->prefix('system')->name('system.')->group(function () {
        Route::get('complexes', [SystemComplexController::class, 'index'])->name('complexes.index');
        Route::post('complexes', [SystemComplexController::class, 'store'])->name('complexes.store');
        Route::post('complexes/{complex}/select', [SystemComplexController::class, 'select'])
            ->name('complexes.select');
        Route::post('complexes/clear', [SystemComplexController::class, 'clear'])->name('complexes.clear');

        Route::get('sms', [SmsController::class, 'show'])->name('sms.show');
        Route::put('sms', [SmsController::class, 'update'])->name('sms.update');
        Route::post('sms/test', [SmsController::class, 'test'])->name('sms.test');

        Route::get('backups', [SystemBackupController::class, 'index'])->name('backups.index');
        Route::post('backups', [SystemBackupController::class, 'store'])->name('backups.store');
        Route::get('backups/{backup}/download', [SystemBackupController::class, 'download'])
            ->name('backups.download');
        Route::post('backups/restore', [SystemBackupController::class, 'restore'])->name('backups.restore');
    });
});
