<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * گزارشِ نهاییِ فایلِ معماری با نقشه‌ی مدرک می‌خواند (مرحلهٔ ۱۰ · فاز ۱۵؛ بندهای ۱۳۵، ۱۳۶، ۱۴۱ و ۱۴۲).
 *
 * گزارش وضعیتِ تازه‌ای نمی‌سازد: وضعیتِ هر حوزه ضعیف‌ترین وضعیتِ بندهای همان حوزه در نقشه است، هر معیارِ پذیرش و هر
 * اصل فقط به بندهای VERIFIED بسته می‌شود، و هر آزمونی که نام می‌برد وجود دارد. پس «Complete با تغییرِ سند» (بندِ ۱۴۱)
 * این‌جا ممکن نیست: سبز کردنِ یک حوزه یعنی سبز کردنِ بندهایش در نقشه — که آن‌جا هم بی آزمونِ موجود پذیرفته نمی‌شود
 * (`ArchitectureEvidenceMapTest`).
 */
class ArchitectureFinalReportTest extends TestCase
{
    private const REPORT = 'docs/ARCHITECTURE_FINAL_REPORT.md';

    private const MAP = 'docs/ARCHITECTURE_EVIDENCE_MAP.md';

    /** همان ۱۸ حوزه و به همان ترتیبِ بندِ ۱۴۲ */
    private const AREAS = [
        'Account Architecture', 'Community Architecture', 'Unit Architecture', 'Membership Lifecycle',
        'Invitation System', 'Dashboard Context', 'Multi-Community Isolation', 'Messenger', 'Group Moderation',
        'Poll System', 'File Security', 'Financial Integration', 'Authorization', 'Database Integrity', 'Testing',
        'Adversarial Testing', 'Regression', 'Environment Verification',
    ];

    /** پنج خروجیِ بندِ ۱۴۲ و خطِ Definition of Done ِ بندِ ۱۳۶ */
    private const FIELDS = ['بندها', 'Final Status', 'Evidence', 'Tests', 'Known Limitations', 'Environment Dependency', 'Definition of Done'];

    private const DONE = ['Code', 'Database', 'Authorization', 'API', 'Tests', 'Adversarial', 'Regression', 'Documentation'];

    /** از ضعیف به قوی؛ «INTENTIONALLY NOT BUILT» وضعیتِ حوزه را پایین نمی‌آورد */
    private const RANK = [
        'OPEN' => 0,
        'NOT VERIFIED' => 1,
        'BLOCKED BY EXTERNAL ENVIRONMENT' => 2,
        'IMPLEMENTATION READY — ENVIRONMENT VERIFICATION REQUIRED' => 3,
        'VERIFIED' => 4,
    ];

    /** بندهای فرایندی (نه قابلیت): خودِ گزارش و قواعدِ کار، نه حوزه‌ای از سامانه */
    private const PROCESS = [1, 111, 112, 132];

    private const DIGITS = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];

    public function test_every_area_of_item_142_is_reported_with_every_field(): void
    {
        $areas = $this->areas();

        $this->assertSame(self::AREAS, array_keys($areas), 'حوزه‌های بندِ ۱۴۲ کامل و به ترتیب نیستند.');
        foreach ($areas as $name => $fields) {
            $this->assertSame(self::FIELDS, array_keys($fields), "حوزه‌ی «{$name}» همه‌ی خروجی‌ها را ندارد.");
            foreach ($fields as $field => $value) {
                $this->assertNotSame('', trim($value), "«{$field}» ِ «{$name}» خالی است.");
            }
            foreach (self::DONE as $dimension) {
                $this->assertMatchesRegularExpression("/(^|· ){$dimension}:/u", $fields['Definition of Done'], "Definition of Done ِ «{$name}» «{$dimension}» ندارد.");
            }
        }
    }

    /** 🔴 وضعیتِ هر حوزه ضعیف‌ترین وضعیتِ بندهایش در نقشه است — نه چیزی که دستی نوشته شده. */
    public function test_each_area_status_is_the_weakest_of_its_items_in_the_map(): void
    {
        $items = $this->mapStatuses();

        foreach ($this->areas() as $name => $fields) {
            $numbers = $this->numbers($fields['بندها']);
            $this->assertNotSame([], $numbers, "«{$name}» بندی ندارد.");

            $ranked = [];
            foreach ($numbers as $number) {
                $this->assertArrayHasKey($number, $items, "بندِ {$number} ِ «{$name}» در نقشه نیست.");
                if (isset(self::RANK[$items[$number]])) {
                    $ranked[$number] = self::RANK[$items[$number]];
                }
            }
            $weakest = array_search(min($ranked), self::RANK, true);

            $this->assertSame($weakest, trim($fields['Final Status']), "وضعیتِ «{$name}» با بندهایش در نقشه نمی‌خواند.");
        }
    }

    /** هر بندِ قابلیت (۲ تا ۱۳۴، جز بندهای فرایندی) دستِ کم در یک حوزه آمده است. */
    public function test_every_capability_item_belongs_to_an_area(): void
    {
        $covered = [];
        foreach ($this->areas() as $fields) {
            $covered = [...$covered, ...$this->numbers($fields['بندها'])];
        }

        $missing = array_values(array_diff(array_diff(range(2, 134), self::PROCESS), $covered));
        $this->assertSame([], $missing, 'این بندها در هیچ حوزه‌ای نیامده‌اند.');
    }

    /** 🔴 بندهای ۱۳۵ و ۱۴۱: هر معیار و هر اصل به بندهایی بسته است که در نقشه VERIFIED‌اند. */
    public function test_every_acceptance_criterion_and_principle_rests_on_verified_items(): void
    {
        $items = $this->mapStatuses();

        foreach (['معیارهای پذیرش (بندِ ۱۳۵)' => 46, 'اصلِ نهایی (بندِ ۱۴۱)' => 7] as $section => $expected) {
            $rows = $this->tableRows($section);
            $this->assertCount($expected, $rows, "شمارِ ردیف‌های «{$section}» کم یا زیاد است.");

            foreach ($rows as $cells) {
                $numbers = $this->numbers((string) end($cells));
                $this->assertNotSame([], $numbers, 'ردیفی بی بند: '.implode(' | ', $cells));
                foreach ($numbers as $number) {
                    $this->assertSame('VERIFIED', $items[$number] ?? null, "«{$cells[count($cells) - 2]}» به بندِ {$number} بسته است که VERIFIED نیست.");
                }
            }
        }
    }

    /** هر آزمونی که گزارش نام می‌برد وجود دارد. */
    public function test_every_cited_test_exists(): void
    {
        $report = $this->report();

        preg_match_all('/`(\w+Test)::(test_\w+)`/u', $report, $methods, PREG_SET_ORDER);
        $this->assertGreaterThan(50, count($methods), 'گزارش تقریباً هیچ آزمونی نام نمی‌برد.');
        foreach ($methods as [, $class, $method]) {
            $fqcn = "Tests\\Feature\\{$class}";
            $this->assertTrue(method_exists($fqcn, $method), "آزمونِ نام‌برده نیست: {$class}::{$method}");
        }

        preg_match_all('/`(tests\/(?:js|e2e)\/[\w.\/-]+)`/u', $report, $files);
        foreach ($files[1] as $file) {
            $this->assertFileExists(base_path($file));
        }
    }

    /* ── خواندنِ دو سند ─────────────────────────────────────────────────── */

    private function report(): string
    {
        return (string) file_get_contents(base_path(self::REPORT));
    }

    /** @return array<string, array<string, string>> */
    private function areas(): array
    {
        $body = (string) preg_replace('/^.*?\n## حوزه‌ها \(بندِ ۱۴۲\)\n|\n## معیارهای پذیرش.*$/su', '', $this->report());
        $areas = [];

        foreach (preg_split('/^### /mu', $body) ?: [] as $chunk) {
            [$name, $rest] = array_pad(explode("\n", $chunk, 2), 2, '');
            if (trim($name) === '' || ! str_contains($rest, '**')) {
                continue;
            }
            preg_match_all('/^- \*\*([^*]+):\*\* (.*)$/mu', $rest, $matches, PREG_SET_ORDER);
            $areas[trim($name)] = array_combine(array_column($matches, 1), array_column($matches, 2));
        }

        return $areas;
    }

    /** @return list<list<string>> */
    private function tableRows(string $section): array
    {
        $start = strpos($this->report(), "## {$section}");
        $this->assertNotFalse($start, "بخشِ «{$section}» نیست.");
        $tail = substr($this->report(), (int) $start + strlen("## {$section}"));
        $block = explode("\n## ", $tail)[0];

        $rows = [];
        foreach (explode("\n", $block) as $line) {
            if (! str_starts_with($line, '|') || str_contains($line, '---')) {
                continue;
            }
            $cells = array_map('trim', array_slice(explode('|', $line), 1, -1));
            if ($this->numbers((string) end($cells)) !== []) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    /** @return array<int, string> شماره‌ی بند ← وضعیت در نقشه */
    private function mapStatuses(): array
    {
        $statuses = [];
        foreach (explode("\n", (string) file_get_contents(base_path(self::MAP))) as $line) {
            if (preg_match('/^\|\s*([۰-۹]{1,3})\s*\|[^|]*\|\s*([^|]*?)\s*\|/u', $line, $m)) {
                $statuses[(int) strtr($m[1], self::DIGITS)] = $m[2];
            }
        }

        return $statuses;
    }

    /** @return list<int> */
    private function numbers(string $text): array
    {
        preg_match_all('/[۰-۹]+/u', $text, $m);

        return array_map(fn (string $n) => (int) strtr($n, self::DIGITS), $m[0]);
    }
}
