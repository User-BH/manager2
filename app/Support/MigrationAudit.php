<?php

namespace App\Support;

/**
 * تشخیصِ مهاجرتِ مخرب، از روی متنِ خودِ فایل (R57).
 *
 * ─── چرا متنِ فایل و نه اجرای واقعی ────────────────────────────────────────
 * ⚠️ فهمیدنِ اینکه یک مهاجرت داده را از بین می‌برد، **پیش از اجرایش** لازم
 * است. اجرای آزمایشی روی دیتابیسِ محصول همان کاری است که می‌خواهیم جلویش
 * را بگیریم، و `--pretend` هم برای مهاجرت‌هایی که `DB::table()` صدا می‌زنند
 * SQL کامل نمی‌دهد.
 *
 * ─── محدودیتی که باید دانست ────────────────────────────────────────────────
 * ⚠️ این یک تحلیلِ **متنی** است، نه تجزیه‌ی نحوی. مهاجرتی که نامِ متد را
 * پویا بسازد از دستش در می‌رود. آن حالت در این پروژه وجود ندارد و اگر روزی
 * پیدا شود، دستِ‌کم `MigrationSafetyTest` برگشت‌پذیری‌اش را می‌سنجد.
 *
 * در عوض، سادگی‌اش یعنی هیچ مهاجرتی برای سنجیده‌شدن لازم نیست اجرا شود.
 */
class MigrationAudit
{
    /**
     * عملیاتی که **داده را از بین می‌برند**.
     *
     * ⚠️ `addColumn` اینجا نیست و عمدی است: افزودنِ ستون برگشت‌پذیر است و
     * چیزی را پاک نمی‌کند. فهرست فقط چیزهایی را دارد که `down()` هم
     * نمی‌تواند برشان گرداند — یک ستونِ حذف‌شده را می‌شود دوباره ساخت، ولی
     * محتوایش رفته است.
     *
     * @var array<string, string>
     */
    private const DESTRUCTIVE = [
        'حذفِ ستون' => '/->dropColumn\(|DROP\s+COLUMN/i',
        'تغییرِ نامِ ستون' => '/->renameColumn\(/',
        'تغییرِ نوعِ ستون' => '/->change\(\)|\b(MODIFY|CHANGE)\s+COLUMN/i',
        'حذفِ جدول' => '/Schema::drop(IfExists)?\(/',
        'تغییرِ نامِ جدول' => '/Schema::rename\(|->renameTable\(/',
        'حذفِ ردیف' => '/->delete\(\)|\bDELETE\s+FROM\b|\bTRUNCATE\b/i',
    ];

    /**
     * عملیاتِ مخربی که در `up()`ِ این فایل هست.
     *
     * ⚠️ فقط `up()` سنجیده می‌شود. `down()` **باید** مخرب باشد — کارش
     * برگرداندنِ `up()` است — و سنجیدنش یعنی هر مهاجرتِ سالمی هم مخرب
     * علامت بخورد.
     *
     * @return array<int, string>
     */
    public static function destructiveOperations(string $file): array
    {
        $up = self::upBody(self::sourceWithoutComments($file));

        $found = [];

        foreach (self::DESTRUCTIVE as $label => $pattern) {
            if (preg_match($pattern, $up) === 1) {
                $found[] = $label;
            }
        }

        return $found;
    }

    /**
     * آیا نویسنده صریحاً پذیرفته که این مهاجرت مخرب است؟
     *
     * ⚠️ دلیل هم اجباری است. `@destructive` تنها، فقط یک مهرِ لاستیکی
     * می‌شد؛ نوشتنِ دلیل کسی را وادار می‌کند یک بار به آنچه از بین می‌رود
     * فکر کند.
     */
    public static function isAcknowledged(string $file): bool
    {
        $source = (string) file_get_contents($file);

        if (preg_match('/@destructive\s+(.+)$/m', $source, $match) !== 1) {
            return false;
        }

        return mb_strlen(trim($match[1])) >= 20;
    }

    /**
     * متنِ فایل، بدونِ کامنت.
     *
     * ⚠️ اجباری است: توضیحِ همین مهاجرت‌ها پر از جمله‌هایی مثل «این جدول
     * حذف شد» و نامِ متدهاست. دو الگوی جدا لازم است — یکی‌کردنشان با پرچمِ
     * `s` باعث می‌شود `//.*` تا آخرِ فایل را ببلعد.
     *
     * ⚠️ ولی `@destructive` عمداً از متنِ **کامنت‌دار** خوانده می‌شود، چون
     * خودش یک کامنت است.
     */
    private static function sourceWithoutComments(string $file): string
    {
        $code = (string) file_get_contents($file);
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);

        return (string) preg_replace('#^\s*//.*$#m', '', $code);
    }

    /** بدنه‌ی `up()` — یا رشته‌ی تهی اگر پیدا نشود. */
    private static function upBody(string $code): string
    {
        if (preg_match('/public function up\(\): void\s*\{(.*?)\n    \}/s', $code, $match) !== 1) {
            return '';
        }

        return $match[1];
    }
}
