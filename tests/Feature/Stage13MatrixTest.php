<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * نگهبانِ ماتریسِ الزاماتِ مرحلهٔ ۱۳ (۱۳/۹-الف؛ docs/STAGE13_ROADMAP.md بخشِ ۵ و docs/STAGE13_RECONCILIATION.md).
 *
 * «صرفِ وجودِ نامِ یک تست، کامیت یا عبارتِ قبلاً انجام شده برای VERIFIED کافی نیست» — پس این آزمون:
 *   ① برای هر ردیفِ جدول‌های وضعیتِ بخشِ ۵ دقیقاً یکی از پنج وضعیت را می‌خواهد، بی قیدِ کهنه («پس از push»)؛
 *   ② هر VERIFIED را بی آزمونِ نام‌برده رد می‌کند، و هر آزمون، متد، فایلِ رابط و سفرِ مرورگرِ نام‌برده را با مخزن می‌سنجد؛
 *   ③ هر OPEN را بی دلیل و برنامه (فاز) رد می‌کند؛
 *   ④ هر ردیفِ «جایگزین‌شده» را بی شناسه‌ی تصمیمی که واقعاً در نقشه‌ی راه هست رد می‌کند؛
 *   ⑤ سندِ آشتی را با ماتریس می‌خواند: هر ردیفِ آشتی‌شده همان وضعیتِ ماتریس را دارد، شواهد و نتیجه‌ی اجرا دارد، و جدولِ
 *      شمارش از خودِ ردیف‌ها شمرده می‌شود — تغییرِ وضعیت به‌تنهایی الزام را کامل نمی‌کند.
 */
class Stage13MatrixTest extends TestCase
{
    private const ROADMAP = 'docs/STAGE13_ROADMAP.md';

    private const RECONCILIATION = 'docs/STAGE13_RECONCILIATION.md';

    private const DOD = 'docs/STAGE13_DOD.md';

    /** شمارِ بندهای هر بخشِ سندِ DoD، از خودِ اسنادِ کارفرما — بندِ افتاده یا ادغام‌شده همین‌جا گرفته می‌شود */
    private const DOD_SECTIONS = ['۱' => 58, '۲' => 9, '۳' => 12, '۴' => 31, '۵' => 13, '۶' => 5, '۷' => 47];

    /** ترتیبِ تجمیع: بدترین وضعیتِ بخش، وضعیتِ ردیفِ ماتریسِ آن است؛ بندِ جایگزین‌شده خنثی */
    private const SEVERITY = [
        'OPEN' => 4,
        'BLOCKED BY EXTERNAL ENVIRONMENT' => 3,
        'IMPLEMENTATION READY — ENVIRONMENT VERIFICATION REQUIRED' => 2,
        'VERIFIED' => 1,
    ];

    public const STATUSES = [
        'IMPLEMENTATION READY — ENVIRONMENT VERIFICATION REQUIRED',
        'INTENTIONALLY NOT BUILT',
        'BLOCKED BY EXTERNAL ENVIRONMENT',
        'VERIFIED',
        'OPEN',
    ];

    /** قیدهایی که وضعیت را مشروط یا کهنه می‌کنند — یا وضعیتِ ششمی که در فهرست نیست */
    private const STALE = ['پس از push', 'پیش از تغییر', 'CODE READY', 'محیطی', 'ENVIRONMENT VERIFIED', 'PRODUCTION READY'];

    /** @return list<array{line: int, section: string, key: string, text: string, status: string}> */
    private function statusRows(): array
    {
        $rows = [];
        foreach ($this->tables(self::ROADMAP) as $table) {
            if (! str_starts_with($table['section'], '۵.') || end($table['header']) !== 'وضعیت') {
                continue;
            }
            foreach ($table['rows'] as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array<string, array{heading: string, rows: list<array{line: int, section: string, key: string, text: string, status: string}>}> */
    private function dodSections(): array
    {
        $sections = [];
        foreach ($this->tables(self::DOD) as $table) {
            if (preg_match('/^([۰-۹]+)\. /u', $table['section'], $m)) {
                $sections[$m[1]] = ['heading' => $table['section'], 'rows' => [...($sections[$m[1]]['rows'] ?? []), ...$table['rows']]];
            }
        }

        return $sections;
    }

    /** @return list<array{line: int, section: string, key: string, text: string, status: string}> */
    private function dodRows(): array
    {
        return array_merge(...array_values(array_map(fn (array $s) => $s['rows'], $this->dodSections())));
    }

    public function test_the_matrix_is_parsed(): void
    {
        // ⚠️ بی این، خرابیِ خواندنِ جدول‌ها نگهبان را بی‌صدا پوچ می‌کرد
        $this->assertGreaterThan(110, count($this->statusRows()));
    }

    /** ۱۳/۹-ب: هر بندِ چهار DoD یک ردیف دارد — نه کمتر، نه ادغام‌شده */
    public function test_every_item_of_the_four_definitions_of_done_has_its_own_row(): void
    {
        $sections = $this->dodSections();
        $this->assertSame(array_keys(self::DOD_SECTIONS), array_keys($sections), 'بخش‌های سندِ DoD عوض شده‌اند.');
        foreach (self::DOD_SECTIONS as $n => $count) {
            $this->assertCount($count, $sections[$n]['rows'], "بخشِ {$n} ِ سندِ DoD باید {$count} بند داشته باشد.");
            $this->assertSame(count($sections[$n]['rows']), count(array_unique(array_column($sections[$n]['rows'], 'key'))), "بخشِ {$n}: شماره‌ی بند تکراری است.");
        }
    }

    /** وضعیتِ ردیفِ ماتریس از بندهای DoD ِ همان بخش می‌آید؛ تغییرِ وضعیتِ ماتریس به‌تنهایی DoD را کامل نمی‌کند */
    public function test_a_definition_of_done_sets_the_status_of_its_matrix_row(): void
    {
        $linked = 0;
        foreach ($this->dodSections() as $n => $section) {
            if (! preg_match('/ماتریس: (§[۰-۹.]+ · .+?)\)$/u', $section['heading'], $m)) {
                continue;
            }
            $linked++;
            $worst = 'VERIFIED';
            foreach ($section['rows'] as $row) {
                $status = $this->leadingStatus($row['status']);
                if (isset(self::SEVERITY[$status]) && self::SEVERITY[$status] > self::SEVERITY[$worst]) {
                    $worst = $status;
                }
            }
            $matrix = $this->matrixRowFor($m[1]);
            $this->assertNotNull($matrix, "بخشِ {$n}: ردیفِ «{$m[1]}» در نقشه‌ی راه نیست.");
            $this->assertSame($worst, $this->leadingStatus($matrix['status']), "بخشِ {$n}: ردیفِ ماتریسِ «{$m[1]}» باید {$worst} باشد.");
        }
        $this->assertSame(5, $linked, 'پنج بخشِ DoD به ردیفِ ماتریس گره خورده‌اند.');
    }

    /** سندِ آزمایشی بندِ ۸.۵: عددِ coverage فقط با اندازه‌گیریِ واقعی — هیچ سندِ مرحلهٔ ۱۳ چنین عددی ندارد */
    public function test_no_coverage_number_is_claimed(): void
    {
        foreach ([...glob(base_path('docs/STAGE13_*.md')) ?: [], base_path('README.md')] as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/(coverage|پوشش)[^\n|]{0,30}[0-9۰-۹]+(?:[.٫][0-9۰-۹]+)?\s*[%٪]|[0-9۰-۹]+\s*[%٪][^\n|]{0,20}(coverage|پوشش)/iu',
                (string) file_get_contents($file),
                basename($file).' عددِ coverage ادعا می‌کند.',
            );
        }
    }

    public function test_every_row_carries_exactly_one_of_the_five_statuses_without_stale_qualifiers(): void
    {
        foreach ([...$this->statusRows(), ...$this->dodRows()] as $row) {
            $where = "ردیفِ {$row['key']} (سطرِ {$row['line']})";
            $this->assertSame(1, $this->tokenCount($row['status']), "{$where}: دقیقاً یکی از پنج وضعیت نیست — «{$row['status']}».");
            $this->assertNotNull($this->leadingStatus($row['status']), "{$where}: وضعیت با یکی از پنج برچسب شروع نمی‌شود — «{$row['status']}».");
            foreach (self::STALE as $stale) {
                $this->assertStringNotContainsString($stale, $row['status'], "{$where}: قیدِ «{$stale}» وضعیت را کهنه یا مشروط می‌کند.");
            }
        }
    }

    public function test_nothing_is_verified_without_an_existing_named_test(): void
    {
        foreach ([...$this->statusRows(), ...$this->dodRows()] as $row) {
            if ($this->leadingStatus($row['status']) !== 'VERIFIED') {
                continue;
            }
            $this->assertNotSame([], $this->namedTests($row['text']), "ردیفِ {$row['key']} (سطرِ {$row['line']}) VERIFIED است ولی آزمونی نام نمی‌برد.");
        }
    }

    public function test_every_test_and_file_named_in_the_matrix_and_the_reconciliation_exists(): void
    {
        foreach ([self::ROADMAP, self::RECONCILIATION, self::DOD] as $doc) {
            $text = $this->read($doc);
            $this->assertDoesNotMatchRegularExpression('/`[A-Za-z_:.\/-]*(?:Test|test_\w*|\.tsx?|\.ts)…`?/u', $text, "{$doc}: نامِ آزمونِ بریده‌شده («…») قابلِ سنجش نیست.");
            foreach ($this->namedTests($text) as $reference) {
                $this->assertReferenceExists($reference, $doc);
            }
            preg_match_all('/\]\(([A-Z0-9_]+\.md)\)/', $text, $links);
            foreach (array_unique($links[1]) as $file) {
                $this->assertFileExists(base_path('docs/'.$file), "{$doc}: پیوندِ «{$file}» در مخزن نیست.");
            }
        }
    }

    public function test_an_open_row_carries_its_reason_and_plan(): void
    {
        foreach ([...$this->statusRows(), ...$this->dodRows()] as $row) {
            if ($this->leadingStatus($row['status']) === 'OPEN') {
                $this->assertMatchesRegularExpression('/^OPEN \(→ (?:۱۳\/۹-(?:الف|ب|ج|د|ه|و|ز)|مرحلهٔ ۱۴)[^)]*\)/u', $row['status'], "ردیفِ OPEN ِ {$row['key']} (سطرِ {$row['line']}) دلیل و برنامه ندارد.");
            }
        }
    }

    public function test_a_superseded_row_cites_a_decision_that_exists(): void
    {
        $known = $this->decisionIds();
        $this->assertContains('۲.۳-۱', $known);
        $this->assertContains('پ-۱', $known);

        foreach ([...$this->statusRows(), ...$this->dodRows(), ...$this->reconciliation()] as $row) {
            if (! str_contains($row['text'], 'جایگزین')) {
                continue;
            }
            preg_match_all('/(۲\.۳-[۰-۹]+|[پتد]-[۰-۹]+)/u', $row['text'], $cited);
            $this->assertNotSame([], $cited[1], "ردیفِ جایگزین‌شده‌ی {$row['key']} شناسه‌ی تصمیم ندارد.");
            foreach ($cited[1] as $id) {
                $this->assertContains($id, $known, "ردیفِ {$row['key']} به تصمیمِ ناموجودِ «{$id}» ارجاع می‌دهد.");
            }
        }
    }

    /** ⑤ آشتی: ۴۱ ردیف، هر کدام با شواهد و اجرا، و وضعیتِ هر کدام همان وضعیتِ ماتریس */
    public function test_the_reconciliation_matches_the_matrix_row_by_row(): void
    {
        $entries = $this->reconciliation();
        $this->assertCount(41, $entries, 'سندِ آشتی باید دقیقاً ۴۱ ردیفِ بازِ آغازِ ۱۳/۹ را داشته باشد.');

        foreach ($entries as $entry) {
            $id = $entry['key'];
            foreach (['جای', 'الزام', 'وضعیتِ پیشین', 'پیاده‌سازی', 'آزمون و نتیجه', 'کامیت', 'محیط', 'وضعیتِ نهایی', 'دلیل'] as $field) {
                $this->assertNotSame('', trim($entry['fields'][$field] ?? ''), "{$id}: فیلدِ «{$field}» خالی است.");
            }
            $final = $this->leadingStatus($entry['fields']['وضعیتِ نهایی']);
            $this->assertNotNull($final, "{$id}: وضعیتِ نهایی یکی از پنج وضعیت نیست.");
            if ($final === 'VERIFIED') {
                $this->assertNotSame([], $this->namedTests($entry['fields']['آزمون و نتیجه']), "{$id}: VERIFIED بی آزمونِ نام‌برده.");
                $this->assertMatchesRegularExpression('/(قبول|PASS)/u', $entry['fields']['آزمون و نتیجه'], "{$id}: نتیجه‌ی اجرای آزمون ثبت نشده است.");
            }

            $row = $this->matrixRowFor($entry['fields']['جای']);
            $this->assertNotNull($row, "{$id}: ردیفِ «{$entry['fields']['جای']}» در نقشه‌ی راه پیدا نشد.");
            $this->assertSame(1, $this->tokenCount($row['status']), "{$id}: ردیفِ ماتریس دقیقاً یک وضعیت ندارد.");
            $this->assertSame($final, $this->leadingStatus($row['status']) ?? $this->firstToken($row['status']), "{$id}: وضعیتِ ماتریس با سندِ آشتی نمی‌خواند.");
        }
    }

    public function test_the_reconciliation_count_table_is_recomputed_from_the_entries(): void
    {
        $counted = array_count_values(array_map(fn (array $e) => $this->leadingStatus($e['fields']['وضعیتِ نهایی']), $this->reconciliation()));
        $digits = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];

        foreach (self::STATUSES as $status) {
            $this->assertMatchesRegularExpression('/^\| \*\*'.preg_quote($status, '/').'\*\*\s*\| \*\*([۰-۹]+)\*\*/mu', $this->read(self::RECONCILIATION), "شمارشِ «{$status}» در جدول نیست.");
            preg_match('/^\| \*\*'.preg_quote($status, '/').'\*\*\s*\| \*\*([۰-۹]+)\*\*/mu', $this->read(self::RECONCILIATION), $m);
            $this->assertSame($counted[$status] ?? 0, (int) strtr($m[1], $digits), "شمارشِ «{$status}» با ردیف‌ها نمی‌خواند.");
        }
    }

    /* ── خواندن ─────────────────────────────────────────────────────────────── */

    private function read(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    /** @return list<array{section: string, header: list<string>, rows: list<array{line: int, section: string, key: string, text: string, status: string}>}> */
    private function tables(string $path): array
    {
        $tables = [];
        $section = '';
        $current = null;
        foreach (explode("\n", $this->read($path)) as $i => $line) {
            if (str_starts_with($line, '#')) {
                $section = trim($line, "# \t");
                $current = null;

                continue;
            }
            if (! str_starts_with($line, '|')) {
                $current = null;

                continue;
            }
            if (preg_match('/^\|[\s|:-]+\|?\s*$/', $line)) {
                continue;
            }
            $cells = array_map(fn (string $c) => trim((string) preg_replace('/\s+/u', ' ', $c)), explode('|', trim(trim($line), '|')));
            if ($current === null) {
                $tables[] = ['section' => $section, 'header' => $cells, 'rows' => []];
                $current = array_key_last($tables);

                continue;
            }
            $tables[$current]['rows'][] = [
                'line' => $i + 1, 'section' => $section, 'key' => $cells[0], 'text' => implode(' | ', $cells), 'status' => (string) end($cells),
            ];
        }

        return $tables;
    }

    /** ردیفِ ماتریس از «§۵.۵ · ۱۰۵» (شماره‌ی بخش · خانه‌ی اولِ ردیف) */
    private function matrixRowFor(string $where): ?array
    {
        if (! preg_match('/^§([۰-۹.]+) · (.+)$/u', trim($where), $m)) {
            return null;
        }
        foreach ($this->tables(self::ROADMAP) as $table) {
            if (! str_starts_with($table['section'], $m[1].' ')) {
                continue;
            }
            foreach ($table['rows'] as $row) {
                if ($row['key'] === trim($m[2])) {
                    // جدول‌هایی که ستونِ «وضعیت» ندارند (بخشِ ۳): وضعیت درونِ متنِ ردیف است
                    return end($table['header']) === 'وضعیت' || str_starts_with($table['section'], '۶.') ? $row : [...$row, 'status' => $row['text']];
                }
            }
        }

        return null;
    }

    /** @return list<array{key: string, text: string, fields: array<string, string>}> */
    private function reconciliation(): array
    {
        $entries = [];
        $current = null;
        foreach (explode("\n", $this->read(self::RECONCILIATION)) as $line) {
            if (preg_match('/^### (R[۰-۹]{2})/u', $line, $m)) {
                $entries[] = ['key' => $m[1], 'text' => $line, 'fields' => []];
                $current = array_key_last($entries);

                continue;
            }
            if (str_starts_with($line, '## ')) {
                $current = null;
            }
            if ($current !== null && preg_match('/^\| ([^|]+?) \| (.*) \|\s*$/u', $line, $m) && ! in_array(trim($m[1]), ['فیلد', '---', '-'], true)) {
                $entries[$current]['fields'][trim($m[1])] = trim($m[2]);
                $entries[$current]['text'] .= ' '.$m[2];
            }
        }

        return $entries;
    }

    /** @return list<string> شناسه‌های تصمیم‌هایی که در نقشه‌ی راه تعریف شده‌اند */
    private function decisionIds(): array
    {
        $doc = $this->read(self::ROADMAP);
        $ids = [];
        if (preg_match('/^### ۲\.۳ .*?\n(.*?)\n## /msu', $doc, $section)) {
            preg_match_all('/^([0-9]+)\. /mu', $section[1], $items);
            foreach ($items[1] as $n) {
                $ids[] = '۲.۳-'.strtr($n, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
            }
        }
        preg_match_all('/^\| ([پتد]-[۰-۹]+)\b/mu', $doc, $rows);
        preg_match_all('/\*\*(د-[۰-۹]+) /u', $doc, $decisions);

        return array_values(array_unique([...$ids, ...$rows[1], ...$decisions[1]]));
    }

    private function tokenCount(string $status): int
    {
        $rest = $status;
        $count = 0;
        foreach (self::STATUSES as $token) {
            $count += substr_count($rest, $token);
            $rest = str_replace($token, '', $rest);
        }

        return $count;
    }

    private function leadingStatus(string $status): ?string
    {
        foreach (self::STATUSES as $token) {
            if (str_starts_with(trim($status), $token)) {
                return $token;
            }
        }

        return null;
    }

    private function firstToken(string $text): ?string
    {
        foreach (self::STATUSES as $token) {
            if (str_contains($text, $token)) {
                return $token;
            }
        }

        return null;
    }

    /** @return list<string> آزمون‌های نام‌برده: `XTest`، `XTest::test_y`، `x.test.ts(x)`، `x.spec.ts` */
    private function namedTests(string $text): array
    {
        preg_match_all('/`((?:[A-Z]\w*Test)(?:::test_\w+)?|[\w\/.-]*\.test\.tsx?|[\w\/.-]*\.spec\.ts|tests\/[\w\/.-]+\.php)`/u', $text, $m);

        return array_values(array_unique($m[1]));
    }

    private function assertReferenceExists(string $reference, string $doc): void
    {
        if (str_ends_with($reference, '.spec.ts')) {
            $this->assertFileExists(base_path('tests/e2e/'.basename($reference)), "{$doc}: سفرِ «{$reference}» نیست.");

            return;
        }
        if (preg_match('/\.test\.tsx?$/', $reference)) {
            $this->assertFileExists(base_path('tests/js/'.basename($reference)), "{$doc}: آزمونِ رابطِ «{$reference}» نیست.");

            return;
        }
        if (str_starts_with($reference, 'tests/')) {
            $this->assertFileExists(base_path($reference), "{$doc}: «{$reference}» نیست.");

            return;
        }

        [$class, $method] = array_pad(explode('::', $reference), 2, null);
        $fqcn = collect(['Feature', 'Unit'])->map(fn (string $suite) => "Tests\\{$suite}\\{$class}")->first(fn (string $c) => class_exists($c));
        $this->assertNotNull($fqcn, "{$doc}: آزمونِ «{$class}» در مخزن نیست.");
        if ($method !== null) {
            $this->assertTrue(method_exists((string) $fqcn, $method), "{$doc}: متدِ «{$reference}» در مخزن نیست.");
        }
    }
}
