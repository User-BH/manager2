<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReadsAuditMap;
use Tests\TestCase;

/**
 * سندِ نهاییِ پیش از محصول همان چیزی است که ادعا می‌کند (فاز ۹).
 *
 * ─── 🔴 چرا این سند بیش از بقیه به محافظ نیاز دارد ─────────────────────────
 * این آخرین سندی است که کسی می‌خواند و اولین چیزی که به آن استناد می‌شود.
 * ⚠️ اگر کهنه شود، همه بر پایهٔ عددی تصمیم می‌گیرند که دیگر درست نیست — و
 * چون «نهایی» نامیده شده، کسی دوباره نمی‌سنجدش.
 *
 * ─── مرزِ صریحِ این محافظ ───────────────────────────────────────────────────
 * 🔴 این تست **درستیِ ادعاها را ثابت نمی‌کند**. کاری که می‌کند این است که
 * نگذارد سند ساختارش را از دست بدهد، عددش از نقشه جدا بیفتد، یا حکمی
 * بدهد که با وضعیتِ ثبت‌شدهٔ خودش نمی‌خواند.
 */
class FinalPreProductionAuditTest extends TestCase
{
    use ReadsAuditMap;

    private const DOC = 'docs/FINAL_PRE_PRODUCTION_AUDIT.md';

    /** ⚠️ هر سه حکمِ مجاز، و بس. هیچ عبارتِ سومی «تقریباً آماده» نیست. */
    private const VERDICTS = [
        'PRODUCTION READY — INFRASTRUCTURE VERIFICATION REQUIRED',
        'NOT PRODUCTION READY',
        'PRODUCTION READY',
    ];

    /**
     * بیست‌وچهار بخشی که نقشهٔ مرحلهٔ چهارم نام برده بود.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function sections(): array
    {
        $titles = [
            'خلاصهٔ مدیریتی', 'معماریِ فعلی', 'امنیت', 'چندمستأجری',
            'احراز هویت', 'یکپارچگیِ مالی', 'دفترِ کیفِ پول',
            'ماشینِ حالتِ پرداخت', 'Idempotency', 'یکپارچگیِ دیتابیس',
            'PWA و Service Worker', 'قراردادِ API', 'امنیتِ آپلود',
            'اتکاپذیریِ صف', 'مشاهده‌پذیری', 'تست', 'کارایی', 'استقرار',
            'بکاپ و بازیابی', 'بازیابی از فاجعه', 'راستی‌آزماییِ محیطی',
            'محدودیت‌های شناخته‌شده', 'آنچه عمداً ساخته نشد',
            'تصمیمِ نهاییِ انتشار',
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
            "بخشِ {$number} («{$title}») در سندِ نهایی نیست.",
        );
    }

    /** ⚠️ نه کمتر از ۲۴ بخش، و نه بخشِ شماره‌دارِ اضافه‌ای که در فهرست نباشد. */
    public function test_the_document_has_exactly_the_twenty_four_sections(): void
    {
        preg_match_all('/^## ([۰-۹]+)\) /mu', $this->readDoc(self::DOC), $matches);

        $this->assertCount(
            24,
            $matches[1],
            '⚠️ شمارِ بخش‌های شماره‌دارِ سند ۲۴ نیست.',
        );
    }

    /**
     * 🔴 مهم‌ترین ادعای این فایل: شمارشِ مرجع از نو از نقشه محاسبه شود.
     *
     * ⚠️ محافظی که فقط بپرسد «جدولی هست؟» پوچ است. اینجا هر برچسب از
     * ردیف‌های `STAGE2_AUDIT_MAP` شمرده می‌شود و با عددِ نوشته‌شده در سندِ
     * نهایی مقایسه می‌گردد.
     */
    public function test_the_canonical_count_is_recomputed_from_the_map(): void
    {
        /*
         * 🔴 فقط بخشِ ۲۴ خوانده می‌شود، نه کلِ سند.
         *
         * ⚠️ نسخهٔ اولِ همین تست کلِ سند را می‌گشت و **ردیفِ اشتباهی** را
         * پیدا می‌کرد: خلاصهٔ مدیریتی هم سطری دارد که هم واژهٔ `OPEN` در
         * آن است و هم عددِ برجسته. چون هر دو امروز ۰ بودند، تست سبز شد
         * بی‌آنکه جدولِ مرجع را اصلاً دیده باشد.
         *
         * یعنی محافظ از روزِ اول پوچ بود و سبزیِ اولش هیچ چیزی ثابت نمی‌کرد.
         */
        $doc = $this->canonicalCountSection();
        $actual = $this->tallyByLabel();

        foreach ([
            'VERIFIED',
            'IMPLEMENTATION READY — ENVIRONMENT VERIFICATION REQUIRED',
            'INTENTIONALLY NOT BUILT',
            'OPEN',
            'قاعدهٔ اجرا',
        ] as $label) {
            $this->assertSame(
                $actual[$label] ?? 0,
                $this->claimedNumberIn($doc, $label),
                "شمارشِ مرجع برای «{$label}» با نقشه نمی‌خواند.\n"
                .'⚠️ یا نقشه عوض شده و سند به‌روز نشده، یا برعکس.',
            );
        }

        $this->assertSame(
            array_sum($actual),
            $this->claimedNumberIn($doc, 'جمع'),
            'مجموعِ نوشته‌شده با نقشه نمی‌خواند.',
        );
    }

    /**
     * 🔴 هیچ ردیفِ `OPEN`ی نباید بماند — و این را از نقشه می‌سنجیم، نه از متن.
     *
     * ⚠️ نوشتنِ «OPEN = ۰» در سند آسان است؛ آنچه سخت است این است که واقعاً
     * صفر بماند.
     */
    public function test_no_row_of_the_map_is_still_open(): void
    {
        $this->assertSame(
            0,
            $this->tallyByLabel()['OPEN'] ?? 0,
            '🔴 ردیفِ بازی در نقشه هست؛ حکمِ نهایی نمی‌تواند صادر شود.',
        );
    }

    /**
     * هفت موردِ محیطی، هرکدام با شش چیزی که نقشه خواسته بود.
     *
     * 🔴 مهم‌ترینشان **معیارِ شکست** است: بدونِ آن، «اجرا کنید و ببینید»
     * دستورالعمل نیست، آرزو است.
     */
    public function test_every_environment_item_carries_all_six_fields(): void
    {
        $doc = $this->readDoc(self::DOC);

        preg_match_all('/^### (E-\d+) · (.+)$/mu', $doc, $headings, PREG_SET_ORDER);

        $this->assertCount(7, $headings, '⚠️ هفت موردِ محیطی انتظار می‌رفت.');

        $blocks = preg_split('/^### E-\d+ · /mu', $doc) ?: [];
        $blocks = array_slice($blocks, 1, 7);

        foreach ($headings as $index => [, $code, $title]) {
            $body = $blocks[$index] ?? '';

            foreach ([
                '**پیاده‌سازی:**',
                '**دستور:**',
                '**پیش‌نیاز:**',
                '**نتیجهٔ موردِ انتظار:**',
                '**معیارِ شکست:**',
                '**وضعیت:**',
            ] as $field) {
                $this->assertStringContainsString(
                    $field,
                    $body,
                    "موردِ {$code} («{$title}») فیلدِ «{$field}» را ندارد.",
                );
            }
        }
    }

    /**
     * موارد خارج از دامنه، هرکدام با سه چیز.
     *
     * ⚠️ **آستانه** مهم‌ترینشان است: «فعلاً لازم نیست» بدونِ شرطِ لازم‌شدن،
     * تصمیم نیست — تعویق است.
     */
    public function test_every_out_of_scope_item_states_its_future_threshold(): void
    {
        $doc = $this->readDoc(self::DOC);

        $scope = strstr($doc, '## ۲۳) آنچه عمداً ساخته نشد');
        $this->assertIsString($scope, 'بخشِ ۲۳ پیدا نشد.');

        $scope = strstr((string) $scope, '## ۲۴)', true) ?: (string) $scope;

        $blocks = array_slice(preg_split('/^### /mu', $scope) ?: [], 1);

        $this->assertGreaterThanOrEqual(
            5,
            count($blocks),
            '⚠️ نقشه شش موردِ خارج از دامنه نام برده بود.',
        );

        foreach ($blocks as $block) {
            $title = trim(strtok($block, "\n") ?: '');

            foreach (['**دلیل:**', '**سازگاری:**', '**آستانه'] as $field) {
                $this->assertStringContainsString(
                    $field,
                    $block,
                    "موردِ «{$title}» فیلدِ «{$field}» را ندارد.",
                );
            }
        }
    }

    /**
     * 🔴 دقیقاً یک حکم، و از فهرستِ مجاز.
     *
     * ─── چرا این‌طور سنجیده می‌شود ──────────────────────────────────────────
     * ⚠️ `PRODUCTION READY` رشته‌ای است که در دو حکمِ دیگر هم هست. پس صرفِ
     * «پیدا شد» چیزی ثابت نمی‌کند و باید **بلندترین** تطبیق برنده باشد،
     * وگرنه محافظ همیشه خوش‌بینانه‌ترین جواب را می‌دهد.
     */
    public function test_the_verdict_is_exactly_one_of_the_three(): void
    {
        $doc = $this->readDoc(self::DOC);

        $this->assertSame(
            1,
            preg_match('/^> # `([^`]+)`$/mu', $doc, $match),
            'حکمِ نهایی به‌شکلِ نقلِ‌قولِ برجسته در سند نیست.',
        );

        $this->assertContains(
            $match[1],
            self::VERDICTS,
            "حکمِ «{$match[1]}» جزوِ سه حکمِ مجاز نیست.",
        );
    }

    /**
     * 🔴 حکم با وضعیتِ ثبت‌شدهٔ خودِ سند بخواند.
     *
     * ⚠️ این همان جایی است که گزارش‌ها معمولاً دروغ می‌گویند: جدول
     * می‌گوید محیط تأیید نشده و حکم می‌گوید آماده است. اگر روزی هر هفت
     * موردِ محیطی بسته شدند، این تست هم باید عوض شود — **آگاهانه**.
     */
    public function test_the_verdict_matches_the_three_level_table(): void
    {
        $doc = $this->readDoc(self::DOC);

        preg_match('/^> # `([^`]+)`$/mu', $doc, $match);
        $verdict = $match[1] ?? '';

        $environmentVerified = (bool) preg_match(
            '/\*\*ENVIRONMENT VERIFIED\*\*\s*\|\s*✅/u',
            $doc,
        );

        if ($environmentVerified) {
            $this->assertSame(
                'PRODUCTION READY',
                $verdict,
                'محیط تأیید شده ولی حکم هنوز مشروط است.',
            );

            return;
        }

        $this->assertNotSame(
            'PRODUCTION READY',
            $verdict,
            "🔴 حکمِ بی‌قیدِ «PRODUCTION READY» در حالی که جدول می‌گوید محیط تأیید نشده.\n"
            .'⚠️ همان قاطی‌کردنِ CODE READY با PRODUCTION READY که این مرحله برای بستنش بود.',
        );
    }

    /**
     * ⚠️ سند نباید به فایلی پیوند بدهد که وجود ندارد.
     *
     * خواننده‌ای که به بن‌بست بخورد، بقیهٔ سند را هم باور نمی‌کند.
     */
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
     * فقط متنِ بخشِ ۲۴ — جایی که جدولِ شمارشِ مرجع در آن است.
     *
     * ⚠️ اگر روزی عنوانِ بخش عوض شود، این متد قرمز می‌کند نه اینکه بی‌صدا
     * کلِ سند را برگرداند؛ همان بازگشتِ خاموشی که تست را پوچ کرده بود.
     */
    private function canonicalCountSection(): string
    {
        $section = strstr($this->readDoc(self::DOC), '## ۲۴) تصمیمِ نهاییِ انتشار');

        $this->assertIsString($section, 'بخشِ ۲۴ پیدا نشد؛ محافظ پوچ می‌شود.');

        return (string) $section;
    }

    /** ارقامِ فارسیِ یک عدد. */
    private function persianNumber(int $number): string
    {
        return str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            (string) $number,
        );
    }
}
