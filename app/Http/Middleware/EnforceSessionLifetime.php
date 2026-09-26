<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * سقفِ مطلقِ عمرِ نشست (مرحلهٔ ۵ · فاز ۱۰).
 *
 * ─── 🔴 چه چیزی باز بود ──────────────────────────────────────────────────────
 * `SESSION_LIFETIME` فقط بیکاری را می‌شمارد: نشستی که هر دو ساعت یک درخواست
 * بدهد **هرگز** تمام نمی‌شود. کوکیِ دزدیده‌شده‌ای که مهاجم زنده نگهش دارد تا
 * ابد کار می‌کرد. این همان نقشی است که در JWT «حداکثرِ عمرِ Refresh Token»
 * دارد.
 *
 * حالا از لحظه‌ی ورود، پس از `session.absolute_lifetime` دقیقه نشست بسته
 * می‌شود و کاربر باید دوباره وارد شود. دستگاهِ مورداعتماد («مرا به خاطر
 * بسپار») نشستِ **تازه** با شناسه‌ی تازه می‌سازد — همان رفتارِ remember.
 *
 * ⚠️ نشستی که زمانِ شروع ندارد (پیش از این اصلاح ساخته شده) از همین لحظه
 * شمرده می‌شود، نه اینکه بی‌سقف بماند.
 */
class EnforceSessionLifetime
{
    public const STARTED_AT = 'auth.started_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! Auth::check()) {
            return $next($request);
        }

        $session = $request->session();
        $startedAt = (int) $session->get(self::STARTED_AT, 0);

        if ($startedAt === 0) {
            $session->put(self::STARTED_AT, now()->timestamp);

            return $next($request);
        }

        $limit = (int) config('session.absolute_lifetime') * 60;

        if (now()->timestamp - $startedAt <= $limit) {
            return $next($request);
        }

        Auth::logout();
        $session->invalidate();
        $session->regenerateToken();

        $message = 'زمانِ نشستِ شما به پایان رسید. برای امنیتِ حساب دوباره وارد شوید.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'code' => 'auth.session_expired',
                'csrfToken' => csrf_token(),
            ], 401);
        }

        return redirect('/auth');
    }
}
