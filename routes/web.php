<?php

use App\Http\Controllers\AdvertisementImageController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\GatewayController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\SubscriptionCheckoutController;
use App\Http\Middleware\SetCurrentComplex;
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
    /*
    | تصویرِ بزرگِ بالای صفحه (LCP) — R36.
    |
    | این تصویر را React رندر می‌کند، یعنی مرورگر تا JS اجرا نشود آدرسش را
    | نمی‌بیند و بزرگ‌ترین عنصرِ صفحه دیرترین چیزی می‌شود که شروع به آمدن
    | می‌کند. `preload` در head آن را به اولین بایتِ HTML جلو می‌آورد.
    |
    | فقط همین یک صفحه این را دارد: در دمو و پشتیبانی و ورود، عنصرِ بزرگ
    | متن است نه تصویر، و preloadِ بی‌مورد فقط پهنای باند را از منابعِ
    | واقعی می‌گیرد.
    |
    | ⚠️ اینجا **مسیرِ منبع** می‌آید، نه خروجیِ `Vite::asset()`.
    |
    | فایلِ مسیرها هنگامِ **بوت** اجرا می‌شود — یعنی در هر دستورِ artisan، از
    | جمله `package:discover` که خودِ `composer install` صدایش می‌زند. و
    | `Vite::asset()` بدونِ `public/build/manifest.json` استثنا می‌اندازد.
    | نتیجه‌اش این بود که روی سرورِ تازه، `composer install` **پیش از** آنکه
    | فرصتِ بیلد برسد می‌شکست و هیچ دستورِ artisanی اجرا نمی‌شد.
    |
    | حل: تبدیلِ مسیر به URL در `partials/resource-hints` انجام می‌شود، یعنی
    | هنگامِ رندرِ صفحه — جایی که manifest قطعاً وجود دارد. دادهٔ مسیر هم
    | رشته‌ی ساده می‌ماند و `route:cache` را نمی‌شکند.
    */
    'lcpImage' => 'resources/images/hero-building.webp',
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
//
// ─── تاریخچه‌ی مرورگر (مرحلهٔ ۷ · فاز ۱) ──────────────────────────────────
// کاربرِ واردشده نباید فرمِ ورود را ببیند، حتی با «بازگشتِ» مرورگر:
//   ① `guest` او را **سمتِ سرور** به داشبورد می‌فرستد — پیش از آن فقط خودِ
//      React پس از بارگذاری این کار را می‌کرد و فرم یک لحظه دیده می‌شد.
//   ② `no_store` نمی‌گذارد مرورگر نسخه‌ی پیش از ورودِ این صفحه را از
//      bfcache برگرداند؛ آن نسخه هنوز فکر می‌کند کاربر مهمان است.
// خودِ جریانِ ورود هم یک ورودی در تاریخچه است که در پایان با `replace` جای
// خودش را به داشبورد می‌دهد (LoginForm، VerifyOtpPage، ForgotPasswordPage).
$authView = [
    'seo' => config('seo.auth'),
    'entry' => 'resources/js/app/entries/auth.tsx',
    // پنلِ تصویرِ دسکتاپ (LCP)؛ زیرِ lg دیده نمی‌شود — مرحلهٔ ۷ · فاز ۳
    'lcpImage' => 'resources/images/auth-background.webp',
    'lcpMedia' => '(min-width: 1024px)',
];
Route::middleware(['guest', 'cache.headers:no_store;private'])->group(function () use ($authView) {
    Route::view('/auth', 'public.auth', $authView)->name('login');
    Route::view('/auth/verify', 'public.auth', $authView);
    Route::view('/auth/forgot', 'public.auth', $authView);
    Route::view('/auth/recover', 'public.auth', $authView);
    // پیوندِ دعوت (مرحلهٔ ۹ · فاز ۶): همان island؛ توکن پس از `#` و هرگز به سرور نمی‌رسد.
    // کاربرِ واردشده با `guest` به داشبورد می‌رود — دعوت همان‌جا منتظر است.
    Route::view('/invite', 'public.auth', $authView);
});

/*
| QR ِ ورودِ عمومیِ مجتمع (مرحلهٔ ۱۰ · فاز ۵؛ بندِ ۲۲): همان island، کد پس از `#`.
|
| ⚠️ عمداً **بیرون** از `guest`: کاربرِ واردشده — عضوِ مجتمعِ دیگر یا کسی که همین حالا برای فرستادنِ
| درخواست وارد شده — باید همین صفحه را ببیند و درخواست بفرستد، نه اینکه به داشبورد پرت شود و QR گم شود.
| صفحه هیچ دسترسی‌ای نمی‌دهد: نامِ مجتمع از `join-link/preview` و درخواست از `join-requests`، هر دو در سرور.
*/
Route::view('/join', 'public.auth', $authView)->middleware('cache.headers:no_store;private');

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

/*
| بلاگِ عمومی (مرحلهٔ ۶ · فاز ۱۱) — Blade، ایندکس‌شدنی، بی‌نیاز به ورود.
|
| ⚠️ ترتیب مهم است: `category`، `tag` و `media` پیش از `{slug}` می‌آیند تا
| مقاله‌ای با نامکِ «category» جای فهرستِ دسته را نگیرد. قاعده‌ی نامِ فایلِ
| تصویر همان خروجیِ `store()` است (۴۰ نویسه + پسوندِ تصویر) — مسیرِ دلخواه و
| `..` از این‌جا رد نمی‌شوند.
*/
Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/category/{slug}', [BlogController::class, 'category'])->name('blog.category');
Route::get('/blog/tag/{slug}', [BlogController::class, 'tag'])->name('blog.tag');
Route::get('/blog/media/{file}', [BlogController::class, 'media'])
    ->where('file', '[A-Za-z0-9]{40}\.(jpg|jpeg|png|webp)')->name('blog.media');
Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog.show');

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
    Route::post('pay/{bill}/online', [GatewayController::class, 'start'])
        ->middleware('throttle:payment-start')->name('payments.online');

    // خرید اشتراک — درگاهش از درگاه مجتمع جداست (config/subscription.php)
    Route::post('subscription/checkout', [SubscriptionCheckoutController::class, 'start'])
        ->middleware('throttle:payment-start')->name('subscription.checkout');
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
/*
| ⚠️ `SetCurrentComplex` هم کنار گذاشته می‌شود، و این عمدی است (فاز ۲).
|
| از فاز ۲ به بعد، درخواستِ **مهمان** صریحاً از همه‌ی داده‌ی مستأجری محروم
| می‌شود. ولی بانک دقیقاً به‌صورتِ مهمان برمی‌گردد — بدونِ نشست، و گاهی
| ساعت‌ها بعد. بدونِ این استثنا، اتصالِ مدلِ مسیر چیزی پیدا نمی‌کرد و
| callbackِ واقعیِ بانک ۴۰۴ می‌گرفت؛ یعنی پولی که کسر شده هرگز تسویه
| نمی‌شد.
|
| 🔴 پس این دو مسیر همان رفتارِ قبلی را نگه می‌دارند: زمینه‌ی مستأجری
| ندارند و محافظشان همان است که بالا نوشته شده — تاییدیه‌ی خودِ درگاه به
| علاوه‌ی محدودیتِ نرخ. هیچ مسیرِ دیگری این استثنا را ندارد.
*/
Route::match(['get', 'post'], 'pay/callback/{payment}', [GatewayController::class, 'callback'])
    ->middleware('throttle:gateway-callback')
    ->name('payments.callback')
    ->withoutMiddleware([PreventRequestForgery::class, SetCurrentComplex::class]);

Route::match(['get', 'post'], 'subscription/callback/{subscription}', [SubscriptionCheckoutController::class, 'callback'])
    ->middleware('throttle:gateway-callback')
    ->name('subscription.callback')
    ->withoutMiddleware([PreventRequestForgery::class, SetCurrentComplex::class]);

// بازگشتِ خریدِ مجتمع توسطِ حسابِ اولیه (مرحلهٔ ۹ · فاز ۴) — همان سه محافظ؛
// `{intent}` شناسه‌ی تصادفیِ `public_id` است، نه شمارنده‌ی ردیف
Route::match(['get', 'post'], 'checkout/callback/{intent}', [SubscriptionCheckoutController::class, 'intentCallback'])
    ->middleware('throttle:gateway-callback')
    ->name('checkout.callback')
    ->withoutMiddleware([PreventRequestForgery::class, SetCurrentComplex::class]);

/*
| بررسیِ سلامت (R43).
|
| ⚠️ باید **پیش از** روتِ فراگیرِ پایین بیاید، وگرنه `/up` هم به همان
| `view('spa')` می‌رسد و همیشه ۲۰۰ می‌گیرد — یعنی دقیقاً همان دروغی که این
| مرحله برای رفعش بود.
|
| بدونِ احراز هویت است چون متعادل‌کننده‌ی بار و اسکریپتِ استقرار باید بتوانند
| بزنندش؛ به همین دلیل پاسخِ پیش‌فرضش هم فقط یک کلمه است و جزئیات پشتِ
| هدرِ `X-Health-Secret` می‌ماند.
*/
Route::get('/up', HealthController::class)->name('health');

/*
| catch-all: داشبوردِ SPA.
|
| هر مسیری که صفحه‌ی عمومیِ بالا، /api یا فایلِ ساخته‌شده نباشد (مثل /dashboard
| و /units و /settings/complex) همین یک ویو را می‌گیرد و react-router سمتِ
| کلاینت صفحه‌ی داشبوردیِ درست را رندر می‌کند. باید آخرین روتِ فایل باشد.
|
| ⚠️ `blog/` هم بیرون است (مرحلهٔ ۶ · فاز ۱۱): آدرسِ ناشناخته‌ی بلاگ باید ۴۰۴ِ
| واقعی بگیرد، نه پوسته‌ی داشبورد با ۲۰۰ («۴۰۴ِ نرم») — آن هم زیرِ پیشوندی که
| ایندکس‌شدنی است.
*/
Route::get('/{path}', fn () => view('spa'))
    ->where('path', '^(?!api|build|storage|blog(?:/|$))[^?]*$');
