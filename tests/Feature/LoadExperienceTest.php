<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * تجربه‌ی واقعیِ بارگذاری، سمتِ سرور (مرحلهٔ ۷ · فاز ۳).
 *
 * ─── چه چیزی سنجیده شد ─────────────────────────────────────────────────────
 * ۲۳ مسیر × دو اندازه روی 4G و پردازنده‌ی ۴ برابر کندتر، با کشِ خالی. سه
 * یافته که سمتِ سرور دارند:
 *
 * ① `#root` خالی می‌آمد: صفحه‌ی اصلی ‎۲٫۱ ثانیه و داشبورد حدودِ ‎۱ ثانیه سفید
 *    بودند تا جاوااسکریپت اجرا شود.
 * ② تصویرِ پنلِ ورود `lazy` بود و پیش‌بار نمی‌شد.
 * ③ لوگو ‎۵۱۲×۵۱۲ و ‎۴۰KB بود برای نمایشِ حداکثر ‎۳۶ پیکسلی — در هر صفحه.
 *
 * بخشِ سمتِ کاربر در `tests/js/loadExperience.test.ts` است.
 */
class LoadExperienceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function islandPages(): array
    {
        return [
            'صفحه‌ی اصلی' => ['/'],
            'دمو' => ['/demo'],
            'پشتیبانی' => ['/support'],
            'ورود' => ['/auth'],
            'پوسته‌ی داشبورد' => ['/dashboard'],
        ];
    }

    #[DataProvider('islandPages')]
    public function test_a_page_that_is_all_react_shows_a_loading_state_not_white(string $path): void
    {
        $html = (string) $this->get($path)->assertOk()->getContent();

        // درونِ `#root`، تا اولین رندرِ React خودش جایش را بگیرد
        $this->assertMatchesRegularExpression('/<div id="root"><div class="boot-shell">/', $html);
        $this->assertStringContainsString('role="status"', $html);
        // اگر اسکریپت هرگز نرسید، راهِ بیرون — نه چرخشِ بی‌پایان
        $this->assertStringContainsString('class="boot-shell-slow"', $html);
    }

    /**
     * ⚠️ بلاگ محتوایش را سرورساید دارد؛ لایه‌ی بارگذاری آنجا روی متنِ آماده
     * می‌نشست و آن را تا رسیدنِ اسکریپت پنهان می‌کرد.
     */
    public function test_a_server_rendered_page_gets_no_loading_layer(): void
    {
        $html = (string) $this->get('/blog')->assertOk()->getContent();

        $this->assertStringNotContainsString('boot-shell', $html);
    }

    /**
     * لایه‌ی بارگذاری با تأخیر دیده می‌شود تا روی دستگاهِ سریع چشمک نزند، و
     * پیامِ «طول کشید» فقط پس از ‎۱۲ ثانیه.
     */
    public function test_the_loading_layer_waits_before_it_shows(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        preg_match('/\.boot-shell \{([^}]*)\}/', $css, $shell);
        preg_match('/\.boot-shell-slow \{([^}]*)\}/', $css, $slow);

        $this->assertStringContainsString('opacity: 0;', $shell[1]);
        $this->assertMatchesRegularExpression('/animation: boot-shell-in [\d.]+s ease 0\.35s forwards/', $shell[1]);
        $this->assertStringContainsString('opacity: 0;', $slow[1]);
        $this->assertMatchesRegularExpression('/animation: boot-shell-in [\d.]+s ease 12s forwards/', $slow[1]);
    }

    public function test_the_logo_is_sized_for_where_it_is_shown(): void
    {
        $path = public_path('logo.webp');
        $size = getimagesize($path);

        // بیشینه‌ی نمایش ‎۳۶px؛ ‎۱۲۸px برای صفحه‌ی ‎۳x بس است
        $this->assertLessThanOrEqual(256, $size[0]);
        $this->assertLessThan(12 * 1024, filesize($path), 'لوگو در هر صفحه بار می‌شود؛ باید سبک بماند.');
    }

    /**
     * پنلِ تصویرِ ورود فقط از `lg` دیده می‌شود؛ پیش‌بارش هم فقط همان‌جا.
     */
    public function test_the_auth_panel_image_is_preloaded_only_where_it_is_seen(): void
    {
        $html = (string) $this->get('/auth')->assertOk()->getContent();

        preg_match('/<link rel="preload" as="image"[^>]*>/', $html, $preload);

        $this->assertNotEmpty($preload);
        $this->assertStringContainsString('auth-background', $preload[0]);
        $this->assertStringContainsString('media="(min-width: 1024px)"', $preload[0]);
    }
}
