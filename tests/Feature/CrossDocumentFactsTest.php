<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * یک واقعیت، یک عدد — در **همهٔ** اسناد (فاز ۹).
 *
 * ─── 🔴 نقصی که این فایل را ساخت ───────────────────────────────────────────
 * فاز ۰ جدولِ بارِ polling را از خودِ کد بازسازی کرد و `DocumentedNumbersTest`
 * نگهبانش شد. ولی **متنِ بالای همان جدول** دو جا می‌گفت «هر ۳۰ ثانیه»، در
 * حالی که `POLL_MS` از روزِ اول ۶۰۰۰۰ بوده و هرگز ۳۰ ثانیه نبوده.
 *
 * ⚠️ یعنی سند در جدولش درست بود و در روایتش غلط، و هر محافظی که داشتیم
 * فقط جدول را می‌خواند. همان‌طور `NOT_VERIFIED` می‌گفت «۱۵ گام» در حالی که
 * `ReleasePipelineTest` شانزده گام را می‌سنجید.
 *
 * ─── چرا اسکنِ همهٔ اسناد، و نه چند فایلِ نام‌برده ────────────────────────
 * 🔴 چون عددِ غلط دقیقاً جایی می‌نشیند که کسی نگاهش نمی‌کند. محافظی که
 * فهرستِ فایل داشته باشد، فایلِ بعدی را از قلم می‌اندازد.
 *
 * ─── مرزِ صریح ─────────────────────────────────────────────────────────────
 * ⚠️ این تست فقط **عددهایی با شکلِ مشخص** را می‌سنجد. ادعای بی‌عدد یا
 * جمله‌ی غلطِ توصیفی از تورش رد می‌شود؛ ابزارِ آن، خواندنِ آدم است.
 */
class CrossDocumentFactsTest extends TestCase
{
    /**
     * 🔴 عددِ درست از **کد** می‌آید، نه از یک ثابتِ نوشته‌شده در همین تست.
     *
     * ⚠️ اگر عدد را اینجا دستی می‌نوشتم، این فایل هم یکی دیگر از همان
     * سندهایی می‌شد که باید نگهبانی می‌شدند.
     */
    private function releaseStageCount(): int
    {
        $highest = 0;

        foreach (array_keys(ReleasePipelineTest::stages()) as $stage) {
            if (preg_match('/^([۰-۹]+)/u', (string) $stage, $m) === 1) {
                $highest = max($highest, (int) $this->latin($m[1]));
            }
        }

        $this->assertGreaterThan(0, $highest, 'شمارهٔ گامی از نقشهٔ خطِ انتشار خوانده نشد.');

        return $highest;
    }

    /**
     * فاصله‌های واقعیِ polling بر حسبِ **ثانیه**، از خودِ کد.
     *
     * @return list<int>
     */
    private function pollingSeconds(): array
    {
        $sources = [
            'resources/js/shared/stores/notificationStore.ts' => '/const POLL_MS = ([0-9_]+)/',
            'resources/js/features/messaging/messenger/ConversationThread.tsx' => '/const POLL_INTERVAL = ([0-9_]+)/',
            // «چند نکته»، بندِ ۳: فهرستِ گفت‌وگوها و پنلِ بازِ اعضا (حضور)
            'resources/js/features/messaging/messenger/MessengerPage.tsx' => '/const LIST_POLL_MS = ([0-9_]+)/',
            'resources/js/features/messaging/messenger/GroupInfoPanel.tsx' => '/const INFO_REFRESH_MS = ([0-9_]+)/',
            'resources/js/features/settings/ComplexBackupPage.tsx' => "/'pending'\\) \\? ([0-9_]+)/",
        ];

        $seconds = [];

        foreach ($sources as $path => $pattern) {
            $source = (string) file_get_contents(base_path($path));

            $this->assertSame(
                1,
                preg_match($pattern, $source, $m),
                "فاصلهٔ polling در «{$path}» پیدا نشد؛ الگو کهنه شده و محافظ پوچ می‌شود.",
            );

            $seconds[] = (int) ((int) str_replace('_', '', $m[1]) / 1000);
        }

        sort($seconds);

        return $seconds;
    }

    /**
     * ⚠️ هر ادعای «هر N ثانیه» باید یکی از فاصله‌های واقعی باشد.
     *
     * 🔴 این تست نمی‌داند کدام جمله دربارهٔ کدام سازوکار است — و لازم هم
     * ندارد. کافی است عددی که به‌عنوانِ فاصله نوشته شده، **در کد وجود
     * داشته باشد**. «۳۰ ثانیه» در هیچ‌کجای کد نبود، و همین گرفتنش را ممکن
     * می‌کرد.
     */
    public function test_no_document_claims_a_polling_interval_that_does_not_exist(): void
    {
        $real = $this->pollingSeconds();
        $offenders = [];
        $seen = 0;

        foreach ($this->documents() as $path => $body) {
            foreach ($this->claimsIn($body, '/هر ([۰-۹]+) ثانیه/u') as $digits) {
                $seen++;

                if (! in_array((int) $this->latin($digits), $real, true)) {
                    $offenders[] = "{$path}: «هر {$digits} ثانیه»";
                }
            }
        }

        $this->assertGreaterThan(0, $seen, 'هیچ ادعای فاصله‌ای خوانده نشد؛ محافظ پوچ می‌شود.');

        $this->assertSame(
            [],
            $offenders,
            '🔴 فاصله‌ای ادعا شده که در کد نیست (فاصله‌های واقعی: '.implode('، ', $real)." ثانیه):\n  "
            .implode("\n  ", $offenders),
        );
    }

    /**
     * ⚠️ شمارِ گام‌های خطِ انتشار در هر سندی باید همان عددی باشد که
     * `ReleasePipelineTest` واقعاً می‌سنجد.
     *
     * ─── 🔴 چرا `(?!ِ)` در الگو هست ────────────────────────────────────────
     * ⚠️ نسخهٔ اولِ این تست یک موردِ **درست** را هم قرمز کرد:
     * «نحوِ shellِ هر ۱۲ گامِ `deploy.yml`». آن جمله دربارهٔ خطِ انتشارِ
     * شانزده‌گامی نیست — دربارهٔ گام‌های خودِ آن فایل است، و ۱۲ هم درست است.
     *
     * کسرهٔ اضافه همان تفاوت را می‌سازد: «گامِ X» یعنی گامِ **آن چیز**، ولی
     * «گام» تنها یعنی کلِ خطِ انتشار. 🔴 عریض‌کردنِ تور تا وقتی موردِ درست را
     * بگیرد، محافظ را به منبعِ نویز تبدیل می‌کند و آخرش کسی خاموشش می‌کند.
     */
    public function test_every_document_agrees_on_the_number_of_release_stages(): void
    {
        $real = $this->releaseStageCount();
        $offenders = [];
        $seen = 0;

        foreach ($this->documents() as $path => $body) {
            foreach ($this->claimsIn($body, '/(?:خطِ انتشارِ|هر) ([۰-۹]+) گام(?!ِ)/u') as $digits) {
                $seen++;

                if ((int) $this->latin($digits) !== $real) {
                    $offenders[] = "{$path}: «{$digits} گام»";
                }
            }
        }

        $this->assertGreaterThan(0, $seen, 'هیچ ادعایی دربارهٔ گام‌ها خوانده نشد؛ محافظ پوچ می‌شود.');

        $this->assertSame(
            [],
            $offenders,
            "🔴 خطِ انتشار {$real} گام دارد، ولی این اسناد عددِ دیگری نوشته‌اند:\n  "
            .implode("\n  ", $offenders),
        );
    }

    /**
     * ارقامِ هر **ادعا** در یک سند — و نه هر نقلِ‌قول.
     *
     * ─── 🔴 چرا نقلِ‌قول استثنا است ────────────────────────────────────────
     * ⚠️ سندی که خطای اصلاح‌شده‌ای را ثبت می‌کند، ناچار است **نامش را
     * ببرد**: «هر ۳۰ ثانیه» دقیقاً همان جمله‌ای است که غلط بود. اگر محافظ
     * این را هم قرمز کند، پروژه توانِ نوشتنِ گزارشِ صادقانه را از دست
     * می‌دهد و راهِ سبزشدن، **پاک‌کردنِ تاریخچه** می‌شود.
     *
     * پس فقط وقتی رد می‌شود که گیومه **دقیقاً دورِ خودِ عبارت** باشد.
     *
     * 🔴 و مرزش را صریح بگویم: بله، می‌شود ادعای غلط را در گیومه گذاشت و از
     * تور رد شد. ولی آن دیگر لغزش نیست، **پنهان‌کاریِ عمدی** است — و محافظِ
     * خودکار ابزارِ درستی برای گرفتنِ آن نیست.
     *
     * @return list<string>
     */
    private function claimsIn(string $body, string $pattern): array
    {
        preg_match_all($pattern, $body, $matches, PREG_OFFSET_CAPTURE);

        $claims = [];

        foreach ($matches[0] as $index => [$whole, $offset]) {
            $before = mb_substr(substr($body, 0, $offset), -1);
            $after = mb_substr(substr($body, $offset + strlen($whole)), 0, 1);

            if ($before === '«' && $after === '»') {
                continue;
            }

            $claims[] = $matches[1][$index][0];
        }

        return $claims;
    }

    /**
     * همهٔ اسنادِ `docs/`.
     *
     * @return array<string, string>
     */
    private function documents(): array
    {
        $documents = [];

        foreach ((array) glob(base_path('docs').DIRECTORY_SEPARATOR.'*.md') as $file) {
            if (is_string($file)) {
                $documents[basename($file)] = (string) file_get_contents($file);
            }
        }

        $this->assertNotEmpty($documents, 'هیچ سندی خوانده نشد؛ محافظ پوچ می‌شود.');

        return $documents;
    }

    /** ارقامِ فارسی به لاتین. */
    private function latin(string $digits): string
    {
        return str_replace(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $digits,
        );
    }
}
