<?php

namespace Tests\Unit;

use App\Models\Complex;
use App\Services\Charge\PenaltyCalculator;
use PHPUnit\Framework\TestCase;

/**
 * جریمه‌ی دیرکرد (R68).
 *
 * ─── 🔴 نقصی که اندازه‌گیریِ R68 پیدا کرد ───────────────────────────────────
 * این کلاس **پولی را حساب می‌کند که به قبضِ ساکن اضافه می‌شود** و تا امروز
 * هیچ تستی نداشت. تنها فراخوانی‌اش در `BillGenerator` بود، یعنی رفتارش فقط
 * به‌طورِ غیرمستقیم و در کنارِ ده چیزِ دیگر سنجیده می‌شد.
 *
 * ⚠️ خودِ کلاس عمداً خالص است (بدونِ دیتابیس) و داکبلاکش می‌گوید «trivial to
 * unit test» — ولی آن تست هرگز نوشته نشده بود. همان الگوی آشنای این پروژه:
 * چیزی که **می‌شد** سنجید با چیزی که **سنجیده شده** یکی نیست.
 *
 * ─── چرا `PHPUnit\TestCase` و نه `Tests\TestCase` ──────────────────────────
 * این تست به دیتابیس، مسیر و کانتینر نیازی ندارد. بوت‌کردنِ کلِ لاراول
 * برایش یعنی چند صد میلی‌ثانیه هزینه برای هیچ — و همان چیزی است که هرمِ
 * تستِ این پروژه را وارونه کرده (`docs/TESTING.md`).
 */
class PenaltyCalculatorTest extends TestCase
{
    private PenaltyCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PenaltyCalculator;
    }

    /** مجتمعی که جریمه‌اش خاموش است، هرگز جریمه نمی‌گیرد. */
    public function test_a_complex_with_penalties_off_never_charges(): void
    {
        $complex = $this->complex(['penalty_enabled' => false, 'penalty_value' => 50]);

        $this->assertSame(0.0, $this->calculator->compute(1_000_000, 100, $complex));
    }

    /**
     * ⚠️ بدهیِ صفر یا منفی جریمه ندارد.
     *
     * بدونِ این محافظ، واحدی که بستانکار است (بدهیِ منفی) با نوعِ درصدی
     * جریمه‌ی **منفی** می‌گرفت — یعنی قبضش بی‌دلیل کم می‌شد.
     */
    public function test_no_penalty_without_an_overdue_amount(): void
    {
        $complex = $this->complex(['penalty_type' => 'percent', 'penalty_value' => 10]);

        $this->assertSame(0.0, $this->calculator->compute(0, 30, $complex));
        $this->assertSame(0.0, $this->calculator->compute(-500_000, 30, $complex));
    }

    /** مهلتِ ارفاق واقعاً ارفاق است: تا پایانش جریمه‌ای نیست. */
    public function test_the_grace_period_is_honoured(): void
    {
        $complex = $this->complex([
            'penalty_type' => 'fixed',
            'penalty_value' => 50_000,
            'penalty_grace_days' => 5,
        ]);

        $this->assertSame(0.0, $this->calculator->compute(1_000_000, 5, $complex), 'روزِ پنجم هنوز داخلِ ارفاق است.');
        $this->assertSame(50_000.0, $this->calculator->compute(1_000_000, 6, $complex));
    }

    /** جریمه‌ی مقطوع به مبلغِ بدهی و تعدادِ روز کاری ندارد. */
    public function test_a_fixed_penalty_ignores_amount_and_days(): void
    {
        $complex = $this->complex(['penalty_type' => 'fixed', 'penalty_value' => 30_000]);

        $this->assertSame(30_000.0, $this->calculator->compute(1_000_000, 1, $complex));
        $this->assertSame(30_000.0, $this->calculator->compute(9_000_000, 90, $complex));
    }

    /** درصدی: یک بار روی بدهی، مستقل از تعدادِ روز. */
    public function test_a_percentage_penalty_is_charged_once(): void
    {
        $complex = $this->complex(['penalty_type' => 'percent', 'penalty_value' => 10]);

        $this->assertSame(100_000.0, $this->calculator->compute(1_000_000, 1, $complex));
        $this->assertSame(100_000.0, $this->calculator->compute(1_000_000, 60, $complex));
    }

    /**
     * 🔴 پرخطرترین نوع: درصدِ روزانه.
     *
     * ⚠️ ۱٪ در روز یعنی پس از صد روز، جریمه به **اندازه‌ی کلِ بدهی**
     * می‌رسد. این عدد سقفی ندارد و باید صریح سنجیده شود تا کسی بعداً
     * فکر نکند جایی محدود می‌شود.
     */
    public function test_a_daily_percentage_compounds_with_the_days(): void
    {
        $complex = $this->complex(['penalty_type' => 'percent_per_day', 'penalty_value' => 1]);

        $this->assertSame(10_000.0, $this->calculator->compute(1_000_000, 1, $complex));
        $this->assertSame(300_000.0, $this->calculator->compute(1_000_000, 30, $complex));
        $this->assertSame(1_000_000.0, $this->calculator->compute(1_000_000, 100, $complex));
    }

    /** ⚠️ و ارفاق از روزهای درصدِ روزانه هم کم می‌شود، نه فقط از شروعش. */
    public function test_the_grace_days_are_subtracted_from_the_daily_count(): void
    {
        $complex = $this->complex([
            'penalty_type' => 'percent_per_day',
            'penalty_value' => 1,
            'penalty_grace_days' => 10,
        ]);

        // ۳۰ روز دیرکرد منهای ۱۰ روز ارفاق = ۲۰ روزِ جریمه‌پذیر
        $this->assertSame(200_000.0, $this->calculator->compute(1_000_000, 30, $complex));
    }

    /**
     * ⚠️ نوعِ ناشناخته صفر می‌دهد، نه استثنا.
     *
     * ستونِ `penalty_type` رشته است و می‌تواند با اصلاحِ دستیِ دیتابیس
     * مقدارِ بی‌ربط بگیرد. صفر‌دادن یعنی قبض ساخته می‌شود؛ استثنا یعنی
     * صدورِ قبضِ کلِ مجتمع متوقف می‌شود.
     */
    public function test_an_unknown_penalty_type_is_treated_as_no_penalty(): void
    {
        $complex = $this->complex(['penalty_type' => 'something-else', 'penalty_value' => 99]);

        $this->assertSame(0.0, $this->calculator->compute(1_000_000, 30, $complex));
    }

    /** خروجی گرد می‌شود: ریالِ اعشاری روی قبض معنی ندارد. */
    public function test_the_result_is_rounded_to_whole_currency(): void
    {
        $complex = $this->complex(['penalty_type' => 'percent', 'penalty_value' => 3]);

        // ۳٪ از ۳۳٬۳۳۳ = ۹۹۹٫۹۹
        $this->assertSame(1_000.0, $this->calculator->compute(33_333, 10, $complex));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function complex(array $attributes): Complex
    {
        /*
         * ⚠️ مدل ساخته می‌شود ولی **ذخیره نمی‌شود**.
         *
         * `PenaltyCalculator` فقط چند ستون را می‌خواند و به دیتابیس دست
         * نمی‌زند؛ ذخیره‌کردن یعنی این تست به مهاجرت‌ها و اتصال گره بخورد
         * بی‌آنکه چیزی به ادعایش اضافه شود.
         */
        return new Complex(array_merge([
            'penalty_enabled' => true,
            'penalty_type' => 'fixed',
            'penalty_value' => 0,
            'penalty_grace_days' => 0,
        ], $attributes));
    }
}
