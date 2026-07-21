<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoodPayerController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\System;
use App\Livewire\Messenger;
use Illuminate\Support\Facades\Route;

// Public landing page for visitors; signed-in users go straight to their dashboard.
Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : view('home.index'))->name('home');

// --- Authentication ---
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login/password', [LoginController::class, 'password'])->name('login.password');
    Route::post('/login/otp/request', [LoginController::class, 'requestOtp'])->name('login.otp.request');
    Route::post('/login/otp/verify', [LoginController::class, 'verifyOtp'])->name('login.otp.verify');
    Route::post('/login/otp/cancel', [LoginController::class, 'cancelOtp'])->name('login.otp.cancel');
});
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// --- Authenticated ---
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Shared pages (all roles)
    Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::get('/messenger', Messenger::class)->name('messenger');
    Route::get('/good-payers', [GoodPayerController::class, 'index'])->name('good-payers');

    // Resident bills & payments
    Route::get('/my/bills', [BillController::class, 'index'])->name('bills.index');
    Route::get('/my/bills/{bill}', [BillController::class, 'show'])->name('bills.show');
    Route::get('/my/bills/{bill}/pdf', [BillController::class, 'pdf'])->name('bills.pdf');
    Route::get('/pay/{bill}', [PaymentController::class, 'show'])->name('payments.show');
    Route::post('/pay/{bill}/online', [PaymentController::class, 'startOnline'])->name('payments.online');
    Route::match(['get', 'post'], '/pay/callback/{payment}', [PaymentController::class, 'callback'])
        ->name('payments.callback')
        ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
    Route::post('/pay/{bill}/receipt', [PaymentController::class, 'uploadReceipt'])->name('payments.receipt');

    // --- Complex admin area ---
    Route::middleware('role:super_admin,complex_admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('units/{unit}/statement', [Admin\UnitController::class, 'statement'])->name('units.statement');
        Route::get('units/{unit}/statement/pdf', [Admin\UnitController::class, 'statementPdf'])->name('units.statement.pdf');
        Route::resource('units', Admin\UnitController::class)->except('show');
        Route::resource('residents', Admin\ResidentController::class)->except('show');
        Route::patch('residents/{resident}/toggle-active', [Admin\ResidentController::class, 'toggleActive'])->name('residents.toggle-active');
        Route::patch('residents/{resident}/toggle-message', [Admin\ResidentController::class, 'toggleMessage'])->name('residents.toggle-message');

        Route::get('managers', [Admin\ManagerController::class, 'index'])->name('managers.index');
        Route::post('managers', [Admin\ManagerController::class, 'store'])->name('managers.store');
        Route::delete('managers/{manager}', [Admin\ManagerController::class, 'destroy'])->name('managers.destroy');

        Route::resource('charge-rules', Admin\ChargeRuleController::class)->only('index', 'store', 'destroy');
        Route::patch('charge-rules/{charge_rule}/toggle', [Admin\ChargeRuleController::class, 'toggle'])->name('charge-rules.toggle');

        Route::get('expenses', [Admin\ExpenseController::class, 'index'])->name('expenses.index');
        Route::post('expenses', [Admin\ExpenseController::class, 'storeExpense'])->name('expenses.store');
        Route::delete('expenses/{expense}', [Admin\ExpenseController::class, 'destroyExpense'])->name('expenses.destroy');
        Route::post('incomes', [Admin\ExpenseController::class, 'storeIncome'])->name('incomes.store');
        Route::delete('incomes/{income}', [Admin\ExpenseController::class, 'destroyIncome'])->name('incomes.destroy');

        Route::get('bills', [Admin\BillManagerController::class, 'index'])->name('bills.index');
        Route::get('bills/export', [Admin\BillManagerController::class, 'export'])->name('bills.export');
        Route::post('bills/generate', [Admin\BillManagerController::class, 'generate'])->name('bills.generate');
        Route::post('bills/remind', [Admin\BillManagerController::class, 'remind'])->name('bills.remind');

        Route::get('discounts', [Admin\DiscountController::class, 'index'])->name('discounts.index');
        Route::post('discounts', [Admin\DiscountController::class, 'store'])->name('discounts.store');
        Route::delete('discounts/{discount}', [Admin\DiscountController::class, 'destroy'])->name('discounts.destroy');

        Route::get('payments', [Admin\PaymentReviewController::class, 'index'])->name('payments.index');
        Route::get('payments/{payment}/receipt', [Admin\PaymentReviewController::class, 'receipt'])->name('payments.receipt');
        Route::post('payments/{payment}/approve', [Admin\PaymentReviewController::class, 'approve'])->name('payments.approve');
        Route::post('payments/{payment}/reject', [Admin\PaymentReviewController::class, 'reject'])->name('payments.reject');

        Route::get('announcements', [Admin\AnnouncementManagerController::class, 'index'])->name('announcements.index');
        Route::post('announcements', [Admin\AnnouncementManagerController::class, 'store'])->name('announcements.store');
        Route::delete('announcements/{announcement}', [Admin\AnnouncementManagerController::class, 'destroy'])->name('announcements.destroy');

        Route::get('settings', [Admin\SettingController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [Admin\SettingController::class, 'update'])->name('settings.update');

        Route::get('backup', [Admin\BackupController::class, 'index'])->name('backup.index');
        Route::post('backup', [Admin\BackupController::class, 'store'])->name('backup.store');
    });

    // --- System super-admin area ---
    Route::middleware('role:super_admin')->prefix('system')->name('system.')->group(function () {
        Route::get('complexes', [System\ComplexController::class, 'index'])->name('complexes.index');
        Route::post('complexes', [System\ComplexController::class, 'store'])->name('complexes.store');
        Route::post('complexes/{complex}/select', [System\ComplexController::class, 'select'])->name('complexes.select');
        Route::post('complexes/clear', [System\ComplexController::class, 'clear'])->name('complexes.clear');

        Route::get('sms', [System\SmsSettingController::class, 'edit'])->name('sms.edit');
        Route::put('sms', [System\SmsSettingController::class, 'update'])->name('sms.update');
        Route::post('sms/test', [System\SmsSettingController::class, 'test'])->name('sms.test');

        Route::get('backup', [System\SystemBackupController::class, 'index'])->name('backup.index');
        Route::post('backup', [System\SystemBackupController::class, 'store'])->name('backup.store');
        Route::get('backup/{backup}/download', [System\SystemBackupController::class, 'download'])->name('backup.download');
        Route::post('backup/restore', [System\SystemBackupController::class, 'restore'])->name('backup.restore');
    });
});
