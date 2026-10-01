<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use SplFileInfo;
use Tests\TestCase;

/**
 * معرفیِ محصول: «مسکونی و تجاری»، نه «فقط مسکونی» (مرحلهٔ ۷ · فاز ۲).
 *
 * ─── 🔴 چه چیزی خراب بود ─────────────────────────────────────────────────
 * سامانه از R67 مجتمعِ تجاری را هم پشتیبانی می‌کند (نوعِ مجتمع، تعرفه‌ی جدا،
 * دستیارِ پشتیبانی)، ولی هر جایی که محصول را معرفی می‌کرد — عنوانِ صفحه،
 * توضیحِ گوگل، Hero، فوتر، manifest، `llms.txt`، پرسشِ «ساکنا چیست؟» و حتی
 * متنِ روی تصویرِ اشتراک‌گذاری — آن را «پنلِ مجتمعِ مسکونی» می‌نامید.
 *
 * ─── قاعده ────────────────────────────────────────────────────────────────
 * هر «مسکونی» در متنِ معرفی باید «مسکونی و تجاری» (یا «مسکونی یا تجاری»)
 * باشد. استثناها نام‌برده و با دلیل‌اند، تا فهرست به سیاهه‌ی نخوانده تبدیل
 * نشود.
 *
 * ⚠️ مرزِ این تست: متنِ **درونِ تصویرِ** `og-cover.png` را نمی‌خواند (OCR
 * نیست). آن تصویر در همین فاز دوباره ساخته شد؛ هشدارش کنارِ تگِ OG است.
 */
class ProductScopeCopyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * عبارت‌هایی که «مسکونی»ِ تنها دارند و درست‌اند.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        'مجتمع‌های مسکونی و آپارتمان‌ها' => 'llms.txt: مخاطبِ مسکونی، و سطرِ بعدی مخاطبِ تجاری',
        'مدیریت مجتمع مسکونی,' => 'کلیدواژه: کنارِ «مدیریت مجتمع تجاری» در همان فهرست',
    ];

    /** هر «مسکونی»ای که به «و/یا تجاری» نرسد و در استثناها نباشد. */
    private function residentialOnly(string $text): array
    {
        foreach (array_keys(self::ALLOWED) as $phrase) {
            $text = str_replace($phrase, '', $text);
        }

        // نیم‌فاصله و فاصله‌ی معمولی و شکستنِ خط، هر سه میانِ «مسکونی» و «و»
        preg_match_all('/.{0,30}مسکونی(?![\s\x{200C}]+(?:و|یا)[\s\x{200C}]+تجاری).{0,20}/us', $text, $hits);

        return $hits[0];
    }

    /** @return array<string, string> */
    private function sources(): array
    {
        /*
         * ⚠️ پوشه، نه فهرستِ دستیِ فایل: کامپوننتِ تازه‌ی صفحه‌ی فرود باید
         * خودبه‌خود زیرِ همین قاعده بیاید. صفحه‌های داشبورد بیرون‌اند —
         * برچسبِ «مسکونی» در فرمِ نوعِ مجتمع آنجا درست است.
         */
        $files = [
            ...File::allFiles(resource_path('js/features/landing')),
            ...File::allFiles(resource_path('js/features/auth')),
            ...File::allFiles(resource_path('js/shared/config')),
            ...File::allFiles(resource_path('views/public')),
            ...File::allFiles(resource_path('views/layouts')),
            new SplFileInfo(public_path('manifest.webmanifest')),
        ];

        $texts = [];
        foreach ($files as $file) {
            // توضیحِ Blade متنِ صفحه نیست
            $text = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file->getPathname()));
            $texts[str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())] = $text;
        }

        // مقدارها، نه متنِ فایلِ پیکربندی — توضیحِ کد درباره‌ی R67 «مسکونی» دارد
        $texts['config/brand'] = implode("\n", array_filter(config('brand'), 'is_string'));
        $texts['config/seo'] = implode("\n", array_map(
            fn (array $page) => implode("\n", array_filter($page, 'is_string')),
            config('seo'),
        ));

        return $texts;
    }

    public function test_no_introduction_calls_the_product_residential_only(): void
    {
        $offenders = [];
        foreach ($this->sources() as $source => $text) {
            foreach ($this->residentialOnly($text) as $hit) {
                $offenders[] = "{$source}: …{$hit}…";
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * صفحه‌های رندرشده هم — همان چیزی که گوگل، خزنده‌ی هوشِ مصنوعی و
     * پیام‌رسان‌ها می‌خوانند (عنوان، توضیح، OG، JSON-LD، `llms.txt`).
     */
    public function test_the_rendered_pages_say_residential_and_commercial(): void
    {
        foreach (['/', '/support', '/demo', '/auth', '/llms.txt'] as $path) {
            $body = (string) $this->get($path)->assertOk()->getContent();

            $this->assertSame([], $this->residentialOnly($body), $path);
            $this->assertStringContainsString('تجاری', $body, "{$path} اصلاً از مجتمعِ تجاری نام نمی‌برد.");
        }
    }

    public function test_the_two_taglines_are_one(): void
    {
        preg_match("/BRAND_TAGLINE = '([^']+)'/u", (string) file_get_contents(base_path('resources/js/shared/config/brand.ts')), $ts);

        $this->assertSame(config('brand.tagline'), $ts[1]);
        $this->assertStringContainsString('تجاری', $ts[1]);
    }

    /**
     * ⚠️ آمارِ صفحه‌ی اصلی را کارفرما داده: ۲۵۰ مجتمع و ۹۸٪ رضایت «واقعی‌اند،
     * همین‌طور بمانند»؛ واحدها «۳۰ هزار واحد فعال». برچسبِ «مجتمعِ **مسکونی**
     * فعال» به «مجتمعِ فعال» رسید، چون عدد خودش درست است و تنها مشکل، تنگ‌کردنِ
     * معرفیِ محصول بود.
     */
    public function test_the_home_page_numbers_are_the_ones_given(): void
    {
        $content = (string) file_get_contents(base_path('resources/js/features/landing/content/landingContent.ts'));

        $this->assertStringContainsString("{ value: '+۲۵۰', label: 'مجتمع فعال' }", $content);
        $this->assertStringContainsString("{ value: '+۳۰هزار', label: 'واحد فعال' }", $content);
        $this->assertStringContainsString("{ value: '۹۸٪', label: 'رضایت مدیران' }", $content);
        $this->assertStringNotContainsString('۴۰هزار', $content);
    }

    /**
     * کارتِ پیام‌رسان مالِ پیش از فاز ۱۲ بود: «ساکن فقط به مدیریت پیام
     * می‌دهد» — پیام‌رسانی که حالا کانال، گروه و گفت‌وگوی خصوصی دارد.
     */
    public function test_the_messenger_card_describes_the_messenger_that_exists(): void
    {
        $content = (string) file_get_contents(base_path('resources/js/features/landing/content/landingContent.ts'));

        $this->assertStringNotContainsString('ساکن فقط به مدیریت پیام می‌دهد', $content);
        foreach (['کانال', 'گروه', 'گفت‌وگوی خصوصی', 'پیامِ صوتی'] as $capability) {
            $this->assertStringContainsString($capability, $content);
        }
    }
}
