<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * خواندنِ مهمان در API هیچ کوکی‌ای نمی‌نویسد (۱۳/۹-ج؛ مشاهده‌ی بازِ ۱۳/۳).
 *
 * ─── 🔴 شکافی که بازسازی شد ────────────────────────────────────────────────
 * تبی با نشستِ منقضی درخواستی می‌فرستد (نظرسنجیِ زنگوله، `/me`) و پیش از رسیدنِ پاسخ، کاربر در تبِ دیگر وارد می‌شود.
 * پاسخِ دیررس کوکیِ نشست (لاراول همان شناسه‌ی منقضیِ خوش‌شکل را برای نشستِ مهمان نگه می‌دارد)، XSRF، سنجاقِ زمینه
 * و شناسه‌ی دستگاه را می‌فرستد؛ مرورگر آن‌ها را **روی** کوکی‌های ورودِ تازه می‌نشاند و کاربر بی‌صدا بیرون می‌افتد
 * (`SessionLifecycleTest::test_a_late_guest_response_from_a_stale_tab_does_not_sign_out_a_fresh_login`).
 *
 * ─── قاعده ─────────────────────────────────────────────────────────────────
 * پاسخِ GET/HEAD ِ API به مهمان کوکی نمی‌گذارد. نشست و توکنِ مهمان را صفحه‌ی وب (توکنِ CSRF در متاتگ) یا درخواستِ
 * تغییردهنده (ورود، ثبت‌نام) می‌سازد — هیچ‌کدام خواندنِ API نیستند. تنها استثنا `csrf-token` است که پس از ۴۱۹ عمداً
 * نشست و توکنِ تازه می‌دهد.
 *
 * ⚠️ پیش از `EncryptCookies` می‌نشیند (اولِ گروهِ web): کوکی‌های پاسخ را `StartSession`، `VerifyCsrfToken` و
 * `AddQueuedCookiesToResponse` در بازگشت می‌گذارند، پس فقط لایه‌ای بیرون از همه می‌تواند برشان دارد.
 */
class GuestReadsKeepTheSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodCacheable() || ! $request->is('api/*') || $request->routeIs('*csrf-token')) {
            return $response;
        }
        if (Auth::guard('web')->check()) {
            return $response;
        }

        foreach ($response->headers->getCookies() as $cookie) {
            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
        }

        return $response;
    }
}
