<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

/**
 * قراردادهای CI/CD (R42).
 *
 * ─── چرا تستِ PHP برای فایلِ YAML ──────────────────────────────────────────
 * ⚠️ خطای workflow فقط **روی GitHub** دیده می‌شود، آن هم پس از push. یعنی
 * حلقه‌ی بازخورد چند دقیقه‌ای است و هر اشتباه یک کامیتِ اصلاحی می‌خواهد.
 * بدتر: خطای `deploy.yml` تا روزِ استقرار پنهان می‌ماند — دقیقاً بدترین
 * لحظه برای فهمیدنش.
 *
 * این تست‌ها همان‌جایی اجرا می‌شوند که بقیه‌ی دروازه‌ها هستند و پیش از
 * کامیت جواب می‌دهند.
 */
class CiWorkflowTest extends TestCase
{
    /**
     * متنِ workflow، **بدونِ کامنت**.
     *
     * ⚠️ پاک‌سازیِ کامنت اجباری است، نه احتیاط. خودِ همین فایل‌ها در
     * توضیحشان می‌نویسند «`php artisan backup:run` وجود ندارد» و «سقفِ
     * هشدار `--max-warnings=15` است» — و بدونِ حذفِ کامنت، تست روی کدِ
     * کاملاً درست قرمز می‌شود. این تله از R37 تا R41 شش بار تکرار شده.
     */
    private function source(string $name): string
    {
        $path = base_path(".github/workflows/{$name}.yml");

        $this->assertFileExists($path);

        // پایانِ خط یکدست می‌شود: با CRLF، لنگرِ $ در حالتِ /m پیش از

        // نمی‌ایستد و هیچ الگوی خط‌محوری نمی‌خورد
        $raw = str_replace(chr(13).chr(10), chr(10), (string) file_get_contents($path));

        return (string) preg_replace('/^\s*#.*$/m', '', $raw);
    }

    /**
     * بلوکِ یک jobِ مشخص.
     *
     * ⚠️ اینجا عمداً YAML تجزیه **نمی‌شود**: کتابخانه‌ی YAML جزو
     * وابستگی‌های پروژه نیست و پروژه قیدِ «پکیجِ بی‌دلیل نصب نکن» دارد.
     * چیزهایی که این تست‌ها می‌سنجند (نامِ job، `needs`، محرک‌ها) ساختارِ
     * تخت و قابلِ‌اتکایی دارند.
     */
    private function jobBlock(string $workflow, string $job): string
    {
        $source = $this->source($workflow);

        $this->assertSame(
            1,
            preg_match('/^  '.preg_quote($job, '/').':$(.*?)(?=^  \w|\z)/ms', $source, $match),
            "jobِ «{$job}» در «{$workflow}.yml» پیدا نشد.",
        );

        return $match[1];
    }

    /**
     * هر دو فایل باید YAMLِ معتبر باشند.
     *
     * GitHub برای YAMLِ خراب workflow را **بی‌صدا نادیده می‌گیرد** — نه
     * خطایی، نه اجرایی. یعنی فکر می‌کنی دروازه داری و نداری.
     */
    public function test_both_workflows_declare_jobs(): void
    {
        foreach (['ci', 'deploy'] as $name) {
            $this->assertMatchesRegularExpression(
                '/^jobs:$/m',
                $this->source($name),
                "«{$name}.yml» بخشِ jobs ندارد.",
            );
        }
    }

    /**
     * ⚠️ استقرار هرگز نباید با push اجرا شود.
     *
     * ─── چرا این مهم‌ترین تستِ این فایل است ────────────────────────────────
     * وصل‌کردنِ استقرار به `push` یعنی هر merge به `main` — حتی اصلاحِ یک
     * غلطِ تایپی در README — روی دیتابیسِ محصول `migrate` می‌زند. آن تصمیم
     * مالِ کارفرماست، نه مالِ یک قاعده‌ی خودکار؛ و اگر کسی روزی این خط را
     * اضافه کند، باید همین‌جا متوقف شود.
     */
    public function test_deployment_is_manual_only(): void
    {
        $source = $this->source('deploy');

        // بخشِ محرک‌ها: از `on:` تا اولین کلیدِ سطحِ ریشه‌ی بعدی
        $this->assertSame(
            1,
            preg_match('/^on:$(.*?)(?=^\w)/ms', $source, $match),
            'بخشِ محرک‌ها در deploy.yml پیدا نشد.',
        );

        $triggers = $match[1];

        $this->assertMatchesRegularExpression('/^  workflow_dispatch:$/m', $triggers);

        foreach (['push', 'pull_request', 'schedule', 'release'] as $automatic) {
            $this->assertDoesNotMatchRegularExpression(
                '/^  '.$automatic.':/m',
                $triggers,
                "استقرار با «{$automatic}» خودکار می‌شود؛ باید فقط دستی باشد.",
            );
        }
    }

    /**
     * ⚠️ تستِ بک‌اند باید **پس از** بیلدِ فرانت بیاید.
     *
     * ─── اندازه‌گیری، نه حدس ───────────────────────────────────────────────
     * با پاک‌کردنِ `public/build` و اجرای `php artisan test`، خطای
     * `Illuminate\Foundation\Vite::asset("resources/images/hero-building.webp")`
     * گرفته شد: صفحه‌ی فرود از R38 تصویرِ LCP را preload می‌کند و
     * `Vite::asset()` بدونِ manifest می‌ترکد.
     *
     * اگر کسی `needs` را بردارد تا CI موازی و سریع‌تر شود، کلِ تست‌های
     * Feature می‌افتند — و علتش هیچ ربطی به تغییرِ او ندارد.
     */
    public function test_backend_waits_for_the_frontend_build(): void
    {
        $this->assertMatchesRegularExpression(
            '/^    needs: frontend$/m',
            $this->jobBlock('ci', 'backend'),
            'jobِ بک‌اند منتظرِ بیلدِ فرانت نمی‌ماند.',
        );
    }

    /**
     * بک‌اند باید خروجیِ بیلد را **بردارد**، نه دوباره بسازد.
     *
     * دو بیلدِ جدا می‌توانند نتیجه‌ی متفاوت بدهند؛ آن‌وقت تست چیزی را
     * می‌سنجد که مستقر نمی‌شود.
     */
    public function test_backend_reuses_the_built_assets(): void
    {
        $this->assertStringContainsString('download-artifact', $this->source('ci'));
        $this->assertStringContainsString('upload-artifact', $this->source('ci'));
    }

    /**
     * ⚠️ هر دستورِ artisan در workflowها باید واقعاً وجود داشته باشد.
     *
     * ─── باگی که همین تست گرفت ─────────────────────────────────────────────
     * نسخه‌ی اولِ `deploy.yml` بکاپ را با `php artisan backup:run` می‌گرفت و
     * بازگشت را با `backup:restore` — **هیچ‌کدام وجود ندارند**. بکاپِ برنامه
     * از پنلِ ادمین و با `BuildBackupJob` ساخته می‌شود و رابطِ خطِ فرمان
     * ندارد. اگر این تست نبود، اولین استقرار دقیقاً در مرحله‌ی بکاپ
     * می‌افتاد — یعنی بدترین لحظه‌ی ممکن برای فهمیدنش.
     */
    public function test_every_artisan_command_in_the_workflows_exists(): void
    {
        $available = array_keys(app(Kernel::class)->all());

        foreach (['ci', 'deploy'] as $name) {
            preg_match_all('/artisan ([a-z][a-z0-9:_-]*)/', $this->source($name), $matches);

            foreach (array_unique($matches[1]) as $command) {
                $this->assertContains(
                    $command,
                    $available,
                    "دستورِ «php artisan {$command}» در «{$name}.yml» هست ولی وجود ندارد.",
                );
            }
        }
    }

    /**
     * ⚠️ `rsync` نباید `--delete` داشته باشد.
     *
     * با آن، هر چیزی که روی سرور هست و در بسته نیست پاک می‌شود — از جمله
     * `storage/app` که رسیدهای اشتراک و پیوست‌های پیام‌رسانِ کاربران در آن
     * است. آن فایل‌ها هیچ‌جای دیگری نیستند.
     */
    public function test_the_deploy_never_deletes_server_side_files(): void
    {
        $code = $this->source('deploy');

        $this->assertStringNotContainsString('--delete', $code);

        // و صریحاً از انتقالِ این دو جلوگیری می‌شود
        $this->assertStringContainsString("--exclude='.env'", $code);
        $this->assertStringContainsString("--exclude='storage/'", $code);
    }

    /**
     * ⚠️ کلیدِ میزبان باید بررسی شود.
     *
     * `StrictHostKeyChecking=no` هر میزبانی را می‌پذیرد — یعنی اگر کسی
     * بینِ runner و سرور بنشیند، کلیدِ خصوصیِ استقرار و کلِ کد به او
     * می‌رسد و هیچ هشداری هم داده نمی‌شود.
     */
    public function test_the_deploy_verifies_the_host_key(): void
    {
        $code = $this->source('deploy');

        $this->assertStringContainsString('ssh-keyscan', $code);
        $this->assertStringNotContainsString('StrictHostKeyChecking=no', $code);
    }

    /**
     * کلیدِ خصوصی باید در پایان پاک شود، حتی اگر استقرار بشکند.
     */
    public function test_the_private_key_is_always_removed(): void
    {
        $this->assertMatchesRegularExpression(
            '/if:\s*always\(\)\s*\n\s*run:\s*rm -f ~\/\.ssh\/id_deploy/',
            $this->source('deploy'),
            'کلیدِ خصوصی پس از استقرار پاک نمی‌شود.',
        );
    }

    /**
     * ⚠️ سقفِ هشدارِ لینت باید **یک جا** تعریف شود.
     *
     * اگر CI عددِ خودش را بنویسد، با `package.json` از هم جدا می‌افتند و
     * یکی سخت‌گیرتر از دیگری می‌شود — آن‌وقت یا توسعه‌دهنده محلی سبز
     * می‌بیند و CI قرمز، یا برعکس که بدتر است.
     */
    public function test_the_lint_ceiling_lives_only_in_package_json(): void
    {
        $ci = $this->source('ci');

        $this->assertStringContainsString('npm run lint', $ci);
        $this->assertStringNotContainsString('--max-warnings', $ci);

        $package = json_decode((string) file_get_contents(base_path('package.json')), true);

        $this->assertStringContainsString('--max-warnings', $package['scripts']['lint']);
    }

    /**
     * بررسیِ امنیتی باید موازیِ بقیه باشد، نه وابسته به آن‌ها.
     *
     * اگر پشتِ لینت یا تست بود، یک خطای قالب‌بندی جلوی دیدنِ آسیب‌پذیری را
     * می‌گرفت.
     */
    public function test_the_security_scan_does_not_hide_behind_other_jobs(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/^    needs:/m',
            $this->jobBlock('ci', 'security'),
            'بررسیِ امنیتی پشتِ jobِ دیگری منتظر می‌ماند.',
        );
    }
}
