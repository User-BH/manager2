<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReadsAuditMap;
use Tests\TestCase;

/**
 * گزارشِ مرجعِ نهاییِ مرحلهٔ چهارم با واقعیت می‌خواند.
 *
 * ─── 🔴 چرا این فایل وجود دارد ─────────────────────────────────────────────
 * این سند «مرجع» نامیده شده، و هر سندی که چنین نامی بگیرد دقیقاً به همان
 * دلیل خطرناک می‌شود: همه به آن استناد می‌کنند و کسی دوباره نمی‌خواندش.
 *
 * ⚠️ در فاز ۹ همین اتفاق برای `CANONICAL_INVENTORY` افتاده بود — جدولِ
 * پایانی‌اش هشت فاز کهنه مانده بود، در حالی که خودش می‌گفت «هرجا سندی
 * متناقض گفت، من درستم».
 *
 * ─── مرزِ صریح ─────────────────────────────────────────────────────────────
 * 🔴 این تست **درستیِ ادعاها را ثابت نمی‌کند**. کاری که می‌کند این است که
 * نگذارد ساختار از دست برود، شمارش با سیاههٔ واقعی جدا بیفتد، یا حکمی
 * صادر شود که با وضعیتِ ثبت‌شدهٔ خودِ سند نمی‌خواند.
 */
class ConsolidatedAuditTest extends TestCase
{
    use ReadsAuditMap;

    private const DOC = 'docs/STAGE4_FINAL_CONSOLIDATED_AUDIT.md';

    /** ⚠️ سه حکمِ مجاز، و بس. «تقریباً آماده» حکم نیست. */
    private const VERDICTS = ['CODE READY', 'ENVIRONMENT VERIFIED', 'NOT READY'];

    /**
     * بیست‌وپنج بخشی که خواسته شده بود.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function sections(): array
    {
        $titles = [
            'خلاصهٔ مدیریتی', 'وضعیتِ نهایی — شمارشِ مرجع',
            'یافته‌های Critical و High', 'یکپارچگیِ مالی',
            'چندمستأجری و مجوزها', 'نوعِ مجتمع', 'احراز هویت و نشست',
            'رازها', 'PWA و Service Worker', 'قراردادِ API',
            'صف و کارهای پس‌زمینه', 'امنیتِ آپلود', 'SSRF',
            'مشاهده‌پذیری', 'تست', 'سفرِ مالیِ سرتاسری',
            'سازگاریِ مرورگر', 'کارایی', 'استقرار',
            'بکاپ، بازیابی و بازیابی از فاجعه', 'راستی‌آزماییِ محیطی',
            'آنچه عمداً ساخته نشد', 'یافته‌های تاریخی — رفع‌شده',
            'شکاف‌های باقی‌مانده', 'تصمیمِ نهاییِ انتشار',
        ];

        $cases = [];

        foreach ($titles as $index => $title) {
            $cases[$title] = [$index + 1, $title];
        }

        return $cases;
    }

    #[DataProvider('sections')]
    public function test_every_required_section_is_present(int $number, string $title): void
    {
        $this->assertStringContainsString(
            '## '.$this->persianNumber($number).') '.$title,
            $this->readDoc(self::DOC),
            "بخشِ {$number} («{$title}») در گزارشِ مرجع نیست.",
        );
    }

    public function test_the_document_has_exactly_the_twenty_five_sections(): void
    {
        preg_match_all('/^## ([۰-۹]+)\) /mu', $this->readDoc(self::DOC), $matches);

        $this->assertCount(25, $matches[1], '⚠️ شمارِ بخش‌های شماره‌دار ۲۵ نیست.');
    }

    /**
     * 🔴 شمارشِ قطعی باید با سیاههٔ واقعی بخواند.
     *
     * ─── چرا این‌طور و نه سنجشِ وجودِ جدول ──────────────────────────────────
     * ⚠️ محافظی که فقط بپرسد «جدولی هست؟» پوچ است. اینجا سهمِ نقشهٔ مرحلهٔ
     * دوم **از نو از ردیف‌ها** شمرده می‌شود، و جمعِ سطرِ آن با آنچه در سند
     * نوشته شده مقایسه می‌گردد.
     */
    public function test_the_map_share_of_the_count_is_recomputed(): void
    {
        $doc = $this->readDoc(self::DOC);
        $actual = $this->tallyByLabel();

        $row = $this->breakdownRow($doc);

        foreach ([
            0 => 'VERIFIED',
            1 => 'IMPLEMENTATION READY — ENVIRONMENT VERIFICATION REQUIRED',
            2 => 'INTENTIONALLY NOT BUILT',
        ] as $column => $label) {
            $this->assertSame(
                $actual[$label] ?? 0,
                $row[$column],
                "سهمِ نقشهٔ مرحلهٔ دوم برای «{$label}» با خودِ نقشه نمی‌خواند.",
            );
        }

        $this->assertSame(
            array_sum($actual),
            array_sum($row),
            'جمعِ سطرِ نقشه با شمارشِ واقعیِ ردیف‌ها نمی‌خواند.',
        );
    }

    /**
     * ⚠️ جمعِ ستون‌ها باید با سطرِ «جمع» بخواند.
     *
     * 🔴 بدونِ این، می‌شد یک برچسب را دستی بزرگ‌تر نوشت و هیچ‌چیز قرمز
     * نمی‌شد — همان کاری که جدولِ جمع‌بندیِ `STAGE2_AUDIT_MAP` سال‌ها
     * می‌کرد.
     */
    public function test_the_headline_table_adds_up(): void
    {
        $doc = $this->readDoc(self::DOC);

        $labels = [
            'VERIFIED', 'IMPLEMENTATION READY — ENVIRONMENT VERIFICATION REQUIRED',
            'INTENTIONALLY NOT BUILT', 'BLOCKED BY EXTERNAL ENVIRONMENT',
            'OPEN', 'HISTORICAL FINDING RESOLVED', 'قاعدهٔ اجرا',
        ];

        $sum = 0;

        foreach ($labels as $label) {
            $sum += $this->claimedNumberIn($doc, $label);
        }

        $this->assertSame(
            $this->claimedNumberIn($doc, 'جمع'),
            $sum,
            "جمعِ نوشته‌شده با جمعِ ستون‌ها نمی‌خواند (ستون‌ها: {$sum}).",
        );
    }

    /** 🔴 دقیقاً یک حکم، و از فهرستِ مجاز. */
    public function test_the_verdict_is_exactly_one_of_the_three(): void
    {
        $this->assertSame(
            1,
            preg_match('/^> # `([^`]+)`$/mu', $this->readDoc(self::DOC), $match),
            'حکمِ نهایی به‌شکلِ نقلِ‌قولِ برجسته در سند نیست.',
        );

        $this->assertContains($match[1], self::VERDICTS, "حکمِ «{$match[1]}» مجاز نیست.");
    }

    /**
     * 🔴 حکم با وضعیتِ ثبت‌شدهٔ خودِ سند بخواند.
     *
     * ⚠️ اگر حتی یک موردِ `OPEN` با شدتِ Critical یا High ثبت شده باشد،
     * `CODE READY` دروغ است.
     */
    public function test_the_verdict_matches_what_the_document_itself_records(): void
    {
        $doc = $this->readDoc(self::DOC);

        preg_match('/^> # `([^`]+)`$/mu', $doc, $match);
        $verdict = $match[1] ?? '';

        $gaps = strstr($doc, '### `OPEN` — واقعاً هنوز نقص دارند');
        $this->assertIsString($gaps, 'بخشِ شکاف‌های باز پیدا نشد؛ محافظ پوچ می‌شود.');

        $gaps = strstr((string) $gaps, '### `BLOCKED', true) ?: (string) $gaps;

        $severe = preg_match('/\*\*(Critical|High)\*\*/u', $gaps) === 1;

        if ($severe) {
            $this->assertSame(
                'NOT READY',
                $verdict,
                '🔴 موردِ بازِ Critical/High ثبت شده ولی حکم `NOT READY` نیست.',
            );

            return;
        }

        $this->assertNotSame(
            'NOT READY',
            $verdict,
            'حکم `NOT READY` است ولی هیچ موردِ بازِ Critical/Highی ثبت نشده.',
        );
    }

    /**
     * 🔴 `ENVIRONMENT VERIFIED` فقط وقتی که جدول هم همان را بگوید.
     *
     * ─── این را خرابکاری خواست ─────────────────────────────────────────────
     * ⚠️ نسخهٔ اولِ این محافظ فقط می‌سنجید که حکم با موردِ **بازِ**
     * Critical/High نخواند. یعنی ارتقای حکم از `CODE READY` به
     * `ENVIRONMENT VERIFIED` — **خطرناک‌ترین ادعای ممکن در این سند** —
     * بی‌صدا از تور رد می‌شد.
     *
     * 🔴 و خطرناک‌ترین است چون درست همان چیزی را ادعا می‌کند که هیچ‌کس
     * نسنجیده: اینکه استقرار و بازیابی و درگاه روی محیطِ واقعی اجرا شده‌اند.
     */
    public function test_the_environment_verdict_needs_the_table_to_agree(): void
    {
        $doc = $this->readDoc(self::DOC);

        preg_match('/^> # `([^`]+)`$/mu', $doc, $match);
        $verdict = $match[1] ?? '';

        $verified = (bool) preg_match(
            '/\*\*ENVIRONMENT VERIFIED\*\*\s*\|\s*✅/u',
            $doc,
        );

        if ($verdict === 'ENVIRONMENT VERIFIED') {
            $this->assertTrue(
                $verified,
                "🔴 حکم `ENVIRONMENT VERIFIED` است ولی جدولِ سه‌سطحی می‌گوید محیط تأیید نشده.\n"
                .'⚠️ همان قاطی‌کردنِ CODE READY با ENVIRONMENT VERIFIED که این مرحله برای بستنش بود.',
            );

            return;
        }

        $this->assertFalse(
            $verified,
            'جدول می‌گوید محیط تأیید شده ولی حکم چیزِ دیگری است.',
        );
    }

    /** ⚠️ سند نباید به فایلی پیوند بدهد که وجود ندارد. */
    public function test_every_document_it_links_to_exists(): void
    {
        preg_match_all(
            '/\]\((?!https?:)([A-Za-z0-9_\-.\/]+\.md)\)/',
            $this->readDoc(self::DOC),
            $matches,
        );

        $this->assertNotEmpty($matches[1], 'هیچ پیوندی خوانده نشد؛ محافظ پوچ می‌شود.');

        $broken = [];

        foreach (array_unique($matches[1]) as $link) {
            if (! file_exists(base_path('docs/'.$link))) {
                $broken[] = $link;
            }
        }

        $this->assertSame([], $broken, 'پیوندِ شکسته: '.implode('، ', $broken));
    }

    /**
     * سطرِ «۳۲۵ ردیفِ نقشهٔ مرحلهٔ دوم» از جدولِ تفکیک.
     *
     * @return list<int>
     */
    private function breakdownRow(string $doc): array
    {
        $line = null;

        foreach (preg_split('/\R/u', $doc) ?: [] as $candidate) {
            if (str_contains($candidate, 'ردیفِ نقشهٔ مرحلهٔ دوم')) {
                $line = $candidate;
                break;
            }
        }

        $this->assertNotNull($line, 'سطرِ تفکیکِ نقشهٔ مرحلهٔ دوم پیدا نشد.');

        preg_match_all('/[۰-۹]+/u', (string) $line, $matches);

        /*
         * ⚠️ اولین عدد «۳۲۵» است (شمارِ ردیف‌ها در متنِ سطر) و آخری هم جمع.
         * ستون‌های میانی همان شش عددِ برچسب‌اند.
         */
        $numbers = array_map(fn (string $n): int => (int) $this->latin($n), $matches[0]);

        $this->assertGreaterThanOrEqual(8, count($numbers), 'سطرِ تفکیک ستون کم دارد.');

        return array_slice($numbers, 1, 6);
    }

    private function latin(string $digits): string
    {
        return str_replace(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $digits,
        );
    }

    private function persianNumber(int $number): string
    {
        return str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            (string) $number,
        );
    }
}
