<?php

namespace App\Services\Account;

use App\Models\Complex;
use Illuminate\Support\Facades\DB;

/**
 * ورودِ عمومی به مجتمع با QR (مرحلهٔ ۱۰ · فاز ۵؛ بندهای ۲۲، ۲۳ و ۲۵ ِ فایلِ معماری).
 *
 *   QR ← صفحه‌ی پیوستن ← ثبت‌نام/ورود ← درخواستِ عضویت ← بررسیِ مدیر ← تأیید ← عضویتِ فعال
 *
 * «اسکنِ QR به‌تنهایی عضویت نیست»: کد فقط مجتمع را **نام می‌برد**. با آن می‌شود نامِ مجتمع را دید و
 * درخواست فرستاد — همان درخواستی که با شماره‌ی مدیر هم ساخته می‌شود و تا تأییدِ مدیر هیچ عضویتی نمی‌سازد.
 *
 * جدا از دعوتِ مستقیم (`Membership::invite`): دعوت به **شماره** بسته، یک‌بارمصرف، هفت‌روزه و هش‌شده است
 * و پذیرفتنش بی تأییدِ دوباره عضو می‌کند. این کد به هیچ شخصی بسته نیست، پس هرگز بی تأیید عضو نمی‌کند.
 */
final class PublicJoinLink
{
    /** ۱۶ بایت تصادفی در base64url بی‌پرکننده */
    public const LENGTH = 22;

    public const PATTERN = '/^[A-Za-z0-9_-]{22}$/';

    public static function url(string $code): string
    {
        // پس از `#`، مثلِ پیوندِ دعوت: نه در لاگِ دسترسی، نه در Referer، نه در آمارِ بازدید
        return url('/join').'#'.$code;
    }

    /**
     * کدِ تازه؛ کدِ قبلی همین لحظه از کار می‌افتد (QR ِ چاپ‌شده‌ی قدیمی «نامعتبر» می‌گوید).
     *
     * ⚠️ زیرِ قفلِ ردیفِ مجتمع: دو کلیکِ هم‌زمانِ «QR ِ تازه» یک کد به جا می‌گذارند، نه دو کدی که
     * مدیر یکی‌اش را دیده و دیگری در پایگاه‌داده است.
     */
    public function rotate(Complex $complex): string
    {
        return DB::transaction(function () use ($complex): string {
            $locked = Complex::lockForUpdate()->findOrFail($complex->id);
            $code = self::generate();

            $locked->forceFill(['join_code' => $code, 'join_code_created_at' => now()])->save();
            $complex->setRawAttributes($locked->getAttributes(), true);

            return $code;
        }, attempts: 3);
    }

    /** کدِ فعلی را نگه می‌دارد؛ فقط اگر نیست می‌سازد — برای «نمایشِ QR» که نباید QR ِ چاپ‌شده را باطل کند. */
    public function ensure(Complex $complex): string
    {
        return DB::transaction(function () use ($complex): string {
            $locked = Complex::lockForUpdate()->findOrFail($complex->id);

            if ($locked->join_code === null) {
                $locked->forceFill(['join_code' => self::generate(), 'join_code_created_at' => now()])->save();
            }
            $complex->setRawAttributes($locked->getAttributes(), true);

            return (string) $locked->join_code;
        }, attempts: 3);
    }

    /** خاموش‌کردنِ ورودِ عمومی؛ درخواست‌های در انتظار سرِ جایشان می‌مانند و مدیر هنوز تصمیم می‌گیرد. */
    public function disable(Complex $complex): void
    {
        $complex->forceFill(['join_code' => null, 'join_code_created_at' => null])->save();
    }

    /**
     * مجتمعِ این کد — یا null برای کدِ بدشکل، باطل‌شده یا مجتمعِ تعلیق‌شده.
     *
     * مجتمعِ تعلیق‌شده عضوِ تازه نمی‌پذیرد: مدیرش هم تا رفعِ تعلیق به صندوقِ درخواست‌ها دسترسی ندارد.
     */
    public function resolve(string $code): ?Complex
    {
        if (! preg_match(self::PATTERN, $code)) {
            return null;
        }

        $complex = Complex::query()
            ->where('join_code', $code)
            ->where('is_active', true)
            ->first();

        // ⚠️ هم‌ترازیِ پیش‌فرضِ MySQL حروفِ بزرگ و کوچک را یکی می‌گیرد؛ base64url نه
        return $complex !== null && hash_equals((string) $complex->join_code, $code) ? $complex : null;
    }

    private static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}
