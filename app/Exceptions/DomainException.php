<?php

namespace App\Exceptions;

use Exception;

/**
 * نقضِ یک قاعده‌ی کسب‌وکار — نه خطای فنی، نه نبودِ دسترسی.
 *
 * ─── چرا این کلاس لازم بود ─────────────────────────────────────────────────
 * پیش از این، قواعدِ کسب‌وکار با `abort_if(..., 422, 'پیام')` وسطِ کنترلرها
 * نوشته می‌شدند. سه مشکل داشت:
 *
 *   ۱. با مجوزدهی قاطی بود. `abort_unless(... , 403)` و
 *      `abort_if(..., 422)` کنارِ هم می‌نشستند، در حالی که یکی «اجازه نداری»
 *      است و دیگری «الان نمی‌شود». تفکیکشان برای کاربر و برای کد مهم است.
 *   ۲. قابلِ آزمودن نبود مگر از راهِ HTTP؛ منطق به لایه‌ی کنترلر چسبیده بود.
 *   ۳. کدِ ماشین‌خوان نداشت، پس فرانت فقط می‌توانست رشته‌ی فارسی را نمایش دهد
 *      و نمی‌توانست رفتارِ متفاوتی برای حالت‌های متفاوت داشته باشد.
 *
 * حالا سرویس‌ها این را پرتاب می‌کنند و `Handler` مرکزی شکلِ پاسخ را می‌سازد.
 *
 * @example
 * throw DomainException::conflict('برای این قبض یک رسید در انتظار بررسی دارید.', 'payment.pending_exists');
 */
class DomainException extends Exception
{
    /**
     * `$errorCode` عمداً `code` نام ندارد: خودِ `Exception` یک `$code`ِ
     * غیرreadonly دارد و بازتعریفش خطای کشنده‌ی PHP می‌دهد
     * («Cannot redeclare non-readonly property as readonly»). در پاسخِ JSON
     * همچنان با کلیدِ `code` بیرون می‌رود.
     *
     * @param  string  $message  پیامِ فارسیِ قابلِ نمایش به کاربر
     * @param  string|null  $errorCode  شناسه‌ی ماشین‌خوان، برای رفتارِ خاصِ فرانت
     * @param  int  $status  کدِ HTTP
     * @param  array<string, string[]>  $errors  خطای فیلدها، اگر مربوط به فرم باشد
     */
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly int $status = 422,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /** درخواست با وضعیتِ فعلیِ داده جور درنمی‌آید (۴۲۲). */
    public static function invalid(string $message, ?string $errorCode = null, array $errors = []): self
    {
        return new self($message, $errorCode, 422, $errors);
    }

    /**
     * تعارض با وضعیتِ فعلیِ سیستم (۴۰۹).
     *
     * فرقش با ۴۲۲: ورودیِ کاربر درست است، ولی الان زمانش نیست — مثلاً رسیدی
     * که هنوز بررسی نشده.
     */
    public static function conflict(string $message, ?string $errorCode = null): self
    {
        return new self($message, $errorCode, 409);
    }

    /** پیش‌نیازی فراهم نیست (مثلاً مجتمعی انتخاب نشده). */
    public static function precondition(string $message, ?string $errorCode = null): self
    {
        return new self($message, $errorCode, 409);
    }
}
