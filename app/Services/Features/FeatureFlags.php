<?php

namespace App\Services\Features;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * پرچم‌های قابلیت (R44).
 *
 * ─── چرا دیتابیس و نه `.env` ───────────────────────────────────────────────
 * ⚠️ خواسته‌ی این مرحله «روشن/خاموش‌کردن قابلیت بدونِ استقرارِ دوباره» بود.
 * پرچمی که در `.env` بنشیند این را نمی‌دهد: تغییرش یعنی ویرایشِ فایل روی
 * سرور، `config:clear` و راه‌اندازیِ دوباره‌ی PHP-FPM — که خودش یک استقرارِ
 * کوچک است و دقیقاً در بحرانی‌ترین لحظه (مثلاً وقتی درگاهِ بانکی قطع شده)
 * کندترین کارِ ممکن است.
 *
 * ─── چرا جدولِ `settings` و نه جدولِ تازه ───────────────────────────────────
 * `settings` از قبل ستون‌های `complex_id` (nullable)، `key`، `value` و
 * کلیدِ یکتای مرکب را دارد — دقیقاً همان شکلی که لازم است. مهاجرتِ تازه
 * فقط یک جدولِ دوم با همان ساختار می‌ساخت.
 *
 * ⚠️ و به همین دلیل Laravel Pennant هم نصب نشد: کلِ چیزی که از آن لازم
 * داریم همین کلاس است، و پروژه قیدِ «پکیجِ بی‌دلیل نصب نکن» دارد.
 *
 * ─── چرا پرچم‌ها سراسری‌اند و نه per-complex ────────────────────────────────
 * `complex_id` عمداً `null` می‌ماند. این پرچم‌ها ابزارِ **عملیاتی**‌اند —
 * «درگاه قطع است، خاموشش کن» — نه ویژگیِ فروش. پرچمِ مخصوصِ هر مجتمع یعنی
 * پشتیبانی باید بداند هر یک از هزاران مجتمع در چه حالتی است، و همان چیزی
 * است که رفع‌عیب را غیرممکن می‌کند.
 */
class FeatureFlags
{
    /** پیشوندِ کلید در جدولِ `settings`، تا با تنظیماتِ دیگر قاطی نشود. */
    public const PREFIX = 'feature.';

    private const CACHE_KEY = 'features:flags';

    /** یک ساعت — و هر نوشتنی بی‌درنگ باطلش می‌کند. */
    private const CACHE_TTL = 3600;

    /**
     * آیا این قابلیت روشن است؟
     *
     * ⚠️ پرچمِ ناشناخته `true` برمی‌گرداند، نه `false`.
     *
     * این تصمیم عمدی است. اگر کسی نامِ پرچم را در `config/features.php`
     * عوض کند یا غلط تایپ کند، با `false` **قابلیت بی‌صدا ناپدید می‌شود** و
     * کسی نمی‌فهمد چرا — کاربر فقط می‌بیند بخشی از سامانه نیست. با `true`
     * رفتار همان چیزی می‌ماند که پیش از افزودنِ پرچم بود، و اشتباه فقط
     * «پرچم کار نمی‌کند» است نه «قابلیت گم شد».
     */
    public function enabled(string $flag): bool
    {
        return $this->all()[$flag] ?? true;
    }

    /**
     * وضعیتِ همه‌ی پرچم‌های تعریف‌شده.
     *
     * @return array<string, bool>
     */
    public function all(): array
    {
        /*
         * ⚠️ شکستِ امن، و این را یک تستِ موجود نشان داد.
         *
         * ─── چه چیزی شکست ──────────────────────────────────────────────────
         * با گذاشتنِ `feature:support_bot` روی گفت‌وگوی پشتیبانی — مسیری
         * عمومی که تا آن لحظه اصلاً به دیتابیس دست نمی‌زد — چهار تستِ
         * موجود با `no such table: settings` افتادند.
         *
         * ─── چرا این از تست مهم‌تر است ───────────────────────────────────────
         * همان اتفاق روی سرورِ واقعی یعنی: اگر دیتابیس بیفتد، **هر مسیری
         * که پرچم دارد ۵۰۰ می‌دهد**. سازوکاری که ساخته شده تا در بحران
         * کمک کند (درگاه قطع است، خاموشش کن) خودش تبدیل به منبعِ خرابیِ
         * بعدی می‌شود.
         *
         * پس نبودنِ منبع یعنی «هیچ‌کس چیزی را عوض نکرده»، و پیش‌فرض‌های
         * اعلام‌شده اعمال می‌شوند — همان رفتاری که پیش از افزودنِ پرچم بود.
         */
        try {
            $stored = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
                return Setting::query()
                    ->whereNull('complex_id')
                    ->where('key', 'like', self::PREFIX.'%')
                    ->pluck('value', 'key')
                    ->mapWithKeys(fn (?string $value, string $key): array => [
                        substr($key, strlen(self::PREFIX)) => $value === '1',
                    ])
                    ->all();
            });
        } catch (Throwable) {
            $stored = [];
        }

        $flags = [];

        // ⚠️ حلقه روی **تعریف‌ها** است نه روی ردیف‌های دیتابیس: کلیدِ
        // به‌جامانده از پرچمی که حذف شده نباید در خروجی ظاهر شود.
        foreach ($this->definitions() as $flag => $definition) {
            $flags[$flag] = $stored[$flag] ?? (bool) ($definition['default'] ?? true);
        }

        return $flags;
    }

    /**
     * روشن یا خاموش‌کردنِ یک پرچم.
     *
     * @throws InvalidArgumentException برای پرچمی که در فهرست نیست
     */
    public function set(string $flag, bool $enabled): void
    {
        if (! array_key_exists($flag, $this->definitions())) {
            /*
             * ⚠️ کلیدِ ناشناخته پذیرفته نمی‌شود.
             *
             * بدونِ این، هر کسی که به پنلِ سوپرادمین دسترسی دارد می‌توانست
             * ردیفِ دلخواه در `settings` بسازد — هم جدول پر از کلیدِ مرده
             * می‌شد، هم یک غلطِ تایپیِ ساده بی‌صدا ذخیره می‌شد و کسی
             * نمی‌فهمید چرا پرچم اثر ندارد.
             */
            throw new InvalidArgumentException("پرچمِ «{$flag}» تعریف نشده است.");
        }

        Setting::query()->updateOrCreate(
            ['complex_id' => null, 'key' => self::PREFIX.$flag],
            ['value' => $enabled ? '1' : '0'],
        );

        $this->flush();
    }

    /**
     * تعریف‌های کامل، همراه با وضعیتِ فعلی — برای پنلِ سوپرادمین.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalogue(): array
    {
        $state = $this->all();

        return collect($this->definitions())
            ->map(fn (array $definition, string $flag): array => [
                'key' => $flag,
                'label' => $definition['label'] ?? $flag,
                'description' => $definition['description'] ?? '',
                'default' => (bool) ($definition['default'] ?? true),
                'enabled' => $state[$flag] ?? true,
            ])
            ->values()
            ->all();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function definitions(): array
    {
        $flags = config('features.flags', []);

        return is_array($flags) ? $flags : [];
    }
}
