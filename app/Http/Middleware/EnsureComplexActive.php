<?php

namespace App\Http\Middleware;

use App\Models\Complex;
use App\Support\ComplexResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * مجتمعِ معلق‌شده باید همان لحظه از دسترسِ اعضایش خارج شود (R29).
 *
 * ─── چرا این میدل‌ور لازم شد ───────────────────────────────────────────────
 * `complexes.is_active` از اولین مهاجرت وجود داشت ولی هیچ‌جا خوانده نمی‌شد.
 * ادمینِ کل می‌توانست مجتمعی را غیرفعال کند و هیچ اتفاقی نیفتد.
 *
 * ─── چرا ادمینِ کل مستثناست ────────────────────────────────────────────────
 * تعلیق برای فشار روی مجتمع است، نه کور کردنِ خودِ پلتفرم. ادمینِ کل باید
 * بتواند همان مجتمع را باز کند، داده‌اش را ببیند و تعلیق را بردارد — وگرنه
 * تنها راهِ بازگرداندن، دست‌کاریِ مستقیمِ دیتابیس می‌شد.
 *
 * ─── چرا خروج از نشست نمی‌کنیم ─────────────────────────────────────────────
 * برخلافِ `EnsureActive` (که کاربرِ غیرفعال را logout می‌کند)، اینجا کاربر
 * تقصیری ندارد و حسابش سالم است؛ فقط ساختمانش معلق شده. خروجِ اجباری
 * باعث می‌شد گمان کند حسابش حذف شده. نشست می‌ماند و پیامِ روشن می‌گیرد.
 */
class EnsureComplexActive
{
    private const MESSAGE = 'دسترسی این مجتمع موقتاً تعلیق شده است. با پشتیبانی تماس بگیرید.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // ادمینِ کل و کاربرِ بی‌مجتمع (حالتِ اولیه‌ی R21) از این قاعده بیرونند
        if (! $user || $user->isSuperAdmin()) {
            return $next($request);
        }

        $complexId = ComplexResolver::idFor($user);

        if (! $complexId) {
            return $next($request);
        }

        /*
         * `withoutGlobalScopes` لازم است: `Complex` خودش اسکوپِ مستأجری
         * ندارد ولی خواندنش اینجا پیش از هر تصمیمِ دیگری انجام می‌شود و
         * نباید به وضعیتِ `TenantContext` وابسته باشد.
         */
        $complex = Complex::withoutGlobalScopes()->find($complexId);

        if (! $complex || $complex->is_active) {
            return $next($request);
        }

        $message = self::MESSAGE
            .($complex->suspension_reason ? ' دلیل: '.$complex->suspension_reason : '');

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                // کلاینت با این پرچم صفحه‌ی تعلیق نشان می‌دهد، نه خطای معمولی
                'complexSuspended' => true,
            ], 403);
        }

        Auth::check();

        return response()->view('errors.suspended', ['message' => $message], 403);
    }
}
