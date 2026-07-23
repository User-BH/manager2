<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * حسابی که غیرفعال شده باید همان لحظه از نشست بیرون بیاید.
 */
class EnsureActive
{
    private const MESSAGE = 'حساب کاربری شما غیرفعال شده است. با مدیر ساختمان تماس بگیرید.';

    public function handle(Request $request, Closure $next): Response
    {
        if (($user = $request->user()) && ! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            /*
             * پاسخ باید با نوع درخواست بخواند.
             *
             * پیش از این همیشه ریدایرکت برمی‌گشت؛ برای درخواست API یعنی
             * مرورگر ریدایرکت را دنبال می‌کرد، صفحه‌ی HTML ورود را می‌گرفت و
             * لایه‌ی `api()` چون پاسخ JSON نبود `undefined` تحویل می‌داد.
             * نتیجه‌اش صفحه‌های خالی و خطاهای بی‌ربط بود، بی‌آنکه کاربر بفهمد
             * حسابش غیرفعال شده.
             */
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => self::MESSAGE,
                    // کلاینت با این پرچم به‌جای نمایش یک خطای معمولی، کاربر
                    // را به صفحه‌ی ورود می‌فرستد و حالتِ محلی را پاک می‌کند.
                    'accountDisabled' => true,
                ], 403);
            }

            // کلید خطا «phone» است نه «email»: ورود این سامانه با شماره‌ی
            // تلفن انجام می‌شود و پیام باید زیر همان فیلد بنشیند.
            return redirect()->route('login')->withErrors(['phone' => self::MESSAGE]);
        }

        return $next($request);
    }
}
