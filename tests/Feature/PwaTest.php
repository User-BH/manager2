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
     * 🔴 «چند نکته»: برنامه‌ی نصب‌شده از هر راه و در هر سیستم‌عامل «ساکنا» نامیده می‌شود.
     *
     * رومیزی (Chrome و Edge روی ویندوز، مک و لینوکس) و پنجره‌ی نصب و فهرستِ برنامه‌های اندروید `name` را نشان می‌دهند؛
     * `name` جمله‌ی بلندِ «ساکنا — پنل مدیریت مجتمع‌های مسکونی و تجاری» بود. iOS عنوان را از
     * `apple-mobile-web-app-title` می‌گیرد و بی آن از `<title>` ِ همان صفحه — پس هر صفحه‌ای که شاید از آن نصب شود.
     */
    public function test_the_installed_app_is_named_sakena_from_every_page(): void
    {
        $manifest = $this->manifest();
        $this->assertSame('ساکنا', $manifest['name']);
        $this->assertSame('ساکنا', $manifest['short_name']);
        $this->assertSame('ساکنا', config('brand.name'));

        // خانه، ورود، آفلاین و پوسته‌ی SPA (هر نشانیِ داشبوردی، حتی ناشناخته، همان پوسته است) و صفحه‌ی ۴۰۴ ِ Blade
        $pages = [
            '/' => $this->get('/')->assertOk()->getContent(),
            '/auth' => $this->get('/auth')->assertOk()->getContent(),
            '/offline' => $this->get('/offline')->assertOk()->getContent(),
            'SPA' => $this->get('/no-such-page-anywhere')->assertOk()->getContent(),
            'errors.404' => view('errors.404')->render(),
        ];
        foreach ($pages as $page => $html) {
            $this->assertStringContainsString('<meta name="apple-mobile-web-app-title" content="ساکنا">', (string) $html, $page);
            $this->assertStringContainsString('<meta name="application-name" content="ساکنا">', (string) $html, $page);
        }

        // صفحه‌های جدای خطا که در آزمون رندر نمی‌شوند (۵۰۰، ۵۰۳، تعلیق)
        foreach (['500', '503', 'suspended'] as $view) {
            $source = (string) file_get_contents(resource_path("views/errors/{$view}.blade.php"));
            $this->assertStringContainsString('<meta name="apple-mobile-web-app-title" content="ساکنا">', $source, $view);
        }
    }

    /**
     * 🔴 «چند نکته»، بندِ ۲: پشتِ آیکونِ نصب صفحه‌ی سفید نیست.
     *
     * همه‌ی آیکون‌های نصب مربعِ سفیدِ مات بودند. حالا آیکون‌های `any` (رومیزی) زمینه‌ی شفاف دارند؛ maskable (اندروید)
     * و apple-touch-icon (iOS، که شفافیت نمی‌پذیرد) زمینه‌ی مات به رنگِ برند، و نشانِ maskable کاملاً داخلِ ناحیه‌ی
     * امنِ دایره‌ای به قطرِ ۸۰٪ است تا بریدنِ سیستم‌عامل چیزی از نشان نبُرد. فایل‌ها از
     * `scripts/generate-pwa-icons.py` می‌آیند. شکلِ نهایی روی هر سیستم‌عامل را خودِ سیستم‌عامل تعیین می‌کند.
     */
    public function test_install_icons_have_no_white_plate(): void
    {
        $brand = [15, 110, 86];
        $icons = collect($this->manifest()['icons']);

        $this->assertSame([], $icons->filter(fn ($i) => str_ends_with($i['src'], '.svg') && $i['purpose'] === 'any')->all(), 'آیکونِ SVG ِ any (سپرِ favicon) جای نشانِ برنامه را می‌گرفت.');

        foreach ($icons->where('purpose', 'any') as $icon) {
            foreach ($this->corners(public_path(ltrim($icon['src'], '/'))) as [$r, $g, $b, $alpha]) {
                $this->assertSame(127, $alpha, "گوشه‌ی {$icon['src']} شفاف نیست.");
            }
        }

        $opaque = [...$icons->where('purpose', 'maskable')->pluck('src')->all(), '/apple-touch-icon.png'];
        foreach ($opaque as $src) {
            foreach ($this->corners(public_path(ltrim($src, '/'))) as [$r, $g, $b, $alpha]) {
                $this->assertSame(0, $alpha, "{$src} باید مات باشد (iOS شفافیت را سیاه می‌کند).");
                $this->assertEqualsWithDelta($brand, [$r, $g, $b], 3, "زمینه‌ی {$src} رنگِ برند نیست.");
            }
        }

        foreach ($icons->where('purpose', 'maskable') as $icon) {
            $image = imagecreatefrompng(public_path(ltrim($icon['src'], '/')));
            $size = imagesx($image);
            $centre = ($size - 1) / 2;
            $outside = 0;
            for ($y = 0; $y < $size; $y += 2) {
                for ($x = 0; $x < $size; $x += 2) {
                    $c = imagecolorat($image, $x, $y);
                    $differs = abs((($c >> 16) & 0xFF) - $brand[0]) + abs((($c >> 8) & 0xFF) - $brand[1]) + abs(($c & 0xFF) - $brand[2]) > 24;
                    if ($differs && hypot($x - $centre, $y - $centre) > 0.4 * $size) {
                        $outside++;
                    }
                }
            }
            $this->assertSame(0, $outside, "نشانِ {$icon['src']} از ناحیه‌ی امنِ maskable بیرون می‌زند.");
        }

        // تک‌رنگ: فقط شکل — زمینه شفاف، هر پیکسلِ دیده‌شده سفید
        $mono = $icons->firstWhere('purpose', 'monochrome');
        $this->assertNotNull($mono);
        foreach ($this->corners(public_path(ltrim($mono['src'], '/'))) as [, , , $alpha]) {
            $this->assertSame(127, $alpha);
        }
    }

    /** @return list<array{0: int, 1: int, 2: int, 3: int}> رنگ و آلفای GD (۰ مات، ۱۲۷ شفاف) در چهار گوشه */
    private function corners(string $path): array
    {
        $image = imagecreatefrompng($path);
        $this->assertNotFalse($image, $path);
        imagesavealpha($image, true);
        $w = imagesx($image) - 1;
        $h = imagesy($image) - 1;

        return array_map(function (array $point) use ($image): array {
            $c = imagecolorat($image, $point[0], $point[1]);
            $alpha = imageistruecolor($image) ? ($c >> 24) & 0x7F : imagecolorsforindex($image, $c)['alpha'];

            return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF, $alpha];
        }, [[0, 0], [$w, 0], [0, $h], [$w, $h]]);
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
