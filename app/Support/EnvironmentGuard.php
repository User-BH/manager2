<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * جلوگیری از پیکربندیِ خطرناک در محصول (R44).
 *
 * ─── چه چیزی اندازه‌گیری شد ────────────────────────────────────────────────
 * ⚠️ با `APP_ENV=production` و `APP_DEBUG=true`، یک استثنای مهارنشده پاسخی
 * **۱٫۴ مگابایتی** برمی‌گرداند که این‌ها را در خود دارد:
 *
 *   • پیامِ کاملِ استثنا
 *   • **مقدارِ واقعیِ متغیرهای محیطی** — یعنی رمزِ دیتابیس، `APP_KEY`،
 *     کلیدِ درگاهِ بانکی و کلیدِ پیامک
 *   • مسیرهای سرور
 *
 * این با یک آزمایشِ واقعی سنجیده شد: متغیری با مقدارِ نشانه گذاشته شد و
 * همان مقدار در بدنه‌ی پاسخ پیدا شد.
 *
 * و راهِ رسیدن به این حالت کوتاه است: `.env.example` عمداً `APP_DEBUG=true`
 * دارد (که برای توسعه درست است) و هر کسی که آن را روی سرور کپی کند، دقیقاً
 * همین‌جا می‌ایستد.
 *
 * ─── چرا خاموش‌کردن، و نه ترکاندنِ برنامه ───────────────────────────────────
 * ⚠️ وسوسه‌ی اول این بود که در چنین حالتی استثنا پرتاب شود تا کسی مجبور به
 * اصلاحش باشد. دو دلیل رد شد:
 *
 * ① استثنا در همان لحظه با `APP_DEBUG=true` رندر می‌شود — یعنی درمان، خودش
 *    همان نشتی را راه می‌اندازد که قرار بود جلویش را بگیرد.
 * ② سایتِ ساکنین را به‌خاطر یک اشتباهِ پیکربندی پایین‌آوردن، بدتر از
 *    خاموش‌کردنِ بی‌صدای debug است.
 *
 * پس رفتار «شکستِ امن» است: debug خاموش می‌شود، تخلف در کانالِ `alerts`
 * ثبت می‌شود، و سنجه‌ی سلامت آن را `degraded` گزارش می‌کند تا پنهان نماند.
 */
class EnvironmentGuard
{
    /** کلیدی که سنجه‌ی سلامت برای دیدنِ تخلف‌ها می‌خواند. */
    public const VIOLATIONS_KEY = 'environment.violations';

    /**
     * تنظیماتی که در محصول هرگز نباید روشن باشند.
     *
     * @var array<string, string> کلیدِ config ← دلیل
     */
    private const FORBIDDEN_IN_PRODUCTION = [
        'app.debug' => 'صفحه‌ی خطای debug مقدارِ متغیرهای محیطی را چاپ می‌کند',
    ];

    /**
     * @return array<int, string> تخلف‌های پیداشده (خالی = سالم)
     */
    public function enforce(): array
    {
        if (! app()->isProduction()) {
            return [];
        }

        $violations = [];

        foreach (self::FORBIDDEN_IN_PRODUCTION as $key => $reason) {
            if (config($key) === true) {
                // ⚠️ اول خاموش، بعد گزارش. اگر ترتیب برعکس بود و لاگ‌کردن
                // خطا می‌داد، تنظیمِ خطرناک روشن می‌ماند.
                config([$key => false]);

                $violations[] = "{$key} در محصول روشن بود ({$reason}). خاموش شد.";
            }
        }

        if ($violations !== []) {
            /*
             * ⚠️ `config()` است و نه یک متغیرِ ایستا.
             *
             * سنجه‌ی سلامت در **درخواستِ دیگری** اجرا می‌شود؛ متغیرِ ایستا
             * بینِ دو درخواستِ PHP-FPM باقی نمی‌ماند و گزارش همیشه خالی
             * می‌شد. `enforce()` در بوتِ هر درخواست اجرا می‌شود، پس مقدار
             * همان‌جا تازه ساخته می‌شود.
             */
            config([self::VIOLATIONS_KEY => $violations]);

            foreach ($violations as $violation) {
                Log::channel('alerts')->critical('پیکربندیِ ناامنِ محصول: '.$violation);
            }
        }

        return $violations;
    }

    /**
     * تخلف‌های همین درخواست — برای سنجه‌ی سلامت (R43).
     *
     * @return array<int, string>
     */
    public static function violations(): array
    {
        $violations = config(self::VIOLATIONS_KEY, []);

        return is_array($violations) ? $violations : [];
    }
}
