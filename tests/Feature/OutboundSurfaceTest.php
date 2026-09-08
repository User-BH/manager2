<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * سطحِ تماسِ بیرونی و سطحِ آپلود — سیاههٔ کامل، نه نمونه (فاز ۷).
 *
 * ─── 🔴 چرا SSRF اینجا با «سیاهه» سنجیده می‌شود و نه با حمله ───────────────
 * حمله‌ی SSRF وقتی معنا دارد که ورودیِ کاربر به مقصدِ شبکه برسد. بازبینیِ
 * فاز ۷ هر شش نقطهٔ تماسِ بیرونیِ پروژه را خواند و نتیجه این بود:
 *
 *     همهٔ مقصدها **ثابتِ کامپایل‌تایم**‌اند.
 *
 * تنها مقدارِ درج‌شده `apikey` است، آن هم در **مسیر** نه در میزبان.
 *
 * ⚠️ پس نوشتنِ تستِ حمله بی‌معنا بود: چیزی برای حمله وجود ندارد. آنچه
 * ارزش دارد این است که **همین‌طور بماند** — یعنی روزی کسی آدرسی از
 * تنظیمات یا از بدنهٔ درخواست نگیرد و به `Http::` ندهد.
 *
 * اصلاحیه هم دقیقاً همین را خواسته: «اگر featureای اصلاً SSRF-capable
 * نیست، با evidence آن را مشخص کن.»
 */
class OutboundSurfaceTest extends TestCase
{
    /**
     * میزبان‌هایی که این برنامه حق دارد با آن‌ها حرف بزند.
     *
     * ⚠️ افزودن به این فهرست باید تصمیمِ دیده‌شونده در بازبینیِ کد باشد، نه
     * یک خطِ گم‌شده در یک سرویس.
     *
     * @return list<string>
     */
    private function allowedHosts(): array
    {
        return [
            'bpm.shaparak.ir',      // درگاهِ ملت
            'sep.shaparak.ir',      // درگاهِ سامان
            'api.kavenegar.com',    // پیامک
            'edge.ippanel.com',     // پیامک
            'rest.payamak-panel.com', // پیامک
            'schemas.xmlsoap.org',  // فضای‌نامِ SOAP، نه مقصدِ شبکه
            'interfaces.core.sw.bps.com', // همان
        ];
    }

    /** فایل‌های `app/` که تماسِ بیرونی می‌زنند. */
    private function callerFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (str_contains($source, 'Http::')) {
                $files[$file->getPathname()] = $source;
            }
        }

        $this->assertNotEmpty($files, 'هیچ نقطهٔ تماسِ بیرونی پیدا نشد؛ این محافظ پوچ می‌شود.');

        return $files;
    }

    /**
     * 🔴 هیچ میزبانِ ناشناخته‌ای در کد نباشد.
     */
    public function test_every_outbound_host_is_on_the_allowlist(): void
    {
        $unknown = [];

        foreach ($this->callerFiles() as $path => $source) {
            // ⚠️ کامنت‌ها هم آدرس دارند (لینکِ مستنداتِ سرویس)، ولی مقصد نیستند
            $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $source);

            preg_match_all('#https?://([a-z0-9.-]+)#i', $code, $matches);

            foreach (array_unique($matches[1]) as $host) {
                if (! in_array($host, $this->allowedHosts(), true)) {
                    $unknown[] = basename($path).' → '.$host;
                }
            }
        }

        $this->assertSame(
            [],
            array_unique($unknown),
            "میزبانِ بیرونیِ ناشناخته:\n  ".implode("\n  ", array_unique($unknown))
            ."\n⚠️ یا به سیاههٔ مجاز اضافه کنید، یا حذفش کنید.",
        );
    }

    /**
     * 🔴 مقصد هرگز نباید از یک متغیر بیاید.
     *
     * ─── این ادعای اصلیِ SSRF است ───────────────────────────────────────────
     * ⚠️ تا وقتی آدرس ثابت است، هیچ ورودی‌ای نمی‌تواند مقصد را عوض کند —
     * نه IPِ داخلی، نه localhost، نه نقطهٔ فراداده‌ی ابری، نه تغییرِ مسیر.
     * همهٔ آن سناریوها به یک پیش‌شرط نیاز دارند: کنترلِ آدرس.
     *
     * تنها استثنا `HealthAlerter` است که آدرس را از `.env` می‌گیرد — یعنی
     * از اپراتور، نه از کاربر — و پروتکلش هم سنجیده می‌شود.
     */
    public function test_no_request_takes_its_destination_from_a_variable(): void
    {
        $dynamic = [];

        foreach ($this->callerFiles() as $path => $source) {
            $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $source);

            /*
             * ⚠️ فقط دستوراتی که خودشان `Http::` دارند.
             *
             * 🔴 نسخهٔ اول هر `->post(` را می‌گرفت و `$this->post($body)`ِ
             * `IppanelSms` را — که یک متدِ **خصوصیِ خودِ کلاس** است، نه
             * تماسِ شبکه — به‌غلط متهم کرد. تستی که خطای کاذب بدهد، همان
             * تستی است که خاموشش می‌کنند.
             */
            $arguments = [];

            foreach (explode(';', $code) as $statement) {
                if (! str_contains($statement, 'Http::')) {
                    continue;
                }

                preg_match_all('#->(?:get|post|put|patch|delete|send)\(\s*([^,)\s]+)#', $statement, $found);
                $arguments = [...$arguments, ...$found[1]];
            }

            foreach ($arguments as $argument) {
                $literal = str_starts_with($argument, "'https")
                    || str_starts_with($argument, '"https')
                    || str_starts_with($argument, "'http")
                    || str_starts_with($argument, '"http')
                    || str_starts_with($argument, 'self::');

                if ($literal) {
                    continue;
                }

                /*
                 * ⚠️ `$url`ِ آلرتر تنها استثناست و از `.env` می‌آید.
                 * پروتکلش در همان فایل سنجیده می‌شود.
                 */
                if (basename($path) === 'HealthAlerter.php' && $argument === '$url') {
                    continue;
                }

                $dynamic[] = basename($path).' → '.$argument;
            }
        }

        $this->assertSame(
            [],
            array_unique($dynamic),
            "مقصدِ این تماس‌ها ثابت نیست و می‌تواند SSRF بسازد:\n  "
            .implode("\n  ", array_unique($dynamic)),
        );
    }

    /** ⚠️ و آدرسِ وب‌هوک باید پروتکلش سنجیده شود، نه فقط تهی نبودنش. */
    public function test_the_webhook_url_is_scheme_checked(): void
    {
        $source = (string) file_get_contents(app_path('Services/Health/HealthAlerter.php'));
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $source);

        $this->assertMatchesRegularExpression(
            '#preg_match\(..\^https\?://#',
            $code,
            '🔴 آدرسِ وب‌هوک پروتکلش سنجیده نمی‌شود.',
        );
    }

    /**
     * 🔴 هیچ آپلودی نباید قالبِ اجراشدنی بپذیرد.
     *
     * ⚠️ SVG بی‌ضرر به نظر می‌رسد و نیست: می‌تواند `<script>` داشته باشد و
     * مرورگر اجرایش می‌کند. همین‌طور `html`، `xml` و هر پسوندِ PHP.
     *
     * بازبینیِ فاز ۷ سه دستهٔ قاعده پیدا کرد و هیچ‌کدام این‌ها را نداشتند.
     * این تست نمی‌گذارد فردا اضافه شوند.
     */
    public function test_no_upload_rule_accepts_an_executable_format(): void
    {
        $forbidden = ['svg', 'html', 'htm', 'xml', 'php', 'phtml', 'js', 'svgz'];
        $offenders = [];

        foreach (glob(app_path('Http/Requests/*.php')) ?: [] as $path) {
            $source = (string) file_get_contents($path);
            $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $source);

            preg_match_all('#mimes(?:types)?:([a-z0-9,/+.-]+)#i', $code, $matches);

            foreach ($matches[1] as $list) {
                foreach (explode(',', $list) as $extension) {
                    if (in_array(strtolower(trim($extension)), $forbidden, true)) {
                        $offenders[] = basename($path).' → '.$extension;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            array_unique($offenders),
            "این قاعده‌های آپلود قالبِ اجراشدنی می‌پذیرند:\n  ".implode("\n  ", array_unique($offenders)),
        );
    }

    /**
     * ⚠️ و محافظِ ضدِ پوچی: قاعده‌ای واقعاً پیدا شده باشد.
     *
     * 🔴 بدونِ این، تغییرِ نامِ `mimes:` در لاراول — یا انتقالِ قاعده‌ها به
     * جای دیگر — تستِ بالا را برای همیشه سبز می‌کرد.
     */
    public function test_the_upload_scan_really_finds_rules(): void
    {
        $found = 0;

        foreach (glob(app_path('Http/Requests/*.php')) ?: [] as $path) {
            $found += preg_match_all('#mimes(?:types)?:#i', (string) file_get_contents($path));
        }

        $this->assertGreaterThanOrEqual(2, $found, 'هیچ قاعدهٔ آپلودی پیدا نشد؛ محافظِ بالا پوچ است.');
    }
}
