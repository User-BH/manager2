<?php

namespace App\Services\Health;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * شمارشِ درخواست‌های کند (مرحلهٔ ۵ · فاز ۹ · بندِ ۷۸: «Slow API»).
 *
 * ⚠️ فقط **شمارش** در کش، در سطل‌های ده‌دقیقه‌ای؛ نه جدولِ تازه و نه
 * ابزارِ APM. پرسشِ هشدار ساده است: «در ساعتِ گذشته چند درخواست از آستانه
 * کندتر بود؟». جزئیاتِ هر مورد در لاگ است.
 */
class SlowRequests
{
    private const BUCKET_MINUTES = 10;

    public static function record(): void
    {
        try {
            $key = self::key(now()->timestamp);
            Cache::add($key, 0, now()->addHours(2));
            Cache::increment($key);
        } catch (Throwable) {
            // شمارشِ کندی نباید خودش درخواست را بشکند
        }
    }

    /** شمارِ درخواست‌های کندِ شصت دقیقه‌ی گذشته. */
    public static function lastHour(): int
    {
        $now = now()->timestamp;
        $total = 0;

        for ($i = 0; $i < 60 / self::BUCKET_MINUTES; $i++) {
            $total += (int) Cache::get(self::key($now - $i * self::BUCKET_MINUTES * 60), 0);
        }

        return $total;
    }

    private static function key(int $timestamp): string
    {
        return 'slow-requests:'.intdiv($timestamp, self::BUCKET_MINUTES * 60);
    }
}
