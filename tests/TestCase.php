<?php

namespace Tests;

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
}
