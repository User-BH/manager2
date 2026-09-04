<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * شانزده گامِ خطِ انتشار واقعاً وجود دارند (R71).
 *
 * ─── چرا این محافظ لازم است ────────────────────────────────────────────────
 * ⚠️ سندِ خطِ انتشار به‌سادگی می‌تواند از واقعیت جدا بیفتد: کسی گامی را از
 * workflow برمی‌دارد و سند همچنان می‌گوید هست. آن‌وقت روزِ حادثه، تیم روی
 * دروازه‌ای حساب می‌کند که وجود ندارد.
 *
 * پس هر گام به **رشته‌ای در خودِ فایلِ workflow** گره خورده و اینجا سنجیده
 * می‌شود.
 *
 * ─── ⚠️ دو مرزِ این محافظ، صریح ────────────────────────────────────────────
 * ① ثابت نمی‌کند گام **درست کار می‌کند** — `deploy.yml` هرگز روی سرورِ
 *   واقعی اجرا نشده و این در سند هم نوشته شده. اینجا فقط تضمین می‌شود که
 *   گامی بی‌سروصدا **حذف** نشود.
 *
 * ② 🔴 **کامنت‌کردنِ عمدیِ یک خط را نمی‌گیرد.** خرابکاری این را نشان داد:
 *   `true # gzip -t "$file"` از تور رد می‌شود.
 *
 *   و این مرز با رگولار‌اکسپرشنِ باهوش‌تر بسته نمی‌شود: پاک‌کردنِ کامنتِ
 *   انتهای خط در بلوکِ shell امن نیست، چون `#` جداکننده‌ی معتبرِ `sed` هم
 *   هست و در همین فایل به‌کار رفته (`sed 's#/up/?$##'`). تشخیصِ درستش
 *   تجزیه‌گرِ shell می‌خواهد، نه تطبیقِ متن.
 *
 *   ⚠️ پس تقسیمِ کار این است: **حذف** (حالتِ تصادفی و بازآرایی) گرفته
 *   می‌شود؛ **کامنت‌کردن** کارِ عمدی‌ای است که در بازبینیِ کد به‌صورتِ یک
 *   `#` دیده می‌شود.
 */
class ReleasePipelineTest extends TestCase
{
    private const DOC = 'docs/RELEASE_PIPELINE.md';

    /**
     * گام ← فایل ← نشانه‌ی وجودش در آن فایل.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function stages(): array
    {
        return [
            '۱ Type Check' => ['.github/workflows/ci.yml', 'npm run typecheck'],
            '۲ Lint (فرانت)' => ['.github/workflows/ci.yml', 'npm run lint'],
            '۲ Lint (بک‌اند)' => ['.github/workflows/ci.yml', 'composer lint'],
            '۳ تا ۶ تست‌های PHP' => ['.github/workflows/ci.yml', 'php artisan test'],
            '۴ تستِ کامپوننت' => ['.github/workflows/ci.yml', 'npm run test'],
            '۷ E2E' => ['.github/workflows/ci.yml', 'npx playwright test'],
            '۷ سه موتور' => ['.github/workflows/ci.yml', 'chromium firefox webkit'],
            '۹ ممیزیِ وابستگیِ PHP' => ['.github/workflows/ci.yml', 'composer audit'],
            '۹ ممیزیِ وابستگیِ Node' => ['.github/workflows/ci.yml', 'npm audit'],
            '۱۰ Build' => ['.github/workflows/ci.yml', 'npm run build'],

            '۱۲ Migration Check' => ['.github/workflows/deploy.yml', 'artisan migrate:check'],
            /*
             * ⚠️ نشانه شاملِ **آرگومان** است، نه فقط نامِ دستور.
             *
             * خرابکاریِ «دستور را با یک # کامنت کن» (`true # gzip -t`) از
             * تورِ نامِ تنها رد می‌شد. فیلترِ کامنت هم نجاتش نمی‌داد، چون
             * کامنتِ انتهای خط را نمی‌شود بی‌خطر پاک کرد: در همین فایل
             * `sed 's#/up/?$##'` هست که # جداکننده‌اش است.
             */
            '۱۳ سنجشِ بکاپ' => ['.github/workflows/deploy.yml', 'gzip -t \\"'],
            '۱۳ دامپ جدول دارد' => ['.github/workflows/deploy.yml', 'CREATE TABLE'],
            '۱۴ انتقال بدونِ حذف' => ['.github/workflows/deploy.yml', 'rsync'],
            '۱۵ Health Check' => ['.github/workflows/deploy.yml', 'HEALTH_URL'],
            '۱۶ تستِ دود' => ['.github/workflows/deploy.yml', '- name: تستِ دود'],
            '۱۶ داراییِ ساخته‌شده سرو می‌شود' => ['.github/workflows/deploy.yml', '/build/assets/'],
            'بازگشت' => ['.github/workflows/deploy.yml', '- name: بازگشت به نسخه‌ی قبل'],
        ];
    }

    #[DataProvider('stages')]
    public function test_every_stage_exists_in_the_workflow(string $file, string $needle): void
    {
        $this->assertStringContainsString(
            $needle,
            $this->executablePartOf($file),
            "گامِ خطِ انتشار در «{$file}» پیدا نشد.\n"
            .'⚠️ یا گام حذف شده، یا سند و کد از هم جدا افتاده‌اند.',
        );
    }

    /**
     * 🔴 کامنت‌های YAML **پیش از** سنجش پاک می‌شوند.
     *
     * ─── چرا، و بارِ چندم ──────────────────────────────────────────────────
     * ⚠️ این فایل‌ها پرکامنت‌اند و کامنت‌ها دقیقاً همان دستورها را نقل
     * می‌کنند («⚠️ `migrate:check` هم پیش از `migrate` می‌آید…»).
     *
     * خرابکاری نشان داد سه گام از تور رد می‌شوند: برداشتنِ خودِ دستور
     * تست را قرمز نمی‌کرد، چون همان رشته در توضیحِ بالای آن دستور بود.
     *
     * همان تله‌ی تکرارشونده‌ی این پروژه (R52، R67، R69): **متنی که مثلِ
     * کد به نظر می‌رسد ولی اجرا نمی‌شود.**
     */
    private function executablePartOf(string $file): string
    {
        $lines = preg_split('/\R/u', (string) file_get_contents(base_path($file))) ?: [];

        $code = array_filter(
            $lines,
            // خطی که پس از فاصله‌ها با # شروع شود، کامنت است
            fn (string $line): bool => preg_match('/^\s*#/', $line) !== 1,
        );

        return implode("\n", $code);
    }

    /**
     * 🔴 گامِ ۱۱ (Performance Check) با نامِ خودش در workflow نیست — چون
     * داخلِ همان اجرای تست‌ها انجام می‌شود.
     *
     * ⚠️ پس اینجا از **وجودِ تست‌های بودجه** مطمئن می‌شویم، نه از یک گامِ
     * جدا. اجرای دوباره‌شان فقط زمانِ CI را می‌خورد و چیزی اضافه نمی‌کند.
     */
    public function test_the_performance_budgets_are_real_tests(): void
    {
        foreach ([
            'tests/Feature/PerformanceBaselineTest.php',
            'tests/Feature/AssetPerformanceTest.php',
            'tests/Feature/QueryProfileTest.php',
            'tests/js/bundleBudget.test.ts',
        ] as $path) {
            $this->assertFileExists(base_path($path), "بودجه‌ی کارایی «{$path}» وجود ندارد.");
        }
    }

    /** ⚠️ سند باید هر شانزده گام را نام ببرد، وگرنه خواننده گامی را جا می‌اندازد. */
    public function test_the_document_lists_every_stage_number(): void
    {
        $doc = (string) file_get_contents(base_path(self::DOC));

        /*
         * ⚠️ شماره‌ها از **خانه‌ی اولِ جدول** بیرون کشیده می‌شوند، نه با
         * تطبیقِ رشته.
         *
         * نسخه‌ی اول فاصله‌ها را دستی نرمال می‌کرد و روی «۱۰» شکست — چون
         * شماره‌ی دورقمی padding متفاوتی می‌گیرد. تستی که به قالب‌بندیِ
         * Markdown حساس باشد، با اولین اجرای Prettier می‌شکند.
         */
        preg_match_all('/^\|\s*([۰-۹]{1,2})\s*\|/mu', $doc, $matches);

        $listed = array_unique($matches[1]);
        $expected = ['۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '۱۰', '۱۱', '۱۲', '۱۳', '۱۴', '۱۵', '۱۶'];
        $missing = array_values(array_diff($expected, $listed));

        $this->assertSame([], $missing, 'این شماره‌های گام در سند نیستند: '.implode('، ', $missing));
    }

    /**
     * 🔴 استقرار نباید خودکار شود.
     *
     * ⚠️ وصل‌کردنش به `push` یعنی هر merge — حتی یک اصلاحِ تایپی — مهاجرت
     * روی دیتابیسِ محصول اجرا کند. این محافظ جلوی «فقط یک خط اضافه کنیم»
     * را می‌گیرد.
     */
    public function test_deployment_stays_manual(): void
    {
        $source = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));

        $this->assertStringContainsString('workflow_dispatch', $source);

        // فقط بخشِ محرک سنجیده می‌شود، نه کلِ فایل (کلمه در کامنت هم هست)
        $trigger = substr($source, strpos($source, "\non:") ?: 0, 400);

        foreach (['push:', 'pull_request:', 'schedule:'] as $automatic) {
            $this->assertStringNotContainsString(
                $automatic,
                $trigger,
                "استقرار با «{$automatic}» خودکار شده است.",
            );
        }
    }

    /**
     * ⚠️ `--delete` در `rsync` یعنی هر چیزی که روی سرور هست و در بسته
     * نیست پاک شود — از جمله `storage/app` که رسیدها و پیوست‌های کاربران
     * در آن است.
     */
    public function test_the_sync_never_deletes_server_files(): void
    {
        $source = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));
        $code = (string) preg_replace('/^\s*#.*$/m', '', $source);

        $this->assertStringNotContainsString('--delete', $code, 'rsync با --delete فایل‌های کاربران را پاک می‌کند.');
    }
}
