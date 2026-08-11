<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Services\System\PlatformStats;
use App\Support\Observability;
use Illuminate\Http\JsonResponse;

/**
 * آمارِ کلِ پلتفرم برای ادمینِ کل (R29).
 *
 * دسترسی را میدل‌ورِ `role:super_admin` روی گروهِ مسیرها می‌گیرد، پس اینجا
 * بررسیِ دومی لازم نیست — و اگر بود، دو جای متفاوت مسئولِ یک قاعده می‌شدند.
 */
class PlatformStatsController extends Controller
{
    public function index(PlatformStats $stats): JsonResponse
    {
        return response()->json($stats->all() + [
            /*
             * وضعیتِ ابزارهای آنالیز (R8) کنارِ آمار می‌آید و نه در صفحه‌ی
             * جدا: ادمینی که می‌پرسد «چند کاربر داریم؟» بلافاصله بعدش
             * می‌پرسد «آیا اصلاً داده‌ای جمع می‌شود؟». جواب‌دادن به دومی در
             * صفحه‌ی دیگر یعنی کسی نمی‌پرسدش.
             */
            'analytics' => $this->analyticsStatus(),
        ]);
    }

    /**
     * کدام ابزارِ آنالیز واقعاً پیکربندی شده.
     *
     * فقط **روشن/خاموش** برمی‌گردد و نه خودِ شناسه‌ها: کلیدِ Sentry و GA
     * اعتبارنامه‌اند و در پاسخِ آماری جایی ندارند.
     *
     * @return array<string, bool>
     */
    private function analyticsStatus(): array
    {
        try {
            $config = Observability::clientConfig();
        } catch (\Throwable) {
            // خواندنِ پیکربندی نباید صفحه‌ی آمار را بترکاند (درسِ R16)
            $config = [];
        }

        return [
            'ga4' => ! empty($config['ga4MeasurementId']),
            'gtm' => ! empty($config['gtmContainerId']),
            'clarity' => ! empty($config['clarityProjectId']),
            'sentry' => ! empty($config['sentryDsn']),
        ];
    }
}
