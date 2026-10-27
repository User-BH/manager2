<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Morilog\Jalali\CalendarUtils;
use Tests\TestCase;

/**
 * فهرستِ مرجعِ تبدیلِ میلادی ↔ شمسی که `morilog/jalali` ِ سرور می‌سازد (مرحلهٔ ۱۳ · فاز ۷؛ ایرادات ۱۰، بندهای ۷۳ و ۱۰۱).
 *
 * الگوریتمِ رابط (`resources/js/shared/lib/jalali.ts`) با همین فایل سنجیده می‌شود (`tests/js/jalali.test.ts`)؛ این آزمون
 * می‌سنجد که فایل همان است که سرور امروز می‌سازد — پس سرور و رابط هیچ‌گاه روزی را متفاوت نمی‌شمارند. ساختنِ دوباره:
 * `UPDATE_JALALI_ANCHORS=1 php artisan test --filter JalaliAnchorsTest`.
 */
class JalaliAnchorsTest extends TestCase
{
    private const FILE = 'tests/fixtures/jalali-anchors.json';

    /** @return array{days: list<array{0: string, 1: string}>, leapYears: list<int>} */
    private function generate(): array
    {
        $days = [];
        // هر پنج روز از ۱۹۸۰ تا ۲۰۴۵ (گذشته و آینده‌ی دور، بندِ ۷۴)
        for ($d = CarbonImmutable::parse('1980-01-01', 'UTC'); $d->lte(CarbonImmutable::parse('2045-12-31', 'UTC')); $d = $d->addDays(5)) {
            $days[] = $this->pair($d);
        }
        // مرزِ هر سال: روزِ پیش از نوروز (۲۹ یا ۳۰ اسفند) و نوروز
        for ($jy = 1360; $jy <= 1424; $jy++) {
            [$gy, $gm, $gd] = CalendarUtils::toGregorian($jy, 1, 1);
            $nowruz = CarbonImmutable::create($gy, $gm, $gd, 0, 0, 0, 'UTC');
            $days[] = $this->pair($nowruz->subDay());
            $days[] = $this->pair($nowruz);
        }

        $leapYears = [];
        for ($jy = 1300; $jy <= 1500; $jy++) {
            if (CalendarUtils::isLeapJalaliYear($jy)) {
                $leapYears[] = $jy;
            }
        }

        return ['days' => $days, 'leapYears' => $leapYears];
    }

    /** @return array{0: string, 1: string} میلادی «Y-m-d» ← شمسی «Y-m-d» */
    private function pair(CarbonImmutable $day): array
    {
        [$jy, $jm, $jd] = CalendarUtils::toJalali((int) $day->year, (int) $day->month, (int) $day->day);

        return [$day->toDateString(), sprintf('%04d-%02d-%02d', $jy, $jm, $jd)];
    }

    public function test_the_fixture_is_what_the_server_calendar_says_today(): void
    {
        $expected = $this->generate();

        if (getenv('UPDATE_JALALI_ANCHORS')) {
            // یک جفت در هر خط: کوچک و diff ِ خوانا
            $lines = array_map(fn (array $pair) => '  '.json_encode($pair), $expected['days']);
            file_put_contents(
                base_path(self::FILE),
                "{\"days\": [\n".implode(",\n", $lines)."\n],\n\"leapYears\": ".json_encode($expected['leapYears'])."}\n",
            );
        }

        $actual = json_decode((string) file_get_contents(base_path(self::FILE)), true);
        $this->assertSame($expected, $actual, 'فهرستِ مرجعِ تقویم با `morilog/jalali` نمی‌خواند؛ دوباره بسازیدش.');
        $this->assertGreaterThan(4800, count($actual['days']));
    }

    /** چند لنگرِ شناخته‌شده‌ی مستقل از هر دو پیاده‌سازی */
    public function test_known_anchors(): void
    {
        $this->assertSame([1404, 1, 1], CalendarUtils::toJalali(2025, 3, 21));
        $this->assertSame([1403, 12, 30], CalendarUtils::toJalali(2025, 3, 20));
        $this->assertSame([1405, 8, 5], CalendarUtils::toJalali(2026, 10, 27));
        $this->assertTrue(CalendarUtils::isLeapJalaliYear(1403));
        $this->assertFalse(CalendarUtils::isLeapJalaliYear(1404));
    }
}
