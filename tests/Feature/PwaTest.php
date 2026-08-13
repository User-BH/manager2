<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قراردادِ PWA (R35).
 *
 * ─── چرا این‌ها تست دارند ───────────────────────────────────────────────────
 * خرابیِ PWA **هیچ خطایی نمی‌دهد**. آیکونی که نباشد یک قابِ خالی است،
 * manifestِ ناقص فقط باعث می‌شود دکمه‌ی نصب ظاهر نشود، و splashِ گم‌شده روی
 * iOS صفحه‌ی سفید است. هیچ‌کدام در لاگ نمی‌آید.
 *
 * ⚠️ نمونه‌ی زنده‌اش همین‌جا بود: `public/sw.js` وجود داشت، ۴۸ خط کدِ درست
 * داشت، و **هیچ‌جا ثبت نمی‌شد**. ماه‌ها به‌نظر می‌رسید برنامه PWA است در
 * حالی که هیچ‌کدام از رفتارهایش کار نمی‌کرد.
 */
class PwaTest extends TestCase
{
    // میان‌برها و پوسته‌ها صفحه‌ی واقعی می‌گیرند و آن صفحه‌ها به تنظیماتِ
    // پایگاه‌داده نگاه می‌کنند؛ بدونِ این، ۵۰۰ می‌گیریم نه صفحه.
    use RefreshDatabase;

    /**
     * @return array<string,mixed>
     */
    private function manifest(): array
    {
        $path = public_path('manifest.webmanifest');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, 'manifest باید JSONِ معتبر باشد.');

        return $decoded;
    }

    public function test_the_manifest_has_everything_a_browser_needs_to_offer_installation(): void
    {
        $manifest = $this->manifest();

        foreach (['id', 'name', 'short_name', 'start_url', 'scope', 'display', 'icons'] as $key) {
            $this->assertArrayHasKey($key, $manifest, "کلیدِ «{$key}» در manifest نیست.");
        }

        $this->assertSame('fa', $manifest['lang']);
        $this->assertSame('rtl', $manifest['dir']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertContains('standalone', $manifest['display_override']);
    }

    /**
     * ⚠️ هر آیکونی که در manifest نام برده شده باید واقعاً روی دیسک باشد.
     *
     * مرورگر برای آیکونِ ۴۰۴ خطایی نشان نمی‌دهد؛ فقط بی‌صدا ردش می‌کند و
     * اگر آن یکی همانی باشد که شرطِ نصب را برآورده می‌کرد، دکمه‌ی نصب
     * هیچ‌وقت نمی‌آید.
     */
    public function test_every_icon_in_the_manifest_exists_on_disk(): void
    {
        foreach ($this->manifest()['icons'] as $icon) {
            $this->assertFileExists(
                public_path(ltrim((string) $icon['src'], '/')),
                "آیکونِ «{$icon['src']}» در manifest هست ولی فایلش نیست.",
            );
        }
    }

    /**
     * ابعادِ اعلام‌شده باید با ابعادِ واقعیِ فایل یکی باشد.
     *
     * ابعادِ دروغ یعنی مرورگر آیکونِ اشتباه را برای اندازه‌ی اشتباه انتخاب
     * می‌کند و نتیجه‌اش آیکونِ محو روی صفحه‌ی خانه است.
     */
    public function test_declared_icon_sizes_match_the_real_files(): void
    {
        foreach ($this->manifest()['icons'] as $icon) {
            if ($icon['sizes'] === 'any') {
                continue;
            }

            [$width, $height] = array_map('intval', explode('x', (string) $icon['sizes']));
            $real = getimagesize(public_path(ltrim((string) $icon['src'], '/')));

            $this->assertNotFalse($real);
            $this->assertSame([$width, $height], [$real[0], $real[1]], "ابعادِ «{$icon['src']}» با اعلامش نمی‌خواند.");
        }
    }

    /**
     * ⚠️ آیکونِ maskable باید **جدا** از آیکونِ معمولی باشد.
     *
     * نسخه‌ی قبلی روی همان دو فایل `"any maskable"` نوشته بود. اندروید
     * آیکونِ maskable را دایره‌ای می‌بُرد، و چون آن فایل محتوایش تا ۱۲٪ لبه
     * می‌رفت، نوکِ لوگو قیچی می‌شد. آیکونِ maskable زمینه‌ی مات و حاشیه‌ی
     * امنِ خودش را می‌خواهد.
     */
    public function test_maskable_icons_are_separate_assets(): void
    {
        $icons = $this->manifest()['icons'];

        $maskable = array_values(array_filter($icons, fn ($i) => str_contains((string) $i['purpose'], 'maskable')));
        $any = array_values(array_filter($icons, fn ($i) => $i['purpose'] === 'any'));

        $this->assertNotEmpty($maskable, 'دستِ‌کم یک آیکونِ maskable لازم است.');

        $maskableSrc = array_column($maskable, 'src');
        $anySrc = array_column($any, 'src');

        $this->assertSame(
            [],
            array_intersect($maskableSrc, $anySrc),
            'یک فایل نمی‌تواند هم‌زمان آیکونِ any و maskable باشد؛ حاشیه‌ی امنشان فرق دارد.',
        );

        // اندروید برای صفحه‌ی خانه ۵۱۲ را می‌خواهد
        $this->assertContains('512x512', array_column($maskable, 'sizes'));
    }

    /**
     * میان‌برها باید به مسیرهای واقعی بروند.
     *
     * میان‌بری که ۴۰۴ بدهد از دیدِ کاربر یعنی برنامه خراب است — و چون فقط
     * پس از نصب و از منوی لانچر دیده می‌شود، هیچ‌وقت حین توسعه لو نمی‌رود.
     */
    public function test_manifest_shortcuts_point_at_real_routes(): void
    {
        foreach ($this->manifest()['shortcuts'] ?? [] as $shortcut) {
            $path = parse_url((string) $shortcut['url'], PHP_URL_PATH);

            $this->get((string) $path)->assertSuccessful();
        }
    }

    public function test_the_offline_page_is_reachable_and_standalone(): void
    {
        $response = $this->get('/offline');

        $response->assertSuccessful();
        $response->assertSee('اتصال اینترنت برقرار نیست');

        /*
         * ⚠️ صفحه‌ی آفلاین نباید هیچ داراییِ ساخته‌شده‌ای بخواهد.
         *
         * دقیقاً وقتی نشان داده می‌شود که شبکه‌ای نیست؛ اگر به CSSِ بیرونی
         * وابسته باشد، کاربر یک صفحه‌ی بی‌شکل می‌بیند.
         */
        $response->assertDontSee('/build/assets', false);
    }

    /**
     * service worker باید سرو شود و کنترل‌کننده‌ی `fetch` داشته باشد.
     *
     * بدونِ `fetch`، مرورگر برنامه را قابلِ نصب نمی‌داند — هر چقدر هم که
     * manifest کامل باشد.
     */
    public function test_the_service_worker_is_served_and_handles_fetch(): void
    {
        $path = public_path('sw.js');

        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);

        $this->assertStringContainsString("addEventListener('fetch'", $source);
        $this->assertStringContainsString("addEventListener('install'", $source);
        $this->assertStringContainsString("addEventListener('activate'", $source);

        // صفحه‌ی آفلاین باید در فهرستِ پیش‌کش باشد، وگرنه هنگامِ نیاز نیست
        $this->assertStringContainsString('/offline', $source);
    }

    /**
     * ⚠️ نسخه‌ی کش باید در نامِ همه‌ی کش‌ها بیاید.
     *
     * اگر نامِ کشی ثابت بماند، `activate` پاکش نمی‌کند و کاربر تا ابد
     * نسخه‌ی قدیمِ همان یک کش را می‌گیرد — بدترین نوعِ باگ، چون فقط روی
     * دستگاهِ کاربر دیده می‌شود.
     */
    public function test_every_cache_name_carries_the_version(): void
    {
        $source = (string) file_get_contents(public_path('sw.js'));

        /*
         * ⚠️ الگوی اول فقط داخلِ backtick می‌گشت، و در پاسِ خرابکاری لو رفت:
         * وقتی یکی از نام‌ها را به `'sakena-images'` (تک‌کوتیشن، بدونِ
         * نسخه) عوض کردم، الگو اصلاً پیدایش نکرد و تست سبز ماند — یعنی
         * دقیقاً همان اشتباهی که برای گرفتنش نوشته شده بود از کنارش رد شد.
         *
         * حالا خودِ بلوکِ `const CACHE` بیرون کشیده می‌شود و **هر** مقدارش
         * سنجیده می‌شود، فارغ از اینکه با چه نشانه‌ای نوشته شده باشد.
         */
        /*
         * ⚠️ مرزِ بلوک `\r?\n\}` است نه اولین `}`.
         *
         * خودِ مقادیر `${VERSION}` دارند، پس `.+?}` روی همان آکولادِ داخلی
         * می‌ایستد و فقط نیمِ سطرِ اول را برمی‌دارد.
         */
        $this->assertSame(1, preg_match('/const CACHE = \{(.+?)\r?\n\}/s', $source, $block));

        preg_match_all('/^\s*(\w+):\s*(.+?),\s*$/m', $block[1], $entries, PREG_SET_ORDER);

        $this->assertGreaterThanOrEqual(3, count($entries), 'بلوکِ CACHE خوانده نشد؛ ساختارش عوض شده؟');

        foreach ($entries as [, $key, $value]) {
            $this->assertStringContainsString(
                '${VERSION}',
                $value,
                "نامِ کشِ «{$key}» نسخه ندارد، پس در activate پاک نمی‌شود و تا ابد کهنه می‌ماند.",
            );
        }
    }

    /**
     * هر splashِ اعلام‌شده در config باید فایلش ساخته شده باشد.
     *
     * iOS برای splashِ گم‌شده خطا نمی‌دهد؛ صفحه‌ی سفید نشان می‌دهد.
     */
    public function test_every_declared_splash_screen_exists_with_the_right_size(): void
    {
        $splashes = config('pwa.splash');

        $this->assertNotEmpty($splashes);

        foreach ($splashes as $splash) {
            $path = public_path(ltrim((string) $splash['href'], '/'));

            $this->assertFileExists($path, "splashِ «{$splash['href']}» در config هست ولی ساخته نشده. `php artisan pwa:splash` را اجرا کنید.");

            $size = getimagesize($path);

            $this->assertNotFalse($size);
            $this->assertSame(
                [$splash['width'] * $splash['ratio'], $splash['height'] * $splash['ratio']],
                [$size[0], $size[1]],
                "ابعادِ «{$splash['href']}» با media queryاش نمی‌خواند، پس iOS هرگز انتخابش نمی‌کند.",
            );
        }
    }

    /**
     * تگ‌های PWA باید در **هر دو** پوسته باشند.
     *
     * کاربر ممکن است برنامه را از صفحه‌ی فرود نصب کند یا از داشبورد؛ اگر
     * فقط یکی تگ‌ها را داشته باشد، نصب از آن یکی کار نمی‌کند.
     */
    public function test_both_shells_carry_the_pwa_tags(): void
    {
        foreach (['/', '/auth'] as $url) {
            $response = $this->get($url);

            $response->assertSee('rel="manifest"', false);
            $response->assertSee('apple-mobile-web-app-capable', false);
            $response->assertSee('apple-touch-startup-image', false);
            $response->assertSee('viewport-fit=cover', false);
        }
    }

    /**
     * رنگِ splash باید با `background_color`ِ manifest یکی باشد.
     *
     * وگرنه لحظه‌ی گذار از splash به برنامه یک پرشِ رنگی دیده می‌شود.
     */
    public function test_the_splash_background_matches_the_manifest(): void
    {
        $this->assertSame(
            strtolower((string) $this->manifest()['background_color']),
            strtolower((string) config('pwa.splash_background')),
        );
    }
}
