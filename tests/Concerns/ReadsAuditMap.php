<?php

namespace Tests\Concerns;

/**
 * خواندن و شمردنِ نقشهٔ ممیزی — یک پیاده‌سازی، دو مصرف‌کننده.
 *
 * ─── 🔴 چرا trait و نه دو نسخه ─────────────────────────────────────────────
 * `FinalReportsTest` اعداد را با واژگانِ **قدیم** (✅/🟡/🔒) می‌سنجد و
 * `FinalPreProductionAuditTest` با واژگانِ **جدید** (VERIFIED/…).
 *
 * ⚠️ اگر هرکدام شمارشگرِ خودش را داشت، می‌شد یکی را عوض کرد و دیگری همچنان
 * سبز بماند — یعنی دقیقاً همان تناقضِ دو سنده‌ای که فاز ۹ برای بستنش است،
 * این‌بار بینِ دو تست.
 */
trait ReadsAuditMap
{
    /** نقشه‌ای که هر دو سند اعدادشان را از آن می‌گیرند. */
    private const AUDIT_MAP = 'docs/STAGE2_AUDIT_MAP.md';

    /** ردیفی که وضعیت ندارد چون اصلاً موردِ قابلِ سنجش نیست. */
    private const RULE_ROW = '—';

    /** نشانه‌های وضعیت، به همان ترتیبی که در نقشه معنا دارند. */
    private const MAP_STATUSES = ['✅', '🟡', '❌', '🔒', '❓'];

    /**
     * 🔴 نگاشتِ نشانهٔ قدیم به برچسبِ جدید — تنها جای تعریفش.
     *
     * ⚠️ ردیف‌های نقشه هنوز نشانه‌ی قدیمی دارند و **عمداً بازنویسی نشدند**:
     * دست‌زدن به ۳۲۵ ردیف برای عوض‌کردنِ یک علامت، ریسکِ خطای دستی می‌سازد
     * بی‌آنکه چیزی را درست‌تر کند. به‌جایش ترجمه اینجا صریح و سنجیده است.
     *
     * @var array<string, string>
     */
    private const LABEL_OF = [
        '✅' => 'VERIFIED',
        '🟡' => 'IMPLEMENTATION READY — ENVIRONMENT VERIFICATION REQUIRED',
        '🔒' => 'INTENTIONALLY NOT BUILT',
        '❌' => 'OPEN',
        '❓' => 'OPEN',
    ];

    private function readDoc(string $path): string
    {
        $full = base_path($path);

        $this->assertFileExists($full, "سندِ «{$path}» وجود ندارد.");

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
    private function tallyAuditMap(): array
    {
        $tally = [];

        foreach (preg_split('/\R/u', $this->readDoc(self::AUDIT_MAP)) ?: [] as $line) {
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

            foreach (self::MAP_STATUSES as $symbol) {
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
            $key = $found ?? self::RULE_ROW;
            $tally[$key] = ($tally[$key] ?? 0) + 1;
        }

        $this->assertNotEmpty($tally, 'هیچ ردیفی از نقشه خوانده نشد؛ محافظ پوچ می‌شود.');

        return $tally;
    }

    /**
     * همان شمارش، ولی با واژگانِ شش‌برچسبیِ مرحلهٔ چهارم.
     *
     * @return array<string, int>
     */
    private function tallyByLabel(): array
    {
        $byLabel = [];

        foreach ($this->tallyAuditMap() as $symbol => $count) {
            $label = self::LABEL_OF[$symbol] ?? 'قاعدهٔ اجرا';
            $byLabel[$label] = ($byLabel[$label] ?? 0) + $count;
        }

        return $byLabel;
    }

    /**
     * عددِ فارسیِ نوشته‌شده در ردیفی که برچسبِ داده‌شده را دارد.
     *
     * ⚠️ ارقام فارسی‌اند و `(int)` رویشان صفر می‌دهد — که یعنی محافظ بی‌صدا
     * پوچ می‌شد. پس صریح تبدیل می‌شوند.
     */
    private function claimedNumberIn(string $document, string $label): int
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

        $this->assertNotNull($latin, "عددی برای «{$label}» پیدا نشد.");

        return (int) str_replace(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', ',', '٬'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '', ''],
            $latin,
        );
    }
}
