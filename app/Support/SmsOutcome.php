<?php

namespace App\Support;

use App\Logging\SecretScrubber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * شمارشِ نتیجه‌ی ارسالِ پیامک، برای هشدار (R64).
 *
 * ─── نقصی که اندازه‌گیری شد ────────────────────────────────────────────────
 * 🔴 `SmsManager::send()` یک `bool` برمی‌گرداند و **هیچ‌جا شمرده نمی‌شد**.
 * تنها ردِ شکست در کلِ سامانه، ستونِ `sms_campaigns.failed` بود که فقط
 * کارزارِ ماهانه‌ی شارژ را پوشش می‌دهد.
 *
 * یعنی مسیرِ **کدِ ورود** — تنها پیامکِ همیشگیِ این سامانه — هیچ ردی از
 * شکست نداشت. سرویسِ پیامک می‌توانست کاملاً قطع باشد و تنها نشانه‌اش این
 * باشد که کاربران زنگ بزنند و بگویند «کد نمی‌آید».
 *
 * ─── چرا کش و نه جدول ──────────────────────────────────────────────────────
 * ⚠️ این داده فقط برای پاسخ به یک پرسش است: «همین حالا پیامک کار می‌کند؟»
 * تاریخچه‌اش ارزشی ندارد و یک جدولِ تازه یعنی مهاجرت، هرس و بکاپِ بیشتر
 * برای چیزی که پس از یک ساعت بی‌معنی می‌شود.
 *
 * ─── چرا سطلِ ساعتی ────────────────────────────────────────────────────────
 * ⚠️ نگه‌داشتنِ فهرستی از رخدادها در یک کلید یعنی «بخوان، عوض کن، بنویس» —
 * و دو ورودِ هم‌زمان یکی از دو شمارش را گم می‌کنند. `increment` اتمی است،
 * پس هر ارسال یک سطلِ ساعتی را جلو می‌برد و پنجره از دو سطلِ آخر خوانده
 * می‌شود.
 *
 * ─── قاعده‌ی ایمنی ─────────────────────────────────────────────────────────
 * 🔴 هیچ‌کدام از این متدها حق ندارند استثنا بدهند. اگر شمارشِ هشدار بشکند
 * و آن استثنا بالا برود، **ارسالِ کدِ ورود** شکست می‌خورد — یعنی ابزارِ
 * پایش، خودش همان چیزی را می‌شکند که برای دیدنش ساخته شده.
 */
class SmsOutcome
{
    private const PREFIX = 'alerts:sms:';

    /** سه ساعت: دو سطلِ خوانده‌شده به‌علاوه‌ی ارفاق برای مرزِ ساعت. */
    private const TTL_SECONDS = 10800;

    /** ثبتِ نتیجه‌ی یک ارسال. */
    public static function record(bool $delivered, ?string $error = null): void
    {
        try {
            self::bump(($delivered ? 'ok:' : 'fail:').self::bucket());

            if (! $delivered && $error !== null && $error !== '') {
                Cache::put(self::PREFIX.'error', self::readable($error), self::TTL_SECONDS);
            }
        } catch (Throwable) {
            // شمارشِ هشدار هرگز نباید ارسالِ پیامک را بشکند
        }
    }

    /**
     * پنجره‌ی اخیر: دو سطلِ آخر.
     *
     * ⚠️ یعنی پنجره بینِ شصت تا صد و بیست دقیقه است، نه دقیقاً شصت دقیقه.
     * برای پرسشِ «پیامک کار می‌کند یا نه» این دقت کافی است و در عوض هیچ
     * کلیدِ اضافه‌ای در کش ساخته نمی‌شود.
     *
     * @return array{attempts: int, failed: int, error: string|null}
     */
    public static function window(): array
    {
        try {
            $buckets = [self::bucket(), self::bucket(-1)];

            $ok = 0;
            $failed = 0;

            foreach ($buckets as $bucket) {
                $ok += (int) Cache::get(self::PREFIX.'ok:'.$bucket, 0);
                $failed += (int) Cache::get(self::PREFIX.'fail:'.$bucket, 0);
            }

            $error = Cache::get(self::PREFIX.'error');

            return [
                'attempts' => $ok + $failed,
                'failed' => $failed,
                'error' => is_string($error) ? $error : null,
            ];
        } catch (Throwable) {
            // کش در دسترس نیست: «داده‌ای ندارم» بهتر از هشدارِ ساختگی است
            return ['attempts' => 0, 'failed' => 0, 'error' => null];
        }
    }

    /**
     * ⚠️ `increment` روی کلیدِ نبوده `false` می‌دهد و چیزی نمی‌نویسد.
     *
     * `add` اتمی است و اگر کلید باشد دست نمی‌زند، پس دو ارسالِ هم‌زمان
     * هم شمارش را صفر نمی‌کنند.
     */
    private static function bump(string $suffix): void
    {
        $key = self::PREFIX.$suffix;

        Cache::add($key, 0, self::TTL_SECONDS);
        Cache::increment($key);
    }

    private static function bucket(int $offset = 0): string
    {
        return now()->addHours($offset)->format('YmdH');
    }

    /**
     * 🔴 پیامِ خطای درگاهِ پیامک **بدونِ پاک‌سازی نباید ذخیره شود**.
     *
     * درسِ R55: کاوه‌نگار کلیدِ API را داخلِ مسیرِ URL می‌گذارد و پیامِ
     * استثنای Guzzle آدرسِ کامل را در خود دارد. این رشته بعداً به وب‌هوکِ
     * هشدار می‌رود — یعنی به یک سرویسِ **بیرونی**، که از فایلِ لاگ هم بدتر
     * است.
     */
    private static function readable(string $error): string
    {
        return Str::limit(
            SecretScrubber::clean(str_replace(["\n", "\r"], ' ', $error)),
            160,
        );
    }
}
