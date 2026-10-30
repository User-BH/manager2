<?php

namespace Tests;

use App\Services\Auth\PasswordConfirmation;
use App\Support\SystemSettings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

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

    /**
     * ورودِ کاربرِ سایت از همان مسیرِ واقعی (۲۰۲۶-۱۰-۳۰): شماره ← کدِ پیامکی ← ورود. درایورِ `log` در محیطِ آزمون کد را
     * در پاسخِ گامِ اول برمی‌گرداند؛ کدِ دیگری جز کدِ واقعیِ همان درخواست به کار نمی‌رود.
     */
    protected function signInByPhone(string $phone): TestResponse
    {
        $code = $this->postJson('/api/v1/login', ['phone' => $phone])->assertOk()->json('dev_code');
        $this->assertNotNull($code, "برای {$phone} کدی فرستاده نشد.");

        return $this->postJson('/api/v1/login/verify', ['code' => (string) $code]);
    }
}
