<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * محیطِ Preview — «محیطِ اعتبارسنجیِ امن» (مرحلهٔ ۹ · فاز ۸؛ تصمیمِ ۱۱ ِ کارفرما).
 *
 * ─── یک برنامه، دو پایگاه‌داده ───────────────────────────────────────────
 * Preview کدِ جدا یا نسخه‌ی ساده‌شده نیست: همان برنامه با `APP_ENV=preview`.
 * هرچه این‌جا فرق می‌کند فقط **مرزِ ایمنی** است:
 *
 *   • پیامکِ واقعی نه — درایور همیشه `log`، و کد در پاسخِ API برای آزمونگر؛
 *   • درگاهِ بانکیِ واقعی نه — همیشه سندباکس، برای قبض و برای اشتراک؛
 *   • ابزارِ تحلیل نه (GA4، GTM، Clarity) — دادهٔ آزمایشی آمارِ محصول را آلوده نکند؛
 *   • ایندکس نه — `noindex` روی هر پاسخ و `Disallow: /` در robots.txt؛
 *   • نوارِ «Preview» روی همه‌ی صفحه‌ها.
 *
 * ─── برچسبِ پایگاه‌داده: Preview هرگز روی دادهٔ واقعی ───────────────────────
 * `preview:reset` پایگاه‌داده‌ی Preview را با `database_role = preview` برچسب
 * می‌زند. Preview ای که به پایگاه‌داده‌ی بی‌برچسب وصل شود (مثلاً پایگاه‌داده‌ی
 * محصول) سرویس نمی‌دهد؛ و محصولی که به پایگاه‌داده‌ی برچسب‌دار وصل شود هم نه —
 * «انتشار با جایگزینیِ پایگاه‌داده» (بندِ ۲۲ ِ فایل ۲) این‌طور ناممکن است.
 * برچسب مستقیم از پایگاه‌داده خوانده می‌شود، نه از کش: کشِ مشترک نباید پاسخ دهد.
 */
final class Preview
{
    public const ENVIRONMENT = 'preview';

    /** کلیدِ برچسب در `settings` (سطحِ سکو). */
    public const ROLE_KEY = 'database_role';

    /** نامِ کوکیِ دسترسی و پارامترِ یک‌باره‌ی گرفتنش. */
    public const ACCESS_COOKIE = 'preview_access';

    public static function active(): bool
    {
        return app()->environment(self::ENVIRONMENT);
    }

    /**
     * برچسبِ پایگاه‌داده‌ی فعلی؛ جدولِ نساخته یا خطا = بی‌برچسب.
     * یک کوئریِ تک‌سطری روی کلیدِ یکتا — بی `hasTable`، چون روی هر درخواستِ محصول می‌آید.
     */
    public static function databaseRole(): ?string
    {
        try {
            $value = Setting::query()->whereNull('complex_id')->where('key', self::ROLE_KEY)->value('value');

            return $value === null ? null : (string) $value;
        } catch (Throwable) {
            return null;
        }
    }

    /** فقط `preview:reset`، روی پایگاه‌داده‌ای که خودش ساخته. */
    public static function markDatabase(): void
    {
        Setting::updateOrCreate(['complex_id' => null, 'key' => self::ROLE_KEY], ['value' => self::ENVIRONMENT]);
        SystemSettings::forget();
    }

    /**
     * اگر این نمونه نباید سرویس بدهد، دلیلش؛ وگرنه `null`.
     *
     * @return list<string>
     */
    public static function violations(): array
    {
        if (! self::active()) {
            // توسعه و آزمون روی هر پایگاه‌داده‌ای آزادند و کوئریِ اضافه نمی‌خورند
            if (! app()->isProduction()) {
                return [];
            }

            // محصول روی پایگاه‌داده‌ی Preview: انتشار با جابه‌جاییِ پایگاه‌داده
            return self::databaseRole() === self::ENVIRONMENT
                ? ['این نمونه‌ی محصول به پایگاه‌داده‌ی Preview وصل است؛ پایگاه‌داده‌ی واقعی هرگز با Preview جایگزین نمی‌شود.']
                : [];
        }

        $problems = [];

        if (self::databaseRole() !== self::ENVIRONMENT) {
            $problems[] = 'پایگاه‌داده‌ی این Preview برچسبِ Preview ندارد (شاید پایگاه‌داده‌ی محصول است). '
                .'برای ساختنِ پایگاه‌داده‌ی آزمایشی: php artisan preview:reset';
        }

        $production = config('preview.production_database');
        if (filled($production) && DB::connection()->getDatabaseName() === $production) {
            $problems[] = 'Preview به پایگاه‌داده‌ی محصول ('.$production.') وصل است.';
        }

        if (blank(config('preview.access_key'))) {
            $problems[] = 'PREVIEW_ACCESS_KEY تنظیم نشده؛ حساب‌های نمونه رمزِ عمومی دارند و Preview ِ باز یعنی پنلِ باز.';
        }

        // کشِ مشترک تنظیماتِ محصول را به Preview می‌داد؛ کوکیِ هم‌نام نشستِ محصول را می‌پوشاند
        if (! str_contains((string) config('cache.prefix'), 'preview')) {
            $problems[] = 'CACHE_PREFIX ِ Preview باید «preview» داشته باشد تا با کشِ محصول قاطی نشود.';
        }
        if (! str_contains((string) config('session.cookie'), 'preview')) {
            $problems[] = 'SESSION_COOKIE ِ Preview باید «preview» داشته باشد تا نشستِ محصول را نپوشاند.';
        }

        return $problems;
    }

    /** کوکیِ دسترسی: هش از کلید، تا خودِ کلید در مرورگر نماند. */
    public static function accessToken(): string
    {
        return hash_hmac('sha256', 'preview-access', (string) config('preview.access_key'));
    }
}
