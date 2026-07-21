<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ResidentController;
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

// --- واردشده ---
Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

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
    });
});
