<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * نقشه‌ی مدرکِ ۱۴۴ بندِ فایلِ معماری با مخزن می‌خواند (مرحلهٔ ۱۰ · فاز ۱).
 *
 * بندهای ۱۱۱، ۱۱۲ و ۱۳۸ ِ همان فایل: «Documentation added» و «Status changed to complete»
 * مدرک نیستند. پس این آزمون
 *   ① هر ۱۴۴ بند را دقیقاً یک بار می‌خواهد،
 *   ② برچسب را از taxonomy ِ پروژه،
 *   ③ هر VERIFIED را با دست‌کم یک آزمونِ موجود،
 *   ④ هر بندِ OPEN یا NOT VERIFIED را با فازی از ۱۰/۲ تا ۱۰/۱۶ که آن را می‌بندد،
 *   ⑤ هر نامِ آزمون و هر مسیرِ فایل ِ سند را با مخزن،
 *   ⑥ و جدولِ «خلاصه» را با شمارشِ دوباره از خودِ ماتریس.
 */
class ArchitectureEvidenceMapTest extends TestCase
{
    private const DOC = 'docs/ARCHITECTURE_EVIDENCE_MAP.md';

    private const STATUSES = [
        'VERIFIED',
        'IMPLEMENTATION READY — ENVIRONMENT VERIFICATION REQUIRED',
        'INTENTIONALLY NOT BUILT',
        'BLOCKED BY EXTERNAL ENVIRONMENT',
        'OPEN',
        'NOT VERIFIED',
    ];

    private const PERSIAN_DIGITS = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];

    private function document(): string
    {
        return (string) file_get_contents(base_path(self::DOC));
    }

    /** @return array<int, array{status: string, phase: string, evidence: string}> */
    private function items(): array
    {
        $items = [];

        foreach (explode("\n", $this->document()) as $line) {
            if (! preg_match('/^\|\s*([۰-۹]{1,3})\s*\|[^|]*\|([^|]*)\|([^|]*)\|(.*)\|\s*$/u', $line, $m)) {
                continue;
            }
            $id = (int) strtr($m[1], self::PERSIAN_DIGITS);
            $this->assertArrayNotHasKey($id, $items, "بندِ {$id} دو بار در نقشه آمده است.");
            $items[$id] = ['status' => trim($m[2]), 'phase' => trim($m[3]), 'evidence' => trim($m[4])];
        }

        return $items;
    }

    private function statusOf(string $cell): ?string
    {
        // «NOT VERIFIED» پیش از «VERIFIED» سنجیده شود
        $ordered = self::STATUSES;
        usort($ordered, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($ordered as $status) {
            if ($cell === $status || str_starts_with($cell, $status.' ')) {
                return $status;
            }
        }

        return null;
    }

    public function test_every_one_of_the_144_items_is_mapped_exactly_once(): void
    {
        $ids = array_keys($this->items());
        sort($ids);

        $this->assertSame(range(1, 144), $ids);
    }

    public function test_every_item_carries_one_of_the_project_statuses(): void
    {
        foreach ($this->items() as $id => $item) {
            $this->assertNotNull($this->statusOf($item['status']), "برچسبِ بندِ {$id} («{$item['status']}») از taxonomy ِ پروژه نیست.");
        }
    }

    public function test_nothing_is_verified_without_a_test(): void
    {
        foreach ($this->items() as $id => $item) {
            if ($this->statusOf($item['status']) !== 'VERIFIED') {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/`(?:[A-Z]\w*Test(?:::test_\w+)?|tests\/[^`]+)`/',
                $item['evidence'],
                "بندِ {$id} VERIFIED است ولی هیچ آزمونی نام نمی‌برد.",
            );
        }
    }

    public function test_every_open_item_names_the_phase_that_closes_it(): void
    {
        foreach ($this->items() as $id => $item) {
            if (! in_array($this->statusOf($item['status']), ['OPEN', 'NOT VERIFIED'], true)) {
                continue;
            }

            $phase = strtr($item['phase'], self::PERSIAN_DIGITS);
            $this->assertMatchesRegularExpression('/^10\/(?:[2-9]|1[0-6])\b/u', $phase, "بندِ باز {$id} فازِ مرحلهٔ ۱۰ ندارد.");
        }
    }

    public function test_every_test_named_in_the_map_exists(): void
    {
        preg_match_all('/`([A-Z]\w*Test)(?:::(test_\w+))?`/', $this->document(), $matches, PREG_SET_ORDER);
        $this->assertGreaterThan(150, count($matches), 'نقشه تقریباً هیچ آزمونی نام نمی‌برد.');

        foreach ($matches as $m) {
            $class = $this->resolveTestClass($m[1]);
            $this->assertNotNull($class, "آزمونِ «{$m[1]}» در مخزن نیست.");

            if (! empty($m[2])) {
                $this->assertTrue(method_exists($class, $m[2]), "متدِ «{$m[1]}::{$m[2]}» در مخزن نیست.");
            }
        }
    }

    public function test_every_path_named_in_the_map_exists(): void
    {
        preg_match_all('/`((?:app|tests|docs|database|resources)\/[^`\s]+)`/', $this->document(), $matches);
        $this->assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $path) {
            $this->assertFileExists(base_path($path), "مسیرِ «{$path}» ِ نقشه در مخزن نیست.");
        }
    }

    public function test_the_summary_counts_are_recomputed_from_the_matrix(): void
    {
        $counted = array_count_values(array_map(fn (array $item) => (string) $this->statusOf($item['status']), $this->items()));

        $this->assertMatchesRegularExpression('/<!-- tally:start -->(.*)<!-- tally:end -->/su', $this->document());
        preg_match('/<!-- tally:start -->(.*)<!-- tally:end -->/su', $this->document(), $block);
        preg_match_all('/^\|\s*([A-Z][^|]*?)\s*\|\s*(\d+)\s*\|/m', $block[1], $rows, PREG_SET_ORDER);

        $declared = [];
        foreach ($rows as $row) {
            $declared[$row[1]] = (int) $row[2];
        }

        ksort($counted);
        ksort($declared);
        $this->assertSame($counted, $declared, 'جدولِ خلاصه با شمارشِ ماتریس نمی‌خواند.');
        $this->assertSame(144, array_sum($declared));
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
