<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `fix_permissions` ِ اسکریپت‌های استقرار (مرحلهٔ ۸ · فاز ۳ · بندِ ۱۶).
 *
 * 🔴 ریشه‌ی محتملِ ۴۰۴ ِ همه‌ی تصویرهای بلاگ روی سرورِ اصلی: این تابع فقط
 * مالکِ **خودِ** `storage` را می‌سنجید و اگر درست بود برمی‌گشت؛ زیرپوشه‌ای که
 * `blog:import` ِ همان استقرار با root ساخته بود (لاراول: ۰۷۰۰) برای php-fpm
 * بسته می‌ماند.
 *
 * ⚠️ مرزِ آنچه این‌جا سنجیدنی است: ساختنِ «پوشه‌ی بالا درست، زیرپوشه نادرست»
 * root می‌خواهد که CI ندارد. پس دو چیز سنجیده می‌شود: رفتارِ واقعیِ تابع روی
 * یک درختِ موقت (همه درست ← موفق؛ ناهمخوان ← هشدار با نامِ مسیر، نه «موفق»)،
 * و اینکه تصمیم از **کلِ درخت** گرفته می‌شود، نه از `stat storage`.
 */
class DeployPermissionsTest extends TestCase
{
    private function runFixPermissions(string $tree, string $webUser): string
    {
        $script = sprintf(
            'source scripts/lib.sh; cd %s; detect_web_user() { printf %%s %s; }; fix_permissions',
            escapeshellarg($tree),
            escapeshellarg($webUser),
        );

        return (string) shell_exec('cd '.escapeshellarg(base_path()).' && NO_COLOR=1 bash -c '.escapeshellarg($script).' 2>&1');
    }

    private function tree(): string
    {
        $tree = sys_get_temp_dir().'/sakena-perm-'.bin2hex(random_bytes(4));
        mkdir($tree.'/storage/app/private/blog', 0700, true);
        mkdir($tree.'/bootstrap/cache', 0755, true);
        touch($tree.'/storage/app/private/blog/cover.webp');

        return $tree;
    }

    public function test_a_tree_owned_by_the_web_user_is_left_as_is(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('find -user روی ویندوز معنا ندارد؛ CI لینوکس است.');
        }

        $me = trim((string) shell_exec('id -un'));
        $output = $this->runFixPermissions($this->tree(), $me);

        $this->assertStringContainsString('با همه‌ی زیرپوشه‌ها', $output);
        $this->assertStringNotContainsString('!', $output);
    }

    public function test_a_file_the_web_user_does_not_own_is_named_and_never_reported_as_fine(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('find -user روی ویندوز معنا ندارد؛ CI لینوکس است.');
        }
        if (trim((string) shell_exec('id -u')) === '0') {
            $this->markTestSkipped('با root تابع مالکیت را درست می‌کند و هشدار نمی‌دهد.');
        }

        $output = $this->runFixPermissions($this->tree(), 'nobody');

        $this->assertStringContainsString("متعلق به 'nobody' نیست", $output);
        $this->assertStringContainsString('storage', $output);
        $this->assertStringNotContainsString('✓', $output);
    }

    public function test_the_decision_comes_from_the_whole_tree_not_the_top_folder(): void
    {
        $lib = (string) file_get_contents(base_path('scripts/lib.sh'));
        $body = substr($lib, (int) strpos($lib, 'fix_permissions() {'));
        $body = substr($body, 0, (int) strpos($body, "\n}\n"));

        $this->assertStringContainsString('find storage bootstrap/cache ! -user "$web_user"', $body);
        // همان بازگشتِ زودهنگامِ قدیمی: «مالکِ storage درست است، پس همه درست است»
        $this->assertStringNotContainsString("stat -c '%U' storage", $body);
        /*
         * 🔴 نسخه‌ی اولِ همین اصلاح را اجرای دستی گرفت: find روی پوشه‌ی
         * ناخوانا کدِ ۱ می‌دهد و با `set -e` کلِ استقرار می‌مرد؛ و «پیدا نشد»
         * نباید «همه درست است» خوانده شود.
         */
        $this->assertStringContainsString('|| scanned=0', $body);
    }
}
