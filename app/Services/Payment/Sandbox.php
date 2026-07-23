<?php

namespace App\Services\Payment;

use RuntimeException;

/**
 * نگهبان درگاه ساختگی.
 *
 * `FakeGateway` هر بازگشتی را تایید می‌کند، پس روی سرور واقعی برابر است با
 * تسویه‌ی قبض بدون پرداخت پول. این کلاس تنها جایی است که تصمیم می‌گیرد
 * سندباکس مجاز است یا نه، تا هر دو مسیرِ درگاه (شارژ مجتمع و اشتراک) یک
 * پاسخ بدهند و اضافه شدن مسیر سوم هم همین‌جا وصل شود.
 */
class Sandbox
{
    public static function isAllowed(): bool
    {
        return (bool) config('payment.sandbox_enabled', false);
    }

    /**
     * درگاه ساختگی، یا خطا اگر روی این محیط مجاز نباشد.
     *
     * استثنا پرتاب می‌شود و نه بازگشت خاموش، چون اگر بی‌صدا رد شود کاربر
     * وسط پرداخت گیر می‌کند بی‌آنکه بفهمد چرا.
     */
    public static function gateway(): FakeGateway
    {
        if (! self::isAllowed()) {
            throw new RuntimeException(
                'درگاه آزمایشی روی این سرور غیرفعال است. '
                .'از «واریز و آپلود رسید» استفاده کنید یا درگاه بانکی واقعی را در تنظیمات وارد کنید.'
            );
        }

        return new FakeGateway;
    }
}
