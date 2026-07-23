<?php

use App\Http\Controllers\Api\AdvertisementController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\ChargeRuleController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DiscountController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\GoodPayerController;
use App\Http\Controllers\Api\ManagerController;
use App\Http\Controllers\Api\MessengerController;
use App\Http\Controllers\Api\MyBillController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentReviewController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ResidentController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\System\AdvertisementController as SystemAdvertisementController;
use App\Http\Controllers\Api\System\BackupController as SystemBackupController;
use App\Http\Controllers\Api\System\ComplexController as SystemComplexController;
use App\Http\Controllers\Api\System\SmsController;
use App\Http\Controllers\Api\System\SubscriptionReviewController;
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
// محدودیت نرخ (تعریف در AppServiceProvider) جلوی حدس‌زدن رمز و کد، و
// مصرف بی‌رویه‌ی اعتبار پیامک را می‌گیرد.
Route::post('login', [AuthController::class, 'login'])
    ->middleware('throttle:login')->name('login');
Route::post('login/otp/request', [AuthController::class, 'requestOtp'])
    ->middleware('throttle:otp-request')->name('login.otp.request');
Route::post('login/otp/verify', [AuthController::class, 'verifyOtp'])
    ->middleware('throttle:otp-verify')->name('login.otp.verify');
Route::post('register', [AuthController::class, 'register'])
    ->middleware('throttle:register')->name('register');

// وضعیت کاربر برای مهمان هم قابل فراخوانی است و null برمی‌گرداند؛ کلاینت
// هنگام بالا آمدن یک‌بار صدایش می‌زند تا بفهمد نشست فعالی هست یا نه.
Route::get('me', [AuthController::class, 'me'])->name('me');
Route::get('csrf-token', [AuthController::class, 'csrfToken'])->name('csrf-token');

// بنرهای صفحه‌ی فرود؛ عمومی است چون صفحه پیش از ورود کاربر دیده می‌شود.
Route::get('ads', [AdvertisementController::class, 'index'])->name('ads.index');

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
    Route::put('announcements/{announcement}', [AnnouncementController::class, 'update'])
        ->name('announcements.update');
    Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])
        ->name('announcements.destroy');

    Route::get('good-payers', [GoodPayerController::class, 'index'])->name('good-payers.index');

    // زنگوله‌ی هدر. منبعش همان اطلاعیه‌هاست، فقط با وضعیت خوانده/نخوانده.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{announcement}/read', [NotificationController::class, 'read'])
        ->name('notifications.read');

    // جستجوی سراسری. هر گروه داخل کنترلر همان قید نقشی صفحه‌ی خودش را دارد.
    Route::get('search', [SearchController::class, 'index'])->name('search');

    // پروفایل و حساب کاربری — همیشه دربارهٔ خودِ کاربر واردشده
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    Route::get('subscription', [SubscriptionController::class, 'show'])->name('subscription.show');
    // خرید با واریز و آپلود رسید — تنها راه خرید تا وقتی درگاه اشتراک فعال نشده
    Route::post('subscription/receipt', [SubscriptionController::class, 'uploadReceipt'])
        ->name('subscription.receipt');
    Route::post('subscription/{subscription}/cancel', [SubscriptionController::class, 'cancel'])
        ->name('subscription.cancel');

    // --- صورت‌حساب‌های خود کاربر (مدیر هم می‌تواند ببیند) ---
    Route::get('my-bills', [MyBillController::class, 'index'])->name('my-bills.index');
    Route::get('my-bills/{bill}', [MyBillController::class, 'show'])->name('my-bills.show');

    // صفحه‌ی پرداخت یک قبض و ثبت رسید
    Route::get('pay/{bill}', [PaymentController::class, 'show'])->name('pay.show');
    Route::post('pay/{bill}/receipt', [PaymentController::class, 'uploadReceipt'])->name('pay.receipt');

    // --- مدیریت مجتمع ---
    // همان میدل‌ور نقشی که پنل Blade استفاده می‌کند، تا سطح دسترسی در هر دو
    // مسیر یکسان بماند.
    Route::middleware('role:super_admin,complex_admin')->group(function () {
        Route::apiResource('units', UnitController::class)->except('show');
        Route::apiResource('residents', ResidentController::class)->except('show');
        Route::patch('residents/{resident}/toggle-active', [ResidentController::class, 'toggleActive'])
            ->name('residents.toggle-active');
        Route::patch('residents/{resident}/toggle-messaging', [ResidentController::class, 'toggleMessaging'])
            ->name('residents.toggle-messaging');

        Route::get('bills', [BillController::class, 'index'])->name('bills.index');
        Route::post('bills/generate', [BillController::class, 'generate'])->name('bills.generate');

        Route::get('managers', [ManagerController::class, 'index'])->name('managers.index');
        Route::post('managers', [ManagerController::class, 'store'])->name('managers.store');
        Route::delete('managers/{manager}', [ManagerController::class, 'destroy'])->name('managers.destroy');

        Route::get('charge-rules', [ChargeRuleController::class, 'index'])->name('charge-rules.index');
        Route::post('charge-rules', [ChargeRuleController::class, 'store'])->name('charge-rules.store');
        Route::patch('charge-rules/{charge_rule}/toggle', [ChargeRuleController::class, 'toggle'])
            ->name('charge-rules.toggle');
        Route::delete('charge-rules/{charge_rule}', [ChargeRuleController::class, 'destroy'])
            ->name('charge-rules.destroy');

        Route::get('finance', [FinanceController::class, 'index'])->name('finance.index');
        Route::post('finance/expenses', [FinanceController::class, 'storeExpense'])->name('finance.expenses.store');
        Route::delete('finance/expenses/{expense}', [FinanceController::class, 'destroyExpense'])
            ->name('finance.expenses.destroy');
        Route::post('finance/incomes', [FinanceController::class, 'storeIncome'])->name('finance.incomes.store');
        Route::delete('finance/incomes/{income}', [FinanceController::class, 'destroyIncome'])
            ->name('finance.incomes.destroy');

        Route::get('payments', [PaymentReviewController::class, 'index'])->name('payments.index');
        Route::get('payments/{payment}/receipt', [PaymentReviewController::class, 'receipt'])
            ->name('payments.receipt');
        Route::post('payments/{payment}/approve', [PaymentReviewController::class, 'approve'])
            ->name('payments.approve');
        Route::post('payments/{payment}/reject', [PaymentReviewController::class, 'reject'])
            ->name('payments.reject');

        Route::get('discounts', [DiscountController::class, 'index'])->name('discounts.index');
        Route::post('discounts', [DiscountController::class, 'store'])->name('discounts.store');
        Route::delete('discounts/{discount}', [DiscountController::class, 'destroy'])->name('discounts.destroy');

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

        // بررسی رسیدهای اشتراک: پول اشتراک به حساب سرویس‌دهنده می‌رود، پس
        // تاییدش کار ادمین کل است نه مدیر مجتمعی که خودش پرداخت کرده.
        Route::get('subscriptions', [SubscriptionReviewController::class, 'index'])
            ->name('subscriptions.index');
        Route::get('subscriptions/{subscription}/receipt', [SubscriptionReviewController::class, 'receipt'])
            ->name('subscriptions.receipt');
        Route::post('subscriptions/{subscription}/approve', [SubscriptionReviewController::class, 'approve'])
            ->name('subscriptions.approve');
        Route::post('subscriptions/{subscription}/reject', [SubscriptionReviewController::class, 'reject'])
            ->name('subscriptions.reject');

        // تبلیغات صفحه‌ی فرود سطح پلتفرم‌اند (نه متعلق به یک مجتمع)
        Route::get('ads', [SystemAdvertisementController::class, 'index'])->name('ads.index');
        Route::post('ads', [SystemAdvertisementController::class, 'store'])->name('ads.store');
        // POST و نه PUT: آپلود فایل با multipart از PUT در PHP خوانده نمی‌شود
        Route::post('ads/{advertisement}', [SystemAdvertisementController::class, 'update'])->name('ads.update');
        Route::patch('ads/{advertisement}/toggle', [SystemAdvertisementController::class, 'toggle'])
            ->name('ads.toggle');
        Route::delete('ads/{advertisement}', [SystemAdvertisementController::class, 'destroy'])
            ->name('ads.destroy');

        Route::get('backups', [SystemBackupController::class, 'index'])->name('backups.index');
        Route::post('backups', [SystemBackupController::class, 'store'])->name('backups.store');
        Route::get('backups/{backup}/download', [SystemBackupController::class, 'download'])
            ->name('backups.download');
        // مخرب‌ترین عملیات سامانه؛ حتی برای ادمین کل هم سقف داشته باشد
        Route::post('backups/restore', [SystemBackupController::class, 'restore'])
            ->middleware('throttle:system-restore')->name('backups.restore');
    });
});
