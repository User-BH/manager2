<?php

namespace App\Providers;

use App\Support\Jalali;
use App\Support\Phone;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Active complex for the multi-tenant query scope. A null id means
        // "no scoping" (super-admin or console). Middleware sets it per request.
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        Paginator::useTailwind();
        Date::macro('jalali', fn () => Jalali::date($this));

        $this->registerRateLimiters();
    }

    /**
     * محدودیت نرخ درخواست روی مسیرهای احراز هویت.
     *
     * بدون این‌ها، هم حدس‌زدن رمز و کد پیامکی بی‌هزینه بود و هم مهم‌تر:
     * هر کسی می‌توانست با درخواست انبوهِ کد، اعتبار پیامکِ سامانه را
     * (که پولی است) تمام کند.
     *
     * کلید هر محدودیت ترکیبی از IP و شماره تلفن است تا نه یک IP بتواند
     * روی شماره‌های مختلف مانور بدهد و نه چند IP روی یک شماره.
     */
    private function registerRateLimiters(): void
    {
        // ورود با رمز: ۵ تلاش در دقیقه برای هر شماره، و ۲۰ در دقیقه برای هر IP
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by($this->phoneKey($request)),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        /*
         * درخواست کد پیامکی سخت‌گیرانه‌ترین است، چون هر درخواست یک پیامکِ
         * واقعی و هزینه‌دار می‌فرستد. OtpService خودش ۶۰ ثانیه فاصله‌ی
         * ارسال مجدد دارد ولی آن فقط per-phone است و جلوی حمله روی
         * شماره‌های متعدد را نمی‌گیرد.
         */
        RateLimiter::for('otp-request', fn (Request $request) => [
            Limit::perMinutes(10, 3)->by($this->phoneKey($request)),
            Limit::perMinutes(10, 15)->by($request->ip()),
        ]);

        // تایید کد: کد ۵ رقمی است، پس تعداد تلاش باید کم بماند
        RateLimiter::for('otp-verify', fn (Request $request) => [
            Limit::perMinute(5)->by($this->phoneKey($request)),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        // ثبت‌نام: جلوگیری از ساخت انبوه حساب
        RateLimiter::for('register', fn (Request $request) => [
            Limit::perHour(5)->by($request->ip()),
        ]);

        /*
         * بازگشت از درگاه پشت میدل‌ور `auth` نیست (توضیحش در routes/web.php)،
         * پس محدودیت نرخ جای آن را می‌گیرد تا نشود شناسه‌ی تراکنش‌ها را پیمود.
         * سقف دست‌ودل‌بازانه است چون یک پرداختِ سالم فقط یک بار برمی‌گردد و
         * رفرش‌های کاربر هم نباید به دیوار بخورند.
         */
        RateLimiter::for('gateway-callback', fn (Request $request) => [
            Limit::perMinute(30)->by($request->ip()),
        ]);

        /*
         * بازیابی کل سیستم. هر اجرای واقعی، کل داده را جایگزین می‌کند و یک
         * بکاپ ایمنی روی دیسک می‌گذارد، پس تکرار سریعش نه معنا دارد نه رایگان است.
         *
         * ولی اجرای آزمایشی (`dry_run`) فقط فایل را می‌خواند و چیزی را عوض
         * نمی‌کند. اگر همان سقف را داشته باشد، ادمینی که چند فایل را بررسی
         * می‌کند یا عبارت تایید را چند بار اشتباه تایپ می‌کند، درست وسط یک
         * بحران یک ساعت از بازیابی محروم می‌شود. پس فقط اجرای واقعی سقف دارد.
         */
        RateLimiter::for('system-restore', fn (Request $request) => $request->boolean('dry_run')
            ? Limit::none()
            : Limit::perHour(10)->by($request->user()?->id ?: $request->ip()));

        /*
        | مسیرهای گران.
        |
        | تا پیش از این فقط مسیرهای ورود سقف داشتند و بقیه آزاد بودند. هیچ‌کدام
        | از این‌ها «حمله» لازم ندارند تا سرور را زمین بزنند؛ یک اسکریپت ساده یا
        | حتی یک تب که گیر کرده کافی است.
        */

        // جستجو در هر فراخوانی شش کوئری LIKE '%…%' روی جدول‌های بزرگ می‌زند
        RateLimiter::for('search', fn (Request $request) => [
            Limit::perMinute(40)->by($request->user()?->id ?: $request->ip()),
        ]);

        // پیام‌رسان هر ۸ ثانیه poll می‌شود (≈۷ در دقیقه)؛ سقف جای تنفس دارد
        RateLimiter::for('messenger', fn (Request $request) => [
            Limit::perMinute(40)->by($request->user()?->id ?: $request->ip()),
        ]);

        // هر بکاپ یک فایل کامل روی دیسک می‌سازد
        RateLimiter::for('backups', fn (Request $request) => [
            Limit::perHour(12)->by($request->user()?->id ?: $request->ip()),
        ]);

        // صدور قبض برای کل مجتمع، سنگین‌ترین محاسبه‌ی سامانه است
        RateLimiter::for('bills-generate', fn (Request $request) => [
            Limit::perHour(20)->by($request->user()?->id ?: $request->ip()),
        ]);

        // آپلود رسید: هم فضا مصرف می‌کند و هم صف بررسی مدیر را پر می‌کند
        RateLimiter::for('receipt-upload', fn (Request $request) => [
            Limit::perHour(20)->by($request->user()?->id ?: $request->ip()),
        ]);
    }

    /** کلید یکتا بر پایه‌ی شماره‌ی نرمال‌شده + IP. */
    private function phoneKey(Request $request): string
    {
        $phone = (string) $request->input('phone', '');

        return Phone::normalize($phone).'|'.$request->ip();
    }
}
