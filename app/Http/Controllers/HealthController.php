<?php

namespace App\Http\Controllers;

use App\Services\Health\HealthReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * مسیرِ بررسیِ سلامت (R43).
 *
 * ─── چرا دو سطحِ پاسخ ──────────────────────────────────────────────────────
 * ⚠️ این مسیر بدونِ احراز هویت باز است، چون متعادل‌کننده‌ی بار و اسکریپتِ
 * استقرار باید بتوانند بزنندش. پس هر چیزی که برمی‌گرداند، عمومی است.
 *
 * گزارشِ کامل به مهاجم می‌گوید کدام وابستگی مرده، دیسک چقدر پر است و هر
 * سنجه چند میلی‌ثانیه طول می‌کشد — یعنی نقشه‌ی دقیقی از اینکه کِی و کجا
 * فشار بیاورد. پس:
 *
 *   بدونِ رمز → فقط `{"status": "ok"}` و کدِ وضعیت
 *   با رمزِ درست → گزارشِ کاملِ شش سنجه
 *
 * کدِ وضعیت به‌تنهایی برای دروازه‌ی استقرارِ R42 کافی است، پس آن اسکریپت
 * نیازی به رمز ندارد.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request, HealthReport $health): JsonResponse
    {
        $report = $health->run();

        /*
         * فقط `down` کدِ ۵۰۳ می‌گیرد.
         *
         * ⚠️ `degraded` عمداً ۲۰۰ می‌ماند: دیسکِ ۸۵٪ یا صفِ شلوغ روی همه‌ی
         * گره‌ها هم‌زمان رخ می‌دهد و ۵۰۳‌دادنش یعنی متعادل‌کننده همه را با هم
         * از مدار خارج کند — یک هشدارِ ظرفیت تبدیل به قطعیِ کامل می‌شود.
         */
        $status = $report['status'] === HealthReport::DOWN ? 503 : 200;

        $response = $this->authorized($request)
            ? $report
            : ['status' => $report['status']];

        /*
         * ⚠️ پاسخ هرگز کش نمی‌شود.
         *
         * بدونِ این هدر، یک CDN یا پروکسیِ میانی می‌تواند «سالم» را نگه دارد
         * و همان را در تمامِ مدتِ قطعی تحویل بدهد — یعنی دقیقاً در لحظه‌ای که
         * بررسیِ سلامت باید حرف بزند، ساکت می‌ماند.
         */
        return response()->json($response, $status)
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    /**
     * رمز از هدر خوانده می‌شود، نه از پارامترِ آدرس.
     *
     * پارامترِ آدرس در لاگِ دسترسیِ nginx، در تاریخچه‌ی مرورگر و در هدرِ
     * Referer می‌نشیند؛ رمزی که در سه جا نوشته می‌شود دیگر رمز نیست.
     */
    private function authorized(Request $request): bool
    {
        $secret = config('health.secret');

        if (! is_string($secret) || $secret === '') {
            // بدونِ رمزِ تنظیم‌شده، گزارشِ کامل هرگز عمومی نمی‌شود
            return false;
        }

        $given = $request->header('X-Health-Secret');

        // مقایسه‌ی زمان‌ثابت: مقایسه‌ی معمولی با اولین بایتِ نادرست برمی‌گردد
        // و همان اختلافِ زمان، رمز را بایت‌به‌بایت لو می‌دهد
        return is_string($given) && hash_equals($secret, $given);
    }
}
