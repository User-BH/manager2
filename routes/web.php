<?php

use App\Http\Controllers\DownloadController;
use App\Http\Controllers\GatewayController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| اپلیکیشن React (SPA)
|--------------------------------------------------------------------------
|
| هر مسیری که با /api یا یکی از مسیرهای زیر شروع نشود، همین یک ویو را
| برمی‌گرداند و react-router سمت کلاینت تصمیم می‌گیرد کدام صفحه رندر شود.
| به همین دلیل رفرش کردن روی مسیرهای داخلی (مثل /units) هم درست کار می‌کند.
|
*/

Route::view('/', 'spa')->name('home');

// نام «login» را لاراول برای ریدایرکت کاربر واردنشده استفاده می‌کند؛
// مقصدش صفحه‌ی ورودِ SPA است.
Route::view('/auth', 'spa')->name('login');

/*
|--------------------------------------------------------------------------
| مسیرهایی که عمداً SPA نیستند
|--------------------------------------------------------------------------
|
| اینجا فقط چیزهایی می‌مانند که ذاتاً نمی‌توانند JSON باشند: فایل‌هایی که
| مرورگر مستقیم بازشان می‌کند، و رفت‌وبرگشت با درگاه بانکی که از دامنه‌ی
| دیگری برمی‌گردد.
|
*/

Route::middleware('auth')->group(function () {
    // خروجی‌ها
    Route::get('bills/{bill}/invoice.pdf', [DownloadController::class, 'billInvoice'])
        ->name('bills.invoice');
    Route::get('units/{unit}/statement.pdf', [DownloadController::class, 'unitStatement'])
        ->name('units.statement');
    Route::get('bills/export.xlsx', [DownloadController::class, 'billsExport'])
        ->name('bills.export');

    // شروع پرداخت آنلاین: مرورگر باید واقعاً به سایت بانک برود
    Route::post('pay/{bill}/online', [GatewayController::class, 'start'])->name('payments.online');
});

// بازگشت از درگاه. بدون CSRF، چون درخواست از دامنه‌ی بانک می‌آید و توکن
// نشستِ ما را ندارد؛ اعتبارسنجی با تاییدیه‌ی خود درگاه انجام می‌شود.
Route::match(['get', 'post'], 'pay/callback/{payment}', [GatewayController::class, 'callback'])
    ->middleware('auth')
    ->name('payments.callback')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class]);

/*
| catch-all: باید آخرین روت فایل باشد.
|
| فقط مسیرهایی استثنا می‌شوند که اصلاً روت لاراولی ندارند (api و فایل‌های
| ساخته‌شده). مسیرهای بالا لازم نیست اینجا تکرار شوند چون زودتر ثبت شده‌اند
| و روتر خودش اول آن‌ها را امتحان می‌کند؛ استثنا کردنشان صفحه‌های SPA مثل
| /units و /bills را از کار می‌انداخت.
*/
Route::get('/{path}', fn () => view('spa'))
    ->where('path', '^(?!api|build|storage)[^?]*$');
