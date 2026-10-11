<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * سندِ Verification ِ مرحلهٔ ۹ با مخزن می‌خواند (فاز ۹/۹؛ بندِ ۴۰ ِ فایلِ ۲).
 *
 * «هیچ موردی که صرفاً بررسی نشده، به عنوان VERIFIED گزارش نشود»: این آزمون
 *   ① همه‌ی ۴۳ بند (سه بندِ «موارد تکمیلی» و ۴۰ بندِ اصلی) را دقیقاً یک بار می‌خواهد،
 *   ② برچسبِ هر بند را از همان پنج برچسبِ پروژه می‌خواهد،
 *   ③ هر VERIFIED را بی مدرکِ آزمون یا commit رد می‌کند،
 *   ④ و هر نامِ آزمون (`Class::test_method` یا `ClassTest`) و هر مسیرِ `tests/…` و
 *      `docs/…` ِ سند را با مخزن می‌سنجد — آزمونِ تغییرنام‌یافته یا حذف‌شده سند را قرمز می‌کند.
 */
class Stage9VerificationTest extends TestCase
{
    private const DOC = 'docs/STAGE9_VERIFICATION.md';

    private const STATUSES = [
        'VERIFIED',
        'IMPLEMENTATION READY — ENVIRONMENT VERIFICATION REQUIRED',
        'INTENTIONALLY NOT BUILT',
        'OPEN',
        'NOT VERIFIED',
    ];

    private function document(): string
    {
        return (string) file_get_contents(base_path(self::DOC));
    }

    /** @return array<string, array{status: string, evidence: string}> */
    private function items(): array
    {
        $items = [];
        $ids = '(الف-[۱-۳]|[۰-۹]{1,2})';

        foreach (explode("\n", $this->document()) as $line) {
            if (! preg_match('/^\|\s*'.$ids.'\s*\|[^|]*\|([^|]*)\|(.*)\|\s*$/u', $line, $m)) {
                continue;
            }
            $id = strtr(trim($m[1]), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
            $this->assertArrayNotHasKey($id, $items, "بندِ {$id} دو بار در سند آمده است.");
            $items[$id] = ['status' => trim($m[2]), 'evidence' => trim($m[3])];
        }

        return $items;
    }

    public function test_every_item_of_the_file_is_reported_exactly_once(): void
    {
        $expected = ['الف-1', 'الف-2', 'الف-3', ...array_map('strval', range(1, 40))];

        $this->assertEqualsCanonicalizing($expected, array_keys($this->items()));
    }

    public function test_every_item_carries_one_of_the_project_statuses(): void
    {
        foreach ($this->items() as $id => $item) {
            $known = array_filter(self::STATUSES, fn (string $s) => str_starts_with($item['status'], $s));
            $this->assertNotEmpty($known, "برچسبِ بندِ {$id} («{$item['status']}») از برچسب‌های پروژه نیست.");
        }
    }

    public function test_nothing_is_verified_without_evidence(): void
    {
        foreach ($this->items() as $id => $item) {
            if (! str_starts_with($item['status'], 'VERIFIED')) {
                continue;
            }

            $hasTest = preg_match('/`(?:[A-Z]\w*Test(?:::test_\w+)?|tests\/[^`]+)`/', $item['evidence']) === 1;
            $hasCommit = preg_match('/`[0-9a-f]{7}`/', $item['evidence']) === 1;
            $pointsToSection = str_contains($item['evidence'], 'DASHBOARD STATUS') || str_contains($item['evidence'], 'جدولِ «بندِ');

            $this->assertTrue($hasTest || $hasCommit || $pointsToSection, "بندِ {$id} VERIFIED است ولی مدرکی ندارد.");
        }
    }

    public function test_every_test_named_in_the_document_exists(): void
    {
        preg_match_all('/`([A-Z]\w*Test)(?:::(test_\w+))?`/', $this->document(), $matches, PREG_SET_ORDER);
        $this->assertGreaterThan(60, count($matches), 'سند تقریباً هیچ آزمونی نام نمی‌برد.');

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
        $this->assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $path) {
            $this->assertFileExists(base_path($path), "مسیرِ «{$path}» ِ سند در مخزن نیست.");
        }
    }

    /** بندِ ۴۰: DATA SAFETY با شش پرسش و DASHBOARD STATUS با پنج داشبورد. */
    public function test_the_data_safety_and_dashboard_sections_answer_every_question(): void
    {
        $doc = $this->document();

        foreach (['User recreation', 'Migration destructive', 'Wallet/Payment/Transaction', 'Profile', 'Existing relationships', 'Backup'] as $question) {
            $this->assertMatchesRegularExpression('/^\| '.preg_quote($question, '/').'[^|]*\|/mu', $doc, "پرسشِ «{$question}» ِ DATA SAFETY پاسخ ندارد.");
        }

        foreach (['Initial', 'Unit', 'Manager', 'Super Admin', 'Preview Accounts'] as $dashboard) {
            $this->assertMatchesRegularExpression('/^\| '.preg_quote($dashboard, '/').'\s*\|/mu', $doc, "وضعیتِ داشبوردِ «{$dashboard}» گزارش نشده.");
        }
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
