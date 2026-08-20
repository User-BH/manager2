<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * درستیِ مستندات (R46).
 *
 * ─── چرا تست، و نه فقط نوشتنِ مستنداتِ بیشتر ─────────────────────────────────
 * ⚠️ این پروژه یازده فایلِ مستند و بیش از ۵٬۶۰۰ خط توضیح دارد. مشکل «کمبودِ
 * مستندات» نبود؛ اندازه‌گیری شد که بخشی از همان‌ها **دروغ** می‌گویند:
 *
 *   • یک دستورِ artisan که وجود ندارد
 *   • ۱۳ مسیر از ۶۶ مسیرِ نوشته‌شده، وجودِ خارجی ندارند
 *   • ۳۶ مسیر از ۱۲۵ مسیرِ واقعیِ API در سندِ OpenAPI نیستند
 *   • شمارشِ تست‌ها سه تا هفت برابر کمتر از واقعیت
 *
 * و مستندِ نادرست **بدتر از نبودنِ مستند** است: کسی که مستند ندارد کد را
 * می‌خواند؛ کسی که مستندِ غلط دارد، ساعت‌ها دنبالِ چیزی می‌گردد که وجود
 * ندارد و در نهایت به کلِ مستندات بی‌اعتماد می‌شود.
 *
 * نوشتنِ متنِ تازه این را حل نمی‌کند — متنِ تازه هم شش ماه بعد کهنه می‌شود.
 * چیزی که حل می‌کند این است: هر ادعای **قابلِ‌سنجش** در مستندات، سنجیده
 * شود.
 */
class DocumentationTest extends TestCase
{
    /**
     * دارایی‌هایی که عمداً هنوز ساخته نشده‌اند.
     *
     * ⚠️ فهرست **صریح** است و نه یک الگوی کلی مثل «`public/videos/` را رد
     * کن». هر ردیفش یک تصمیمِ ثبت‌شده است: کارفرما ویدیوی دمو را به بعد
     * موکول کرده و README هم همین را می‌نویسد («تا وقتی این فایل نیست…»).
     *
     * الگوی کلی، مسیرهای واقعاً غلط را هم بی‌صدا می‌بخشید؛ این فهرست باید
     * وقتی فایل ساخته شد کوتاه شود.
     */
    private const PENDING_ASSETS = [
        'public/videos/demo.mp4',
    ];

    /** فایل‌هایی که ادعاهایشان سنجیده می‌شود. */
    private const DOCS = [
        'README.md',
        'docs/DEPLOYMENT.md',
        'docs/DEVELOPER_GUIDE.md',
        'docs/BACKEND_STRUCTURE.md',
        'docs/FRONTEND_STRUCTURE.md',
    ];

    /**
     * متنِ همه‌ی مستنداتِ سنجیدنی، به‌هم چسبیده.
     *
     * ⚠️ `WORK_PLAN.md` و `PROJECT_MEMORY.md` عمداً بیرون‌اند. آن‌ها
     * **تاریخچه**‌اند نه راهنما، و پر از جمله‌هایی مثل «`backup:run` وجود
     * ندارد» یا «این مسیر حذف شد». سنجیدنشان یعنی درست‌نویسیِ تاریخ را
     * خطا اعلام کنیم — همان تله‌ای که یک بار در تحلیلِ خودِ این مرحله هم
     * افتادم.
     */
    private function documentation(): string
    {
        $text = '';

        foreach (self::DOCS as $doc) {
            $path = base_path($doc);

            $this->assertFileExists($path, "مستندِ «{$doc}» وجود ندارد.");

            $text .= str_replace(chr(13).chr(10), chr(10), (string) file_get_contents($path))."\n";
        }

        return $text;
    }

    /**
     * ⚠️ هر دستورِ artisanِ نوشته‌شده باید وجود داشته باشد.
     *
     * ─── باگی که این گرفت ──────────────────────────────────────────────────
     * README دستورِ `php artisan reminders:charges` را معرفی می‌کرد و
     * می‌گفت «برای قبوض معوق به شماره‌ی ساکن **پیامک** می‌فرستد». آن دستور
     * وجود نداشت (نامِ واقعی `bills:remind` است) و آن رفتار هم عمداً حذف
     * شده بود: طبق قاعده‌ی محصول، پیامک فقط برای کدِ یک‌بارمصرف است.
     *
     * یعنی مستند هم دستورِ اشتباه می‌داد و هم قابلیتی را تبلیغ می‌کرد که
     * وجود نداشت و نباید هم داشته باشد.
     */
    public function test_every_documented_artisan_command_exists(): void
    {
        $available = array_keys(app(Kernel::class)->all());

        preg_match_all('/artisan ([a-z][a-z0-9:_-]*)/', $this->documentation(), $matches);

        foreach (array_unique($matches[1]) as $command) {
            $this->assertContains(
                $command,
                $available,
                "مستندات دستورِ «php artisan {$command}» را معرفی می‌کند ولی وجود ندارد.",
            );
        }
    }

    /** هر اسکریپتِ npmِ نوشته‌شده باید در `package.json` باشد. */
    public function test_every_documented_npm_script_exists(): void
    {
        $package = json_decode((string) file_get_contents(base_path('package.json')), true);
        $scripts = array_keys($package['scripts'] ?? []);

        preg_match_all('/npm run ([a-z][a-z0-9:_-]*)/', $this->documentation(), $matches);

        foreach (array_unique($matches[1]) as $script) {
            $this->assertContains(
                $script,
                $scripts,
                "مستندات «npm run {$script}» را معرفی می‌کند ولی چنین اسکریپتی نیست.",
            );
        }
    }

    /**
     * ⚠️ هر مسیرِ فایلی که در بک‌تیک نوشته شده باید وجود داشته باشد.
     *
     * ─── چه چیزی اندازه‌گیری شد ────────────────────────────────────────────
     * ۱۳ مسیر از ۶۶ مسیرِ نوشته‌شده وجود نداشتند — همه‌شان ساختارِ **پیش
     * از** بازچینشِ فرانت (`resources/js/lib/`، `resources/js/stores/`،
     * `app/main.tsx`…). یعنی توسعه‌دهنده‌ی تازه‌وارد دنبالِ پوشه‌هایی
     * می‌گشت که ماه‌ها پیش جابه‌جا شده بودند.
     */
    public function test_every_documented_path_exists(): void
    {
        preg_match_all(
            '#`((?:app|resources|config|routes|database|tests|docs|public|scripts|\.github)/[A-Za-z0-9_./-]+)`#',
            $this->documentation(),
            $matches,
        );

        $missing = [];

        foreach (array_unique($matches[1]) as $path) {
            if (in_array($path, self::PENDING_ASSETS, true)) {
                continue;
            }

            if (! file_exists(base_path($path))) {
                $missing[] = $path;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'مستندات به این مسیرها ارجاع می‌دهند ولی وجود ندارند: '.implode('، ', $missing),
        );
    }

    /**
     * ⚠️ سندِ OpenAPI باید با مسیرهای واقعی هم‌خوان باشد.
     *
     * ─── چه چیزی اندازه‌گیری شد ────────────────────────────────────────────
     * سندِ کامیت‌شده ۸۹ مسیر داشت در حالی که برنامه ۱۲۵ مسیر دارد —
     * **۳۶ مسیر (۲۹٪) مستندنشده**.
     *
     * و این بدترین حالتِ کهنگی است، چون سند **خودکار تولید می‌شود**: تنها
     * دلیلش این بود که کسی پس از افزودنِ مسیرها دوباره اجرایش نکرد. چیزی
     * که ماشین می‌تواند بسازد نباید به یادِ آدم سپرده شود.
     *
     * اگر این تست قرمز شد، کافی است `php artisan openapi:generate` را
     * بزنید و نتیجه را کامیت کنید.
     */
    public function test_the_openapi_document_matches_the_real_routes(): void
    {
        $path = public_path('openapi.json');

        $this->assertFileExists($path);

        $committed = json_decode((string) file_get_contents($path), true);

        /*
         * ⚠️ سند در فایلِ واقعی بازتولید می‌شود، پس نسخه‌ی کامیت‌شده پیش از
         * اجرا خوانده و در پایان برگردانده می‌شود — وگرنه خودِ تست فایل را
         * عوض می‌کرد و اجرای بعدی همیشه سبز می‌شد.
         */
        $backup = (string) file_get_contents($path);

        try {
            Artisan::call('openapi:generate');

            $fresh = json_decode((string) file_get_contents($path), true);
        } finally {
            file_put_contents($path, $backup);
        }

        $undocumented = array_diff(
            array_keys($fresh['paths'] ?? []),
            array_keys($committed['paths'] ?? []),
        );

        $this->assertSame(
            [],
            array_values($undocumented),
            count($undocumented).' مسیر در سندِ OpenAPI نیست. '
            .'`php artisan openapi:generate` را اجرا و نتیجه را کامیت کنید. '
            .'نمونه: '.implode('، ', array_slice($undocumented, 0, 5)),
        );
    }

    /**
     * ⚠️ هر متغیرِ محیطی که مستند می‌شود باید در `.env.example` باشد.
     *
     * متغیری که فقط در مستندات هست، همان «کلیدِ مرده»ی R45 است از سمتِ
     * مقابل: کاربر آن را در `.env` می‌گذارد، اثری نمی‌بیند، و نمی‌فهمد
     * اشتباه از کجاست.
     */
    public function test_every_documented_environment_variable_is_in_the_example(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        preg_match_all('/^([A-Z][A-Z0-9_]{3,})=/m', $example, $declared);
        $known = $declared[1];

        // فقط متغیرهایی که در بک‌تیک و به‌شکلِ `KEY=مقدار` نوشته شده‌اند
        preg_match_all('/`([A-Z][A-Z0-9_]{3,})=[^`]*`/', $this->documentation(), $matches);

        $unknown = [];

        foreach (array_unique($matches[1]) as $key) {
            // متغیرهای خودِ سیستم‌عامل و ابزارها سنجیده نمی‌شوند
            if (in_array($key, ['PATH', 'HOME', 'USER', 'COMPOSER_HOME'], true)) {
                continue;
            }

            if (! in_array($key, $known, true)) {
                $unknown[] = $key;
            }
        }

        $this->assertSame(
            [],
            $unknown,
            'این متغیرها مستند شده‌اند ولی در .env.example نیستند: '.implode('، ', $unknown),
        );
    }

    /**
     * ⚠️ شمارشِ تست‌ها نباید در مستندات هاردکد شود.
     *
     * ─── چرا این قاعده، و نه «عدد را درست کن» ───────────────────────────────
     * README می‌گفت «بک‌اند ۲۷۷ تست» و «فرانت‌اند ۴۳ تست»؛ عددِ واقعی ۸۴۵
     * و ۲۹۰ بود. درست‌کردنِ عدد فقط تا کامیتِ بعدی درست می‌ماند.
     *
     * عددی که با هر تغییر عوض می‌شود، جایش در متنِ ثابت نیست. مستند باید
     * بگوید **چطور** بشماری، نه چند تا هست.
     */
    public function test_the_documentation_does_not_hardcode_test_counts(): void
    {
        $text = (string) preg_replace('/^\s*(<!--.*?-->)/ms', '', $this->documentation());

        $this->assertSame(
            0,
            preg_match('/(\d[\d,٬]*)\s*(?:تست|test)(?:\s|$)/u', $text, $match),
            'مستندات تعدادِ تست را هاردکد کرده («'.($match[0] ?? '').'»)؛ '
            .'این عدد با هر کامیت کهنه می‌شود.',
        );
    }

    /**
     * ⚠️ مستندات نباید پیامکِ غیر از کدِ یک‌بارمصرف را تبلیغ کند.
     *
     * ─── چرا این تست وجود دارد ──────────────────────────────────────────────
     * قاعده‌ی محصول این است که پیامک **فقط** برای کدِ یک‌بارمصرف است (به‌جز
     * سهمیه‌ی ماهانه‌ی مدیرِ مجتمع). README دستوری را معرفی می‌کرد که
     * «برای قبوض معوق به شماره‌ی ساکن پیامک می‌فرستد» — قابلیتی که عمداً
     * حذف شده بود.
     *
     * چنین جمله‌ای فقط غلط نیست؛ انتظاری در خواننده می‌سازد که محصول
     * نمی‌خواهد برآورده کند.
     */
    public function test_the_documentation_does_not_promise_forbidden_sms(): void
    {
        /*
         * ⚠️ آشکارساز عمداً **باریک** است، و این را یک اجرای واقعی تحمیل کرد.
         *
         * نسخه‌ی اول هر خطی را که کلمه‌ی «پیامک» داشت مشکوک می‌دانست و پنج
         * خطِ کاملاً درست را گرفت: پنلِ انتخابِ سامانه‌ی پیامک، جدولِ
         * محدودیتِ نرخ، و راهنمای ورود با کد. همه‌ی این‌ها زیرساختِ همان
         * کدِ یک‌بارمصرف‌اند که مجاز است.
         *
         * تستِ پرسروصدا بدتر از نبودنِ تست است: چند بار که بی‌دلیل قرمز
         * شود، کسی خاموشش می‌کند. پس چیزی سنجیده می‌شود که واقعاً ممنوع
         * است — **ادعای فرستادنِ پیامک بابتِ قبض و بدهی**، که دقیقاً همان
         * جمله‌ای بود که در README پیدا شد.
         */
        $lines = explode("\n", $this->documentation());
        $offenders = [];

        foreach ($lines as $number => $line) {
            $claimsSending = str_contains($line, 'پیامک')
                && preg_match('/می‌فرستد|ارسال می‌شود|فرستاده می‌شود/u', $line) === 1;

            $aboutBilling = preg_match('/قبض|قبوض|بدهی|سررسید|معوق/u', $line) === 1;

            if (! $claimsSending || ! $aboutBilling) {
                continue;
            }

            // سهمیه‌ی ماهانه‌ی مدیر مجتمع تنها استثنای مجاز است
            if (str_contains($line, 'سهمیه')) {
                continue;
            }

            $offenders[] = 'خط '.($number + 1).': '.mb_substr(trim($line), 0, 70);
        }

        $this->assertSame(
            [],
            $offenders,
            "مستندات پیامکی را وعده می‌دهد که محصول نمی‌فرستد:\n".implode("\n", $offenders),
        );
    }

    /**
     * هر مستندی که در فهرستِ بالا آمده باید واقعاً محتوا داشته باشد.
     *
     * فایلِ خالی یا چندخطی، بدتر از نبودنش است: در فهرست دیده می‌شود،
     * کسی بازش می‌کند، و چیزی پیدا نمی‌کند.
     */
    public function test_no_documented_guide_is_a_stub(): void
    {
        foreach (self::DOCS as $doc) {
            $lines = count(file(base_path($doc)) ?: []);

            $this->assertGreaterThan(
                30,
                $lines,
                "مستندِ «{$doc}» فقط {$lines} خط دارد؛ عملاً خالی است.",
            );
        }
    }

    /**
     * پیوندهای داخلیِ میانِ مستندات نباید بشکنند.
     *
     * پیوندِ شکسته همان مسیرِ ناموجود است با یک کلیک فاصله — و چون در
     * نمایشِ گیت‌هاب به صفحه‌ی ۴۰۴ می‌رود، بی‌اعتمادی‌اش بیشتر هم هست.
     */
    public function test_internal_documentation_links_resolve(): void
    {
        $broken = [];

        foreach (array_merge(self::DOCS, ['docs/PROJECT_MEMORY.md', 'docs/WORK_PLAN.md']) as $doc) {
            $directory = dirname(base_path($doc));

            preg_match_all(
                '/\]\((?!https?:|#|mailto:)([^)#]+)(?:#[^)]*)?\)/',
                (string) file_get_contents(base_path($doc)),
                $matches,
            );

            foreach ($matches[1] as $target) {
                if (! file_exists($directory.'/'.trim($target))) {
                    $broken[] = "{$doc} → {$target}";
                }
            }
        }

        $this->assertSame([], $broken, "پیوندهای شکسته:\n".implode("\n", $broken));
    }

    /**
     * راهنمای توسعه‌دهنده باید دستورهای واقعیِ روزمره را داشته باشد.
     *
     * ⚠️ این تست عمداً روی **رفتار** لنگر می‌اندازد نه روی متن: اگر روزی
     * نامِ اسکریپتی عوض شود، تستِ «هر اسکریپتِ مستندشده باید وجود داشته
     * باشد» می‌گیردش، و این یکی مطمئن می‌شود که اصلاً نوشته شده باشد.
     */
    public function test_the_developer_guide_covers_the_daily_commands(): void
    {
        $guide = (string) file_get_contents(base_path('docs/DEVELOPER_GUIDE.md'));

        foreach (['npm run dev', 'npm run test', 'php artisan test', 'npm run lint'] as $command) {
            $this->assertStringContainsString(
                $command,
                $guide,
                "راهنمای توسعه‌دهنده «{$command}» را توضیح نمی‌دهد.",
            );
        }
    }

    /**
     * هر مستندِ داخلِ `docs/` باید از جایی قابلِ‌رسیدن باشد.
     *
     * مستندی که هیچ‌جا به آن پیوند نخورده، عملاً وجود ندارد — کسی پیدایش
     * نمی‌کند مگر اینکه پوشه را دستی بگردد.
     */
    public function test_every_guide_is_reachable_from_the_readme(): void
    {
        $readme = (string) file_get_contents(base_path('README.md'));
        $orphans = [];

        foreach (File::files(base_path('docs')) as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }

            if (! str_contains($readme, 'docs/'.$file->getFilename())) {
                $orphans[] = $file->getFilename();
            }
        }

        $this->assertSame(
            [],
            $orphans,
            'این مستندها از README قابلِ‌رسیدن نیستند: '.implode('، ', $orphans),
        );
    }
}
