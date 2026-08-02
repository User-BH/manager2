<?php

namespace App\Services\Backup;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * رمزگذاری و بازکردنِ فایلِ نسخه‌ی پشتیبان (R20).
 *
 * ─── چرا لازم شد ───────────────────────────────────────────────────────────
 * فایلِ بکاپِ سیستم متنِ ساده‌ی JSON بود و در ممیزی معلوم شد که **همه‌ی
 * هشِ رمزهای سامانه**، **رمزِ درگاه بانکی** و **کلیدِ API پیامک** را در خود
 * دارد. یک فایلِ بکاپ که از سرور بیرون برود، عملاً کلِ سامانه است.
 *
 * جالب اینکه بکاپِ سطحِ مجتمع `makeHidden('password')` داشت و کامنتش هم
 * می‌گفت «رمزها هرگز داخل فایل بکاپ نمی‌روند» — ولی بکاپِ سیستم با
 * `DB::table()` ساخته می‌شد که `$hidden` را دور می‌زند.
 *
 * ─── قالب ──────────────────────────────────────────────────────────────────
 * پوششِ بیرونی عمداً JSONِ ساده مانده تا فایل همچنان `.json` معتبر باشد و
 * قواعدِ آپلود دست‌نخورده بمانند:
 *
 *     {"format":"sakena-backup","version":2,"encrypted":true,"payload":"<...>"}
 *
 * ⚠️ **وابستگی به `APP_KEY`.** بکاپِ رمزشده بدونِ همان کلید باز نمی‌شود. اگر
 * `APP_KEY` عوض شود، بکاپ‌های قبلی غیرقابل‌استفاده‌اند. این هزینه آگاهانه
 * پذیرفته شد: بکاپی که هرکس بتواند بازش کند، خودش یک نشتِ کامل است.
 */
class BackupCipher
{
    public const FORMAT = 'sakena-backup';

    public const VERSION = 2;

    /** @param  array<string, mixed>  $snapshot */
    public static function seal(array $snapshot): string
    {
        return (string) json_encode([
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'encrypted' => true,
            'generated_at' => now()->toIso8601String(),
            'payload' => Crypt::encryptString(
                (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            ),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * بازکردنِ فایل — چه رمزشده باشد چه نسخه‌ی قدیمیِ ساده.
     *
     * سازگاریِ رو به عقب عمدی است: نصب‌هایی که از قبل بکاپ گرفته‌اند نباید
     * ناگهان ببینند هیچ‌کدام از فایل‌های قدیمی‌شان قابل بازیابی نیست — آن هم
     * دقیقاً در روزی که به بکاپ نیاز دارند.
     *
     * @return array<string, mixed>|null در صورت خرابیِ ساختار
     */
    public static function open(string $contents): ?array
    {
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return null;
        }

        // نسخه‌ی قدیمی: خودِ snapshot، بدونِ پوشش
        if (($decoded['format'] ?? null) !== self::FORMAT) {
            return $decoded;
        }

        if (! ($decoded['encrypted'] ?? false) || ! is_string($decoded['payload'] ?? null)) {
            return null;
        }

        try {
            $plain = Crypt::decryptString($decoded['payload']);
        } catch (DecryptException) {
            /*
             * تقریباً همیشه یعنی «کلیدِ برنامه با زمانِ ساختِ بکاپ فرق دارد».
             * پیامِ صریح لازم است، وگرنه ادمین فکر می‌کند فایل خراب است و
             * دنبالِ مشکلِ اشتباهی می‌گردد.
             */
            throw new RuntimeException(
                'فایل بکاپ با کلید دیگری رمزگذاری شده است. '
                .'برای بازیابی باید همان APP_KEY زمان ساخت بکاپ تنظیم باشد.',
            );
        }

        $snapshot = json_decode($plain, true);

        return is_array($snapshot) ? $snapshot : null;
    }
}
