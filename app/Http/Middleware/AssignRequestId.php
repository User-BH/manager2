<?php

namespace App\Http\Middleware;

use App\Support\RequestId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * نشاندنِ شناسه‌ی درخواست، پیش از هر چیزِ دیگر (R63).
 *
 * ─── چرا اولین میدل‌ور ─────────────────────────────────────────────────────
 * ⚠️ هر خطی که پس از این نوشته شود باید شناسه داشته باشد — از جمله لاگِ
 * خودِ میدل‌ورهای امنیتی. اگر این پایین‌تر می‌نشست، همان خطوطی که روزِ
 * حادثه بیشتر لازم می‌شوند بی‌شناسه می‌ماندند.
 *
 * ─── شناسه‌ی ورودی ─────────────────────────────────────────────────────────
 * اگر روزی یک پروکسی جلوی برنامه بنشیند و خودش `X-Request-Id` بگذارد،
 * پذیرفتنش یعنی رد در هر دو لایه یکی می‌ماند.
 *
 * 🔴 ولی آن هدر کاملاً در اختیارِ فرستنده است. `RequestId::acceptable`
 * فقط دقیقاً همان قالبی را می‌پذیرد که خودمان می‌سازیم؛ هر چیزِ دیگری —
 * از جمله مقداری که `\n` دارد و می‌تواند خطِ جعلیِ لاگ بسازد — بی‌سروصدا
 * با شناسه‌ی تازه جایگزین می‌شود.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        RequestId::set($request->headers->get(RequestId::HEADER));

        $response = $next($request);

        self::apply($response);

        return $response;
    }

    /**
     * ⚠️ جدا از `handle`، چون پاسخی که از استثنا ساخته می‌شود از «پس‌ازِ»
     * میدل‌ورها رد نمی‌شود (درسِ R61).
     *
     * و اینجا مهم‌تر از همه‌جاست: پاسخِ خطا دقیقاً همانی است که کاربر
     * شناسه‌اش را برای پشتیبانی می‌خواند.
     */
    public static function apply(Response $response): void
    {
        $response->headers->set(RequestId::HEADER, RequestId::current());
    }
}
