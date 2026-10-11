<?php

namespace App\Http\Middleware;

use App\Support\Preview;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * مرزِ Preview و محصول (مرحلهٔ ۹ · فاز ۸).
 *
 * ① اگر نمونه به پایگاه‌داده‌ی «نادرست» وصل است (Preview روی پایگاه‌داده‌ی
 *    بی‌برچسب، یا محصول روی پایگاه‌داده‌ی Preview) یا پیکربندیِ ایمنیِ Preview
 *    ناقص است، **هیچ** درخواستی سرویس نمی‌گیرد — ۵۰۳ با دلیلِ روشن. شکستِ بسته:
 *    بهتر از دیدنِ دادهٔ واقعی در Preview یا نوشتنِ دادهٔ آزمایشی در محصول.
 *
 * ② روی Preview، بی کوکیِ دسترسی، ۴۰۳. کوکی با یک بار باز کردنِ هر نشانی با
 *    `?preview_access=کلید` گرفته می‌شود (کلید خودش در کوکی نمی‌ماند).
 */
class EnsurePreviewSafety
{
    public function handle(Request $request, Closure $next): Response
    {
        $violations = Preview::violations();

        if ($violations !== []) {
            Log::channel('alerts')->critical('[preview] نمونه به‌خاطرِ مرزِ Preview/محصول سرویس نمی‌دهد', ['problems' => $violations]);

            return $this->refuse($request, 503, 'این نمونه به‌خاطرِ پیکربندیِ ناامن سرویس نمی‌دهد: '.implode(' ', $violations));
        }

        if (! Preview::active()) {
            return $next($request);
        }

        $given = $request->query(Preview::ACCESS_COOKIE);
        if (is_string($given) && hash_equals((string) config('preview.access_key'), $given)) {
            // کلید از نشانی برداشته می‌شود تا در تاریخچه و لاگِ دسترسی نماند
            $clean = $request->fullUrlWithoutQuery([Preview::ACCESS_COOKIE]);

            return redirect()->to($clean)->withCookie(cookie(
                Preview::ACCESS_COOKIE, Preview::accessToken(), 60 * 24 * 30, '/', null, $request->secure(), true, false, 'lax',
            ));
        }

        $cookie = $request->cookie(Preview::ACCESS_COOKIE);
        if (! is_string($cookie) || ! hash_equals(Preview::accessToken(), $cookie)) {
            return $this->refuse($request, 403, 'دسترسی به Preview محدود است. نشانیِ ورودِ Preview را از تیم بگیرید.');
        }

        return $next($request);
    }

    private function refuse(Request $request, int $status, string $message): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['success' => false, 'message' => $message, 'code' => 'preview.refused'], $status);
        }

        return response($message, $status, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
