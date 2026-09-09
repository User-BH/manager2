<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReadsAuditMap;
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
    use ReadsAuditMap;

    private const AUDIT = 'docs/FINAL_AUDIT.md';

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
        $contents = $this->readDoc($path);

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
        $actual = $this->tallyAuditMap();
        $audit = $this->readDoc(self::AUDIT);

        $rows = [
            '✅' => 'انجام‌شده و سنجیده',
            '🟡' => 'جزئی یا وابسته به محیط',
            '🔒' => 'عمداً ساخته نشد',
            '❌' => 'انجام‌نشده',
            '❓' => 'نامعلوم',
            self::RULE_ROW => 'قاعدهٔ اجرا',
        ];

        foreach ($rows as $symbol => $label) {
            $claimed = $this->claimedNumberIn($audit, $label);
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
            $this->claimedNumberIn($audit, 'ردیف'),
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
        $tally = $this->tallyAuditMap();

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
            $contents = $this->readDoc($path);

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

    /** بخش‌های `## …` یک سند، به‌صورتِ عنوان ← متن. */
    private function numberedSectionsOf(string $path): array
    {
        $parts = preg_split('/^## /mu', $this->readDoc($path)) ?: [];
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
}
