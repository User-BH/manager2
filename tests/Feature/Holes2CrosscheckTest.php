<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * سندِ تطبیقِ «حفره ها۲» با مخزن می‌خواند (۲۰۲۶-۱۰-۲۶؛ بخش‌های ۱۶، ۱۷ و ۲۳ ِ همان سند).
 *
 * «اگر تست اجرا نشده، VERIFIED اعلام نکن»: این آزمون
 *   ① هر ۳۷ بخشِ خروجیِ بخشِ ۲۳ را دقیقاً یک بار می‌خواهد،
 *   ② برچسبِ هر بخش را فقط از پنج برچسبِ سند می‌خواهد،
 *   ③ هر VERIFIED را بی نامِ آزمون رد می‌کند،
 *   ④ هر نامِ آزمون و مسیرِ `tests/…` و `docs/…` را با مخزن می‌سنجد،
 *   ⑤ و جدولِ شمارش را از نو از خودِ ردیف‌ها می‌شمارد.
 */
class Holes2CrosscheckTest extends TestCase
{
    private const DOC = 'docs/HOLES2_CROSSCHECK.md';

    private const STATUSES = [
        'VERIFIED',
        'IMPLEMENTATION READY — ENVIRONMENT VERIFICATION REQUIRED',
        'INTENTIONALLY NOT BUILT',
        'BLOCKED BY EXTERNAL ENVIRONMENT',
        'OPEN',
    ];

    /** بخش‌های خروجیِ بخشِ ۲۳ ِ سند، بی «Release Decision» که حکم است نه برچسب */
    private const AREAS = [
        'Account Architecture', 'Community Architecture', 'Unit Architecture', 'Membership Lifecycle', 'Invitation System',
        'Dashboard Context', 'Multi-Community Isolation', 'Preview Environment', 'Production Environment',
        'Preview/Production Separation', 'Dashboard Routing', 'Plan / Subscription', 'Payment State Machine',
        'Payment Idempotency', 'Wallet Ledger', 'Wallet Balance Invariant', 'Messenger', 'Group Moderation', 'Poll System',
        'File Security', 'Authentication', 'Session Security', 'Secrets', 'PWA', 'API Contract', 'Database Integrity',
        'Queue Reliability', 'Upload Security', 'SSRF', 'Performance', 'Observability', 'Testing', 'Adversarial Testing',
        'Regression', 'Documentation', 'Backup / Restore', 'Environment Verification',
    ];

    private function document(): string
    {
        return (string) file_get_contents(base_path(self::DOC));
    }

    /** @return array<string, array{status: string, evidence: string}> */
    private function areas(): array
    {
        $areas = [];

        foreach (explode("\n", $this->document()) as $line) {
            if (! preg_match('/^\|\s*[۰-۹]{1,2}\s*\|([^|]*)\|([^|]*)\|(.*)\|\s*$/u', $line, $m)) {
                continue;
            }
            $name = trim($m[1]);
            $this->assertArrayNotHasKey($name, $areas, "بخشِ «{$name}» دو بار آمده است.");
            $areas[$name] = ['status' => trim($m[2]), 'evidence' => trim($m[3])];
        }

        return $areas;
    }

    public function test_every_area_of_section_23_is_reported_exactly_once(): void
    {
        $this->assertSame(self::AREAS, array_keys($this->areas()));
    }

    public function test_every_area_carries_exactly_one_of_the_five_statuses(): void
    {
        foreach ($this->areas() as $name => $area) {
            $this->assertContains($area['status'], self::STATUSES, "برچسبِ «{$name}» از پنج برچسبِ سند نیست.");
        }
    }

    public function test_nothing_is_verified_without_a_named_test(): void
    {
        foreach ($this->areas() as $name => $area) {
            if ($area['status'] === 'VERIFIED' && $name !== 'Testing') {
                $this->assertMatchesRegularExpression('/`[A-Z]\w*Test(?:::test_\w+)?`|`tests\/[^`]+`/', $area['evidence'], "«{$name}» VERIFIED است ولی آزمونی نام نمی‌برد.");
            }
        }
    }

    public function test_the_count_table_is_recomputed_from_the_rows(): void
    {
        $counted = array_count_values(array_column($this->areas(), 'status'));
        $digits = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];

        foreach (self::STATUSES as $status) {
            $this->assertMatchesRegularExpression('/^\| \*\*'.preg_quote($status, '/').'\*\*\s*\| \*\*([۰-۹]+)\*\*/mu', $this->document());
            preg_match('/^\| \*\*'.preg_quote($status, '/').'\*\*\s*\| \*\*([۰-۹]+)\*\*/mu', $this->document(), $m);
            $this->assertSame($counted[$status] ?? 0, (int) strtr($m[1], $digits), "شمارشِ «{$status}» با ردیف‌ها نمی‌خواند.");
        }

        $this->assertSame(0, $counted['OPEN'] ?? 0, 'بخشِ OPEN مانده است؛ حکمِ CODE READY نادرست می‌شود.');
    }

    public function test_every_test_named_in_the_document_exists(): void
    {
        preg_match_all('/`([A-Z]\w*Test)(?:::(test_\w+))?`/', $this->document(), $matches, PREG_SET_ORDER);
        $this->assertGreaterThan(80, count($matches), 'سند تقریباً هیچ آزمونی نام نمی‌برد.');

        foreach ($matches as $m) {
            $class = $this->resolveTestClass($m[1]);
            $this->assertNotNull($class, "آزمونِ «{$m[1]}» در مخزن نیست.");

            if (! empty($m[2])) {
                $this->assertTrue(method_exists($class, $m[2]), "متدِ «{$m[1]}::{$m[2]}» در مخزن نیست.");
            }
        }
    }

    public function test_every_path_named_in_the_document_exists(): void
    {
        preg_match_all('/`((?:tests|docs)\/[^`\s]+)`/', $this->document(), $matches);
        preg_match_all('/\]\(([A-Z0-9_]+\.md)\)/', $this->document(), $links);

        foreach (array_unique([...$matches[1], ...array_map(fn (string $f) => 'docs/'.$f, $links[1])]) as $path) {
            $this->assertFileExists(base_path($path), "مسیرِ «{$path}» ِ سند در مخزن نیست.");
        }
    }

    /** بخشِ ۱۶.۱ و ۲۳.۱: سه سطح جدا، و Production Ready بی ENVIRONMENT VERIFIED مجاز نیست */
    public function test_the_verdict_keeps_the_three_levels_apart(): void
    {
        $doc = $this->document();

        $this->assertMatchesRegularExpression('/^\| \*\*CODE READY\*\*\s*\| ✅/mu', $doc);
        $this->assertMatchesRegularExpression('/^\| \*\*ENVIRONMENT VERIFIED\*\*\s*\| 🔴/mu', $doc);
        $this->assertMatchesRegularExpression('/^\| \*\*PRODUCTION READY\*\*\s*\| 🔴 \*\*NOT ALLOWED\*\*/mu', $doc);
    }

    /** @return class-string|null */
    private function resolveTestClass(string $name): ?string
    {
        foreach (['Feature', 'Unit'] as $suite) {
            $class = "Tests\\{$suite}\\{$name}";
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }
}
