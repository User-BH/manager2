<?php

namespace Tests\Feature;

use App\Support\Jalali;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * تاریخ و ساعتِ شمسی به وقتِ تهران (مرحلهٔ ۵ · فاز ۶ · بندِ ۵۱: Timezone).
 *
 * 🔴 نقص: تصمیمِ مکتوب درست بود — ذخیره UTC، تبدیل هنگامِ نمایش — ولی
 * `Jalali` منطقه‌ی زمانی را هرگز تبدیل نمی‌کرد. هر ساعتی که سرور نشان
 * می‌داد سه ساعت و نیم عقب بود، و در سه ساعت و نیمِ اولِ هر ماه، گزارش و
 * نمودار و «صدورِ شارژِ این ماه» ماهِ قبل را می‌دیدند.
 */
class JalaliTimezoneTest extends TestCase
{
    /** نیمه‌شب و نیمِ اولِ مهرِ ۱۴۰۵ به وقتِ تهران = ۲۱:۰۰ِ ۲۲ سپتامبر به UTC. */
    private function firstMinutesOfMehr(): Carbon
    {
        return Carbon::parse('2026-09-22 21:00:00', 'UTC');
    }

    public function test_a_moment_is_shown_in_tehran_time(): void
    {
        $this->assertSame('۱۴۰۵/۰۷/۰۱ ۰۰:۳۰', Jalali::dateTime($this->firstMinutesOfMehr()));
    }

    public function test_a_moment_belongs_to_the_tehran_month(): void
    {
        $this->assertSame('1405-07', Jalali::period($this->firstMinutesOfMehr()));
    }

    public function test_the_current_period_turns_at_tehran_midnight(): void
    {
        $this->travelTo($this->firstMinutesOfMehr());
        $this->assertSame('1405-07', Jalali::currentPeriod());

        // یک دقیقه پیش از نیمه‌شبِ تهران هنوز شهریور است
        $this->travelTo(Carbon::parse('2026-09-22 20:29:00', 'UTC'));
        $this->assertSame('1405-06', Jalali::currentPeriod());
    }

    /** ستونِ `date` (نیمه‌شبِ UTC) با تبدیل جابه‌جا نمی‌شود: ۳:۳۰ِ همان روز. */
    public function test_a_date_only_value_keeps_its_day(): void
    {
        $this->assertSame('۱۴۰۵/۰۷/۰۱', Jalali::date(Carbon::parse('2026-09-23')));
    }

    /** ⚠️ تبدیل روی نسخه‌ی کپی است؛ شیءِ ورودی (مثلاً ستونِ مدل) UTC می‌ماند. */
    public function test_the_input_is_not_mutated(): void
    {
        $moment = $this->firstMinutesOfMehr();

        Jalali::dateTime($moment);

        $this->assertSame('UTC', $moment->timezoneName);
        $this->assertSame('21:00', $moment->format('H:i'));
    }

    /** ذخیره همچنان UTC است؛ فقط نمایش عوض شد. */
    public function test_storage_stays_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Asia/Tehran', config('app.display_timezone'));
    }
}
