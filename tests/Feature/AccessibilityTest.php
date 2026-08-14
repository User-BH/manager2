<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * قراردادهای دسترس‌پذیری در سطحِ سند (R37).
 *
 * ─── چرا این‌ها تست دارند ───────────────────────────────────────────────────
 * هیچ‌کدام از این‌ها خطا نمی‌دهند. صفحه‌ای بدونِ پیوندِ پرش کاملاً سالم
 * به‌نظر می‌رسد — فقط کاربرِ کیبورد باید در هر بار ورود از کلِ منو Tab بزند.
 * صفحه‌ای بدونِ `lang` هم درست دیده می‌شود، ولی صفحه‌خوان متنِ فارسی را با
 * لهجه‌ی انگلیسی می‌خواند.
 */
class AccessibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int,string>
     */
    public static function publicPages(): array
    {
        return [
            ['/'],
            ['/demo'],
            ['/support'],
            ['/auth'],
            ['/offline'],
            /*
             * ⚠️ `/dashboard` عمداً اینجاست.
             *
             * پنج مسیرِ بالا همگی از `layouts/public.blade.php` می‌آیند، پس
             * پوسته‌ی داشبورد (`spa.blade.php`) اصلاً آزموده نمی‌شد. در پاسِ
             * خرابکاری معلوم شد: `user-scalable=no` را در آن فایل گذاشتم و
             * هیچ تستی نشکست. این مسیر بدونِ ورود هم پوسته را برمی‌گرداند،
             * پس برای سنجیدنِ خودِ سند کافی است.
             */
            ['/dashboard'],
        ];
    }

    /**
     * ⚠️ پیوندِ پرش باید **اولین** عنصرِ قابلِ فوکوسِ سند باشد.
     *
     * اگر بعد از لوگو یا کلیدِ تم بیاید، کاربر باید همان‌ها را رد کند تا به
     * آن برسد — یعنی هدفش را از دست داده.
     */
    public function test_the_skip_link_is_the_first_focusable_element(): void
    {
        foreach (['/', '/auth', '/dashboard'] as $url) {
            $html = (string) $this->get($url)->getContent();

            $bodyStart = strpos($html, '<body');
            $this->assertNotFalse($bodyStart, "بدنه‌ی «{$url}» پیدا نشد.");

            $body = substr($html, $bodyStart);

            $this->assertSame(
                1,
                preg_match('/<(a|button|input|select|textarea)\b[^>]*>/', $body, $first, PREG_OFFSET_CAPTURE),
                "هیچ عنصرِ قابلِ فوکوسی در «{$url}» نیست.",
            );

            $this->assertStringContainsString(
                'skip-link',
                $first[0][0],
                "اولین عنصرِ قابلِ فوکوسِ «{$url}» پیوندِ پرش نیست.",
            );
        }
    }

    public function test_the_skip_link_points_at_an_anchor_the_page_actually_has(): void
    {
        foreach (['/', '/auth', '/dashboard'] as $url) {
            $html = (string) $this->get($url)->getContent();

            $this->assertStringContainsString('href="#main-content"', $html);
        }
    }

    /**
     * زبان و جهتِ سند.
     *
     * بدونِ `lang="fa"` صفحه‌خوان متنِ فارسی را با موتورِ انگلیسی می‌خواند و
     * خروجی نامفهوم می‌شود.
     *
     * @dataProvider publicPages
     */
    #[DataProvider('publicPages')]
    public function test_every_public_page_declares_language_and_direction(string $url): void
    {
        $html = (string) $this->get($url)->getContent();

        $this->assertMatchesRegularExpression('/<html[^>]+lang="fa"/', $html, "«{$url}» زبان اعلام نکرده.");
        $this->assertMatchesRegularExpression('/<html[^>]+dir="rtl"/', $html, "«{$url}» جهت اعلام نکرده.");
    }

    /**
     * ⚠️ `user-scalable=no` ممنوع است.
     *
     * بستنِ بزرگ‌نمایی یعنی کاربرِ کم‌بینا نمی‌تواند متن را بزرگ کند — و این
     * دقیقاً همان کاربری است که بیشتر از همه به آن نیاز دارد.
     *
     * @dataProvider publicPages
     */
    #[DataProvider('publicPages')]
    public function test_zoom_is_never_disabled(string $url): void
    {
        $html = (string) $this->get($url)->getContent();

        preg_match('/<meta[^>]+name="viewport"[^>]*>/', $html, $viewport);

        $this->assertNotEmpty($viewport, "«{$url}» تگِ viewport ندارد.");
        $this->assertStringNotContainsString('user-scalable=no', $viewport[0]);
        $this->assertStringNotContainsString('maximum-scale=1', $viewport[0]);
    }

    /**
     * قواعدِ دسترس‌پذیری باید در CSSِ ساخته‌شده باشند، نه فقط در سورس.
     *
     * ⚠️ یک `@media` با نحوِ اشتباه بی‌صدا کنار گذاشته می‌شود؛ نه بیلد
     * می‌شکند نه خطایی در کنسول می‌آید — فقط قاعده هیچ‌وقت اعمال نمی‌شود.
     */
    public function test_the_accessibility_rules_survive_the_build(): void
    {
        $files = glob(public_path('build/assets/*.css'));

        $this->assertNotEmpty($files, 'بیلدِ CSS پیدا نشد؛ اول `npm run build`.');

        $css = '';

        foreach ($files as $file) {
            $css .= (string) file_get_contents($file);
        }

        foreach ([
            'focus-visible' => 'حلقه‌ی فوکوس',
            'pointer:coarse' => 'قاعده‌ی ناحیه‌ی لمسی',
            'prefers-reduced-motion' => 'کاهشِ حرکت',
            'safe-area-inset-bottom' => 'فاصله‌ی امنِ آیفون',
            'skip-link' => 'پیوندِ پرش',
        ] as $needle => $label) {
            $this->assertStringContainsString(
                $needle,
                str_replace(' ', '', $css),
                "{$label} در CSSِ ساخته‌شده نیست.",
            );
        }
    }

    /**
     * ⚠️ `MotionConfig` باید در **هر** نقطه‌ی ورود باشد.
     *
     * انیمیشن‌های framer-motion از بلوک‌های `prefers-reduced-motion`ِ CSS رد
     * می‌شوند؛ تنها راهِ خاموش‌کردنشان همین است. اگر یک ورودی جا بیفتد،
     * همان صفحه برای کاربرِ حساس به حرکت همچنان می‌لرزد.
     */
    public function test_every_entry_point_respects_reduced_motion(): void
    {
        $entries = array_merge(
            [resource_path('js/app/main.tsx')],
            glob(resource_path('js/app/entries/*.tsx')),
        );

        $this->assertGreaterThanOrEqual(5, count($entries));

        foreach ($entries as $entry) {
            $source = (string) file_get_contents($entry);

            /*
             * ⚠️ الگو باید خودِ **تگ** را ببیند، نه صرفاً رشته را.
             *
             * نسخه‌ی اولِ این تست `assertStringContainsString('reducedMotion="user"')`
             * بود و در پاسِ خرابکاری پوچ درآمد: همان عبارت داخلِ کامنتِ
             * توضیحیِ بالای همان تگ هم هست، پس با برداشتنِ propِ واقعی تست
             * همچنان سبز می‌ماند. تستی که کد را از توضیح تشخیص ندهد،
             * چیزی را نگه نمی‌دارد.
             */
            $this->assertMatchesRegularExpression(
                '/<MotionConfig\s+reducedMotion="user"/',
                $source,
                basename($entry).' تنظیمِ کاهشِ حرکت را ندارد.',
            );
        }
    }
}
