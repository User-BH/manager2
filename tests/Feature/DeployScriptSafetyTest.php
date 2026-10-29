<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * قاعده‌های ایمنیِ داده در اسکریپت‌های استقرار (مرحلهٔ ۹ · فاز ۸؛ بندهای ۲۲ تا ۲۵ ِ فایل ۲).
 *
 * ⚠️ این‌ها **متنِ** اسکریپت را می‌سنجند، نه اجرایش روی سرور: اجرای واقعیِ
 * `deploy.sh` (composer، npm، MySQL، php-fpm) فقط روی خودِ سرور ممکن است و برچسبش
 * «ENVIRONMENT VERIFICATION REQUIRED» می‌ماند. نحوِ bash جدا با `bash -n` سنجیده شد.
 */
class DeployScriptSafetyTest extends TestCase
{
    /** بندِ ۲۴: روی محصول، بی بکاپِ ساخته‌شده **و** سنجیده‌شده، migration اجرا نمی‌شود. */
    public function test_production_deploys_back_up_and_verify_before_any_migration(): void
    {
        $script = $this->script('deploy.sh');
        $backup = strpos($script, 'artisan backups:run');
        $verify = strpos($script, 'artisan backups:verify');
        $migrate = strpos($script, '"$PHP" artisan migrate --force --no-interaction');

        $this->assertNotFalse($backup, 'deploy.sh پیش از migration بکاپ نمی‌گیرد.');
        $this->assertNotFalse($verify, 'deploy.sh بکاپ را نمی‌سنجد.');
        $this->assertNotFalse($migrate);
        $this->assertLessThan($migrate, $backup, 'بکاپ پس از migration است.');
        $this->assertLessThan($migrate, $verify, 'سنجشِ بکاپ پس از migration است.');

        // شکستِ هر کدام یعنی توقف، نه ادامه
        $this->assertMatchesRegularExpression('/backups:run[^\n]*\\\\\n[^\n]*\|\| die /', $script);
        $this->assertMatchesRegularExpression('/backups:verify[^\n]*\\\\\n[^\n]*\|\| die /', $script);
        $this->assertStringContainsString('if is_production; then', substr($script, $backup - 400, 400));
    }

    /** دادهٔ نمونه فقط روی Preview؛ پاک‌سازی روی محصول از خودِ اسکریپت رد می‌شود. */
    public function test_the_preview_reset_is_refused_anywhere_but_preview(): void
    {
        $script = $this->script('deploy.sh');

        $this->assertMatchesRegularExpression('/RESET_PREVIEW" = "1" \] && ! is_preview; then\s+die /', $script);
        $this->assertStringContainsString('artisan preview:reset --force', $script);
        $this->assertStringNotContainsString('migrate:fresh', $script, 'deploy.sh نباید مستقیم migrate:fresh بزند؛ فقط preview:reset با قفل‌هایش.');
        $this->assertStringNotContainsString('migrate:fresh', $this->script('setup.sh'));
    }

    /** Preview همان رفتارِ محصول: بی بسته‌های توسعه، با کشِ پیکربندی؛ و نصبش از راهِ `preview:reset`. */
    public function test_preview_installs_like_production(): void
    {
        $lib = $this->script('lib.sh');
        $this->assertStringContainsString('is_deployed() { is_production || is_preview; }', $lib);
        $this->assertStringContainsString("if is_deployed; then\n        \"\$PHP\" artisan config:cache", $lib);

        foreach (['deploy.sh', 'setup.sh'] as $name) {
            $this->assertStringContainsString("if is_deployed; then\n    composer_run install --no-dev", $this->script($name), $name);
        }
        $this->assertMatchesRegularExpression('/if is_preview; then[^\n]*\n(?:\s*#[^\n]*\n)*\s*"\$PHP" artisan preview:reset --force/', $this->script('setup.sh'));
    }

    /** `--demo` روی محصول همچنان رد می‌شود (قفلِ محصول). */
    public function test_the_production_demo_lock_is_kept(): void
    {
        $this->assertMatchesRegularExpression('/WITH_DEMO" = "1" \] && is_production; then\s+die /', $this->script('setup.sh'));
    }

    /**
     * «اول Preview، بعد محصول» (۲۰۲۶-۱۰-۲۹، بندِ ۵): `--ref` فقط تا همان commit ِ بازبینی‌شده پیش می‌رود — بی بازنویسیِ
     * تاریخچه و بی پریدن به کدِ تازه‌تر — و نسخه‌ی مستقرشده برای نوارِ Preview نوشته می‌شود.
     */
    public function test_production_can_be_pinned_to_the_commit_reviewed_on_preview(): void
    {
        $script = $this->script('deploy.sh');

        $this->assertStringContainsString('--ref=*) REF="${arg#--ref=}" ;;', $script);
        $this->assertStringContainsString('git merge --ff-only "$REF" || die ', $script);
        $this->assertStringContainsString('git rev-parse --short HEAD > storage/app/deployed-commit', $script);
        foreach (['reset --hard', 'push --force', 'checkout -f'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $script, $unsafe);
        }
    }

    private function script(string $name): string
    {
        return (string) file_get_contents(base_path('scripts/'.$name));
    }
}
