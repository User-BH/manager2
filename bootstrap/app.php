<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
