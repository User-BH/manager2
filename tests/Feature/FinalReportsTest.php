<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * چهار گزارشِ نهایی واقعاً همان چیزی را می‌گویند که ادعا می‌کنند (R72).
 *
 * ─── 🔴 چرا یک سند به محافظ نیاز دارد ──────────────────────────────────────
 * گزارشِ نهایی بدترین نوعِ سند است برای کهنه‌شدن: یک بار نوشته می‌شود، همه
 * بعداً به آن استناد می‌کنند، و هیچ‌کس دوباره نمی‌خواندش.
 *
 * ⚠️ خطرناک‌ترین حالتش هم «غلط بودن» نیست، «کهنه بودن» است: ردیفی در نقشهٔ
 * ممیزی از ✅ به ❌ برمی‌گردد و گزارشِ نهایی همچنان می‌گوید همه‌چیز سبز
 * است. آن‌وقت کسی روی عددی تصمیم می‌گیرد که دیگر درست نیست.
 *
 * پس اعدادِ گزارش **از خودِ نقشه محاسبه** و با آنچه نوشته شده مقایسه
 * می‌شوند — نه اینکه صرفاً وجودشان سنجیده شود.
 */
class FinalReportsTest extends TestCase
{
    private const MAP = 'docs/STAGE2_AUDIT_MAP.md';

    private const AUDIT = 'docs/FINAL_AUDIT.md';

    /** نشانه‌های وضعیت، به همان ترتیبی که در نقشه معنا دارند. */
    /** ردیفی که وضعیت ندارد چون اصلاً موردِ قابلِ سنجش نیست. */
    private const RULE = '—';

    private const STATUSES = ['✅', '🟡', '❌', '🔒', '❓'];

    /**
     * هر گزارش و فیلدهایی که فایلِ اصلی برایش خواسته.
     *
     * ⚠️ این‌ها از خواستهٔ صریحِ بندهای ۲۲۵، ۲۵۳، ۲۸۸ و ۳۱۲ آمده‌اند، نه از
     * سلیقهٔ نویسنده. اگر فیلدی از گزارش بیفتد، گزارش دیگر آن چیزی نیست که
     * خواسته شده بود.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function reports(): array
    {
        return [
            'گزارشِ امنیتی' => ['docs/FINAL_SECURITY_REPORT.md', [
                'شدت', 'مؤلفه', 'مشکل', 'سناریوی حمله', 'اصلاح', 'راستی‌آزمایی',
            ]],
            'گزارشِ تست' => ['docs/FINAL_TEST_REPORT.md', [
                'Total', 'Passed', 'Failed', 'Skipped', 'Coverage',
                'Critical Failures', 'Known Issues', 'Unverified Areas',
            ]],
            'گزارشِ کارایی' => ['docs/FINAL_PERFORMANCE_REPORT.md', [
                'Bottlenecks', 'Changes', 'Before', 'After', 'Remaining', 'Budget', 'Recommendations',
            ]],
            'گزارشِ NOT VERIFIED' => ['docs/NOT_VERIFIED.md', [
                'نام', 'دلیلِ سنجیده‌نشدن', 'نیازِ Verification', 'زیرساختِ خارجی',
                'سرویسِ ثالث', 'تستِ انسانی', 'محیطِ محصول', 'سطحِ ریسک', 'پیشنهادِ دقیق',
            ]],
        ];
    }

    #[DataProvider('reports')]
    public function test_every_report_has_the_fields_that_were_asked_for(string $path, array $fields): void
    {
        $contents = $this->read($path);

        foreach ($fields as $field) {
            $this->assertStringContainsString(
                $field,
                $contents,
                "فیلدِ «{$field}» در «{$path}» نیست.\n"
                .'⚠️ این فیلد در خواستهٔ اصلی صریح آمده بود.',
            );
        }
    }

    /**
     * 🔴 هر یک از دوازده موردِ NOT VERIFIED باید **هر نُه فیلد** را داشته باشد.
     *
     * ⚠️ سنجیدنِ اینکه «کلمهٔ سطحِ ریسک جایی در فایل هست» کافی نیست: موردی
     * که ریسکش نوشته نشده، دقیقاً همان موردی است که کسی جدی‌اش نمی‌گیرد.
     * پس هر بخش جداگانه سنجیده می‌شود.
     */
    public function test_every_unverified_item_carries_all_nine_fields(): void
    {
        [, $fields] = self::reports()['گزارشِ NOT VERIFIED'];

        $sections = $this->numberedSectionsOf('docs/NOT_VERIFIED.md');

        $this->assertGreaterThanOrEqual(
            10,
            count($sections),
            'گزارشِ NOT VERIFIED کم‌مورد شده؛ احتمالاً بخشی حذف شده است.',
        );

        foreach ($sections as $title => $body) {
            foreach ($fields as $field) {
                $this->assertStringContainsString(
                    $field,
                    $body,
                    "موردِ «{$title}» فیلدِ «{$field}» را ندارد.",
                );
            }
        }
    }

    /** ⚠️ هر یافتهٔ امنیتی هم باید هر شش فیلدش را داشته باشد، نه فقط فایل. */
    public function test_every_security_finding_carries_all_six_fields(): void
    {
        [, $fields] = self::reports()['گزارشِ امنیتی'];

        $findings = array_filter(
            $this->numberedSectionsOf('docs/FINAL_SECURITY_REPORT.md'),
            fn (string $title): bool => str_starts_with($title, 'S-'),
            ARRAY_FILTER_USE_KEY,
        );

        $this->assertGreaterThanOrEqual(9, count($findings), 'یافته‌های امنیتی کم شده‌اند.');

        foreach ($findings as $title => $body) {
            foreach ($fields as $field) {
                $this->assertStringContainsString($field, $body, "یافتهٔ «{$title}» فیلدِ «{$field}» را ندارد.");
            }
        }
    }

    /**
     * 🔴 مهم‌ترین ادعای این فایل: اعدادِ ممیزیِ نهایی با خودِ نقشه بخوانند.
     *
     * ─── چرا این و نه سنجشِ وجودِ عدد ───────────────────────────────────────
     * ⚠️ محافظی که فقط بپرسد «عددی نوشته شده؟» پوچ است: هر عددی از تور رد
     * می‌شود. اینجا شمارش **از نو انجام می‌شود** و با نوشته مقایسه می‌گردد،
     * پس هر تغییری در نقشه که به گزارش نرسد، همین‌جا قرمز می‌کند.
     */
    public function test_the_audit_numbers_match_the_map(): void
    {
        $actual = $this->tally();
        $audit = $this->read(self::AUDIT);

        $rows = [
            '✅' => 'انجام‌شده و سنجیده',
            '🟡' => 'جزئی یا وابسته به محیط',
            '🔒' => 'عمداً ساخته نشد',
            '❌' => 'انجام‌نشده',
            '❓' => 'نامعلوم',
            self::RULE => 'قاعدهٔ اجرا',
        ];

        foreach ($rows as $symbol => $label) {
            $claimed = $this->claimedNumberFor($audit, $label);
            $real = $actual[$symbol] ?? 0;

            $this->assertSame(
                $real,
                $claimed,
                "ممیزیِ نهایی برای «{$label}» عددِ {$claimed} نوشته، ولی نقشه {$real} ردیف دارد.\n"
                .'⚠️ یا نقشه عوض شده و گزارش به‌روز نشده، یا برعکس.',
            );
        }

        $this->assertSame(
            array_sum($actual),
            $this->claimedNumberFor($audit, 'ردیف'),
            'مجموعِ ردیف‌های نوشته‌شده با نقشه نمی‌خواند.',
        );
    }

    /**
     * 🔴 هیچ ردیفی نباید بی‌پاسخ بماند.
     *
     * ⚠️ ❌ یعنی «انجام نشده» و ❓ یعنی «نمی‌دانیم» — و دومی بدتر است. R72
     * هر چهار موردِ ❓ را با نگاه به کدِ واقعی بست. این محافظ نمی‌گذارد
     * ردیفِ تازه‌ای بی‌پاسخ اضافه شود و کسی متوجه نشود.
     *
     * ⚠️ اگر روزی ردیفی واقعاً بی‌پاسخ بود، راهِ درست تغییرِ این تست نیست —
     * نوشتنِ 🔒 با دلیل است، که همان اعلامِ صادقانهٔ «عمداً نه» است.
     */
    public function test_no_audit_row_is_left_unanswered(): void
    {
        $tally = $this->tally();

        $this->assertSame(0, $tally['❌'] ?? 0, 'ردیفِ ❌ در نقشه هست؛ یا انجامش دهید یا با دلیل 🔒 کنید.');
        $this->assertSame(0, $tally['❓'] ?? 0, 'ردیفِ ❓ در نقشه هست؛ «نمی‌دانیم» وضعیتِ نهایی نیست.');
    }

    /**
     * ⚠️ گزارشی که به سندِ ناموجود پیوند بدهد، خواننده را به بن‌بست می‌برد.
     *
     * فقط پیوندهای نسبیِ داخلِ `docs/` سنجیده می‌شوند؛ آدرسِ بیرونی کارِ این
     * تست نیست.
     */
    public function test_every_document_the_reports_link_to_exists(): void
    {
        $broken = [];

        foreach (self::reports() as [$path]) {
            $contents = $this->read($path);

            preg_match_all('/\]\((?!https?:)([A-Za-z0-9_\-.\/]+\.md)\)/', $contents, $matches);

            foreach (array_unique($matches[1]) as $link) {
                if (! is_file(base_path('docs/'.$link))) {
                    $broken[] = "{$path} → {$link}";
                }
            }
        }

        $this->assertSame([], $broken, "پیوندِ شکسته:\n  ".implode("\n  ", $broken));
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ابزار

    private function read(string $path): string
    {
        $full = base_path($path);

        $this->assertFileExists($full, "گزارشِ «{$path}» وجود ندارد.");

        return (string) file_get_contents($full);
    }

    /**
     * شمارشِ وضعیت‌ها از خودِ نقشه.
     *
     * ⚠️ فقط ردیف‌هایی که خانهٔ اولشان **شماره** است — سرستون و جداکننده
     * نباید شمرده شوند، وگرنه عدد بی‌معنا می‌شود.
     *
     * ─── 🔴 چرا `/u` روی `\R` حیاتی است ─────────────────────────────────────
     * ⚠️ `preg_split('/\R/', ...)` بدونِ `/u` در حالتِ **بایتی** کار می‌کند و
     * `\R` آنجا بایتِ `0x85` را هم می‌گیرد — که دقیقاً بایتِ دومِ حرفِ «م»
     * است (`D9 85`).
     *
     * یعنی هر خطی که «م» داشته باشد **وسطِ حرف** تکه می‌شود و آن بایت هم
     * حذف می‌گردد. نتیجه‌اش خطایی است که مثلِ باگِ منطقی به نظر می‌رسد:
     * برچسبِ فارسی در هیچ خطی پیدا نمی‌شود، در حالی که در فایل هست.
     *
     * همین‌جا واقعاً اتفاق افتاد و اولین اجرای این تست را قرمز کرد.
     *
     * @return array<string, int>
     */
    private function tally(): array
    {
        $tally = [];

        foreach (preg_split('/\R/u', $this->read(self::MAP)) ?: [] as $line) {
            $line = trim($line);

            if (! str_starts_with($line, '|')) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim($line, '|')));

            if (count($cells) < 2 || preg_match('/^\d+$/', $cells[0]) !== 1) {
                continue;
            }

            $rest = implode(' ', array_slice($cells, 1));

            $found = null;

            foreach (self::STATUSES as $symbol) {
                if (str_contains($rest, $symbol)) {
                    $found = $symbol;
                    break;
                }
            }

            /*
             * ⚠️ ردیفی که هیچ نشانه‌ای ندارد «قاعدهٔ اجرا» است — دستورالعملِ
             * کار، نه موردِ قابلِ تیک‌زدن. اگر شمرده نشود، مجموعِ گزارش با
             * نقشه نمی‌خواند و محافظ به‌جای گرفتنِ کهنگی، خودش قرمز می‌ماند.
             */
            $key = $found ?? self::RULE;
            $tally[$key] = ($tally[$key] ?? 0) + 1;
        }

        $this->assertNotEmpty($tally, 'هیچ ردیفی از نقشه خوانده نشد؛ محافظ پوچ می‌شود.');

        return $tally;
    }

    /** بخش‌های `## …` یک سند، به‌صورتِ عنوان ← متن. */
    private function numberedSectionsOf(string $path): array
    {
        $parts = preg_split('/^## /mu', $this->read($path)) ?: [];
        $sections = [];

        foreach (array_slice($parts, 1) as $part) {
            $title = trim(strtok($part, "\n") ?: '');

            // بخش‌های جمع‌بندی مورد نیستند و فیلد ندارند
            if ($title === '' || ! preg_match('/^(S-\d+|[۰-۹]+\))/u', $title)) {
                continue;
            }

            $sections[$title] = $part;
        }

        return $sections;
    }

    /**
     * عددِ فارسیِ نوشته‌شده در ردیفی که برچسبِ داده‌شده را دارد.
     *
     * ⚠️ ارقام فارسی‌اند و `(int)` رویشان صفر می‌دهد — که یعنی محافظ بی‌صدا
     * پوچ می‌شد. پس صریح تبدیل می‌شوند.
     */
    private function claimedNumberFor(string $document, string $label): int
    {
        $latin = null;

        foreach (preg_split('/\R/u', $document) ?: [] as $line) {
            if (! str_contains($line, $label)) {
                continue;
            }

            if (preg_match('/\*\*([۰-۹,٬]+)\*\*/u', $line, $m) === 1) {
                $latin = $m[1];
                break;
            }
        }

        $this->assertNotNull($latin, "عددی برای «{$label}» در ممیزیِ نهایی پیدا نشد.");

        return (int) str_replace(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', ',', '٬'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '', ''],
            $latin,
        );
    }
}
