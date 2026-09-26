<?php

namespace Tests;

use App\Services\Auth\PasswordConfirmation;
use App\Support\SystemSettings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * پاک‌کردنِ حافظه‌ی ایستا بینِ تست‌ها.
         *
         * `SystemSettings` از R13 تنظیمات را در یک ویژگیِ `static` نگه می‌دارد
         * تا در هر درخواست فقط یک کوئری بزند. ویژگیِ ایستا با ساختِ دوباره‌ی
         * اپلیکیشن پاک **نمی‌شود**، پس بدونِ این خط تنظیماتِ یک تست به تستِ
         * بعدی نشت می‌کرد — و بدتر، نتیجه به ترتیبِ اجرای تست‌ها وابسته می‌شد.
         */
        SystemSettings::forget();
    }

    /**
     * رمز «همین حالا» تأیید شده است (مرحلهٔ ۵ · فاز ۱۰).
     *
     * ⚠️ فقط برای تست‌هایی که موضوعشان **خودِ** آن عملیات است (بازیابی،
     * پرچم، مدیر) و نه نگهبانِ تأییدِ دوباره. خودِ نگهبان در
     * `ReauthenticationTest` بدونِ این سنجیده می‌شود.
     */
    protected function withConfirmedPassword(): static
    {
        return $this->withSession([PasswordConfirmation::KEY => now()->timestamp]);
    }
}
