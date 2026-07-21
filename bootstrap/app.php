<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // مسیرهای API هم روی گروه «web» سوار می‌شوند (در routes/api.php)، چون
        // اپلیکیشن React از همین دامنه سرو می‌شود و احراز هویتش با نشست و
        // کوکی است، نه توکن bearer. این‌طور نیازی به Sanctum و مدیریت توکن
        // در سمت کلاینت نیست و حفاظت CSRF هم فعال می‌ماند.
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')
                ->prefix('api')
                ->name('api.')
                ->group(__DIR__.'/../routes/api.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
        | پشت CDN (ArvanCloud، Cloudflare و…) درخواست با HTTP به سرور می‌رسد
        | حتی وقتی کاربر HTTPS باز کرده. بدون اعتماد به پراکسی، لاراول درخواست
        | را ناامن می‌بیند و آدرس‌ها را با http:// می‌سازد؛ نتیجه‌اش mixed
        | content و بلاک‌شدن CSS/JS روی صفحه‌ی HTTPS است. IP واقعی کاربر هم
        | به‌جای IP کاربر، IP سرورهای CDN ثبت می‌شود.
        |
        | مقدار TRUSTED_PROXIES در .env تعیین می‌شود:
        |   TRUSTED_PROXIES=*                 → همه (فقط وقتی مبدأ مستقیم در
        |                                       دسترس نیست یا فایروال دارد)
        |   TRUSTED_PROXIES=1.2.3.4,5.6.7.8   → فقط همین IPها
        |   خالی                              → هیچ پراکسی‌ای معتبر نیست
        */
        $proxies = trim((string) env('TRUSTED_PROXIES'));

        $middleware->trustProxies(
            at: $proxies === '*' ? '*' : array_filter(array_map('trim', explode(',', $proxies))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->web(append: [
            \App\Http\Middleware\EnsureActive::class,
            \App\Http\Middleware\SetCurrentComplex::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
