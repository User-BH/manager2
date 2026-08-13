<?php

use App\Http\Controllers\AdvertisementImageController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\GatewayController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\SubscriptionCheckoutController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| صفحه‌های عمومیِ MPA (island)
|--------------------------------------------------------------------------
|
| خانه/دمو/پشتیبانی/ورود هرکدام یک سندِ HTMLِ مستقل‌اند که سمتِ سرور رندر
| می‌شوند: عنوان، توضیح، Open Graph و JSON-LDِ مخصوصِ خودشان را دارند (از
| config/seo.php) و فقط entryِ همان صفحه را بار می‌کنند. این یعنی خزنده‌ها و
| هوش‌مصنوعی‌ها به‌جای یک پوسته‌ی خالی، HTMLِ واقعیِ هر صفحه را می‌بینند.
|
*/

// فایل‌های متنیِ سئو (دامنه از config خوانده می‌شود، پس بین محیط‌ها درست می‌ماند)
Route::get('/robots.txt', [SeoController::class, 'robots']);
Route::get('/sitemap.xml', [SeoController::class, 'sitemap']);
Route::get('/llms.txt', [SeoController::class, 'llms']);

Route::view('/', 'public.home', [
    'seo' => config('seo.home'),
    'entry' => 'resources/js/app/entries/home.tsx',
])->name('home');

Route::view('/demo', 'public.demo', [
    'seo' => config('seo.demo'),
    'entry' => 'resources/js/app/entries/demo.tsx',
])->name('demo');

Route::view('/support', 'public.support', [
    'seo' => config('seo.support'),
    'entry' => 'resources/js/app/entries/support.tsx',
])->name('support');

/*
| صفحه‌ی آفلاینِ PWA.
|
| service worker این را هنگامِ نصب برمی‌دارد و بعد، هر وقت پیمایشی به شبکه
| نرسد، همین را نشان می‌دهد. هیچ داراییِ بیرونی بار نمی‌کند — چون دقیقاً
| وقتی نشان داده می‌شود که هیچ داراییِ بیرونی‌ای در دسترس نیست.
*/
Route::view('/offline', 'offline')->name('offline');

// جریانِ سه‌گامیِ ورود؛ هر سه مسیر همان islandِ auth را سرو می‌کنند و روترِ
// کوچکِ داخلش گامِ درست را نشان می‌دهد. نام «login» را لاراول برای ریدایرکتِ
// کاربرِ واردنشده استفاده می‌کند.
$authView = ['seo' => config('seo.auth'), 'entry' => 'resources/js/app/entries/auth.tsx'];
Route::view('/auth', 'public.auth', $authView)->name('login');
Route::view('/auth/verify', 'public.auth', $authView);
Route::view('/auth/forgot', 'public.auth', $authView);

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

// تصویر بنر تبلیغاتی؛ بدون احراز هویت، چون روی صفحه‌ی فرود عمومی است.
Route::get('ads/{advertisement}/image', AdvertisementImageController::class)->name('ads.image');

Route::middleware('auth')->group(function () {
    // خروجی‌ها
    Route::get('bills/{bill}/invoice.pdf', [DownloadController::class, 'billInvoice'])
        ->name('bills.invoice');
    Route::get('units/{unit}/statement.pdf', [DownloadController::class, 'unitStatement'])
        ->name('units.statement');

    /*
     * اسنادِ تازه‌ی R28.
     *
     * همه اینجا و نه در API: مرورگر باید مستقیم بازشان کند و نشستِ وب
     * احراز هویت را انجام می‌دهد. لینکِ ساده در SPA به همین مسیرها اشاره
     * می‌کند و نیازی به دانلودِ blob و ساختِ object URL نیست.
     */
    Route::get('payments/{payment}/receipt.pdf', [DownloadController::class, 'paymentReceipt'])
        ->name('payments.receipt');
    Route::get('reports/financial.pdf', [DownloadController::class, 'financialReport'])
        ->name('reports.financial');
    Route::get('units/{unit}/dossier.pdf', [DownloadController::class, 'unitDossier'])
        ->name('units.dossier');
    Route::get('reports/bills-bundle/{document}.pdf', [DownloadController::class, 'billsBundle'])
        ->name('reports.bills-bundle');
    Route::get('bills/export.xlsx', [DownloadController::class, 'billsExport'])
        ->name('bills.export');

    // شروع پرداخت آنلاین: مرورگر باید واقعاً به سایت بانک برود
    Route::post('pay/{bill}/online', [GatewayController::class, 'start'])->name('payments.online');

    // خرید اشتراک — درگاهش از درگاه مجتمع جداست (config/subscription.php)
    Route::post('subscription/checkout', [SubscriptionCheckoutController::class, 'start'])
        ->name('subscription.checkout');
});

/*
| بازگشت از درگاه.
|
| بدون CSRF، چون درخواست از دامنه‌ی بانک می‌آید و توکن نشستِ ما را ندارد.
|
| و بدون میدل‌ور `auth` — این تصمیم عمدی و مهم است: اگر نشست کاربر تا لحظه‌ی
| بازگشت از بانک منقضی شده باشد، `auth` او را به صفحه‌ی ورود می‌فرستاد و
| تراکنش هرگز تایید نمی‌شد؛ یعنی پول کم شده بود و قبض پرداخت‌نشده می‌ماند.
| اعتبار این درخواست را تاییدیه‌ی خود درگاه تعیین می‌کند (کنترلر اگر نشستی
| ببیند، مالکیت را هم بررسی می‌کند).
|
| محدودیت نرخ چون مسیر دیگر پشت نشست نیست و نباید بشود شناسه‌ها را پیمود.
*/
Route::match(['get', 'post'], 'pay/callback/{payment}', [GatewayController::class, 'callback'])
    ->middleware('throttle:gateway-callback')
    ->name('payments.callback')
    ->withoutMiddleware([PreventRequestForgery::class]);

Route::match(['get', 'post'], 'subscription/callback/{subscription}', [SubscriptionCheckoutController::class, 'callback'])
    ->middleware('throttle:gateway-callback')
    ->name('subscription.callback')
    ->withoutMiddleware([PreventRequestForgery::class]);

/*
| catch-all: داشبوردِ SPA.
|
| هر مسیری که صفحه‌ی عمومیِ بالا، /api یا فایلِ ساخته‌شده نباشد (مثل /dashboard
| و /units و /settings/complex) همین یک ویو را می‌گیرد و react-router سمتِ
| کلاینت صفحه‌ی داشبوردیِ درست را رندر می‌کند. باید آخرین روتِ فایل باشد.
*/
Route::get('/{path}', fn () => view('spa'))
    ->where('path', '^(?!api|build|storage)[^?]*$');
