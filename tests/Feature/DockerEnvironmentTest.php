<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * قیدهای محیطِ کانتینری (R41).
 *
 * ─── چرا این‌ها تست دارند ───────────────────────────────────────────────────
 * کارفرما سه قید گذاشته: کانفیگِ nginxِ موجود دست‌کاری نشود، MySQL و
 * phpMyAdminِ نصب‌شده دست‌نخورده بمانند، و مسیرِ PHP 7.4 آسیب نبیند.
 *
 * ⚠️ نقضِ هیچ‌کدام خطا نمی‌دهد. یک عددِ پورت که از ۸۰۸۰ به ۸۰ عوض شود،
 * `docker compose up`ِ بعدی را روی سرورِ زنده می‌شکند — و آن لحظه کسی این
 * فایل را باز نمی‌کند تا بفهمد چرا. تنها راهِ نگه‌داشتنِ یک قیدِ کلامی،
 * تبدیلش به تستِ اجراشدنی است.
 */
class DockerEnvironmentTest extends TestCase
{
    private function compose(): string
    {
        $path = base_path('compose.yaml');

        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);

        /*
         * ⚠️ کامنت‌ها برداشته می‌شوند — چهارمین بار است که این تله را
         * می‌خورم (R37، R38، R40، و اینجا).
         *
         * تستِ «Redis نباید کارِ صف را دور بیندازد» روی کدِ کاملاً درست
         * قرمز شد، چون کامنتِ توضیحیِ بالای همان تنظیم می‌نویسد «با
         * `allkeys-lru`، زیرِ فشارِ حافظه…».
         */
        return (string) preg_replace('/^\s*#.*$/m', '', $source);
    }

    /**
     * پورت‌های منتشرشده، با در نظر گرفتنِ `${VAR:-default}`.
     *
     * ⚠️ الگوی اولِ من دنبالِ عددِ خام بود و هیچ‌چیزی پیدا نکرد، چون همه‌ی
     * پورت‌ها در compose متغیرند (`${WEB_PORT:-8080}`). تستی که چیزی پیدا
     * نکند، ادعایش را نسنجیده — پس شمارشِ نتیجه هم بررسی می‌شود.
     *
     * @return array<int,array{host:string,published:string,raw:string}>
     */
    private function publishedPorts(): array
    {
        /*
         * ⚠️ رشته‌ی **تک‌کوتیشنی** لازم است.
         *
         * در رشته‌ی دو-کوتیشنیِ PHP، `\$` به `$` تبدیل می‌شود و رجکس آن را
         * «پایانِ خط» می‌فهمد — الگو هیچ‌وقت چیزی پیدا نمی‌کرد و تست با
         * «هیچ پورتی پیدا نشد» می‌افتاد.
         */
        preg_match_all(
            '/-\s*\'([\d.]+):(\$\{\w+:-(\d+)\}|\d+):(\d+)\'/',
            $this->compose(),
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(fn (array $match) => [
            'host' => $match[1],
            // اگر متغیر بود، مقدارِ پیش‌فرضش سنجیده می‌شود
            'published' => $match[3] !== '' ? $match[3] : $match[2],
            'raw' => $match[0],
        ], $matches);
    }

    /**
     * ⚠️ هیچ پورتی نباید روی پورتِ استانداردِ سرویسِ موجود منتشر شود.
     *
     * ۸۰ و ۴۴۳ مالِ nginxِ سرور است، ۳۳۰۶ مالِ MySQLِ نصب‌شده، و ۶۳۷۹
     * مالِ Redisِ احتمالی. اگر کانتینر یکی از این‌ها را بگیرد، سرویسِ
     * زنده بالا نمی‌آید.
     */
    public function test_no_container_claims_a_standard_service_port(): void
    {
        $ports = $this->publishedPorts();

        // سه سرویس پورت منتشر می‌کنند: web، database، cache
        $this->assertCount(3, $ports, 'تعدادِ پورت‌های منتشرشده عوض شده؛ ساختار را ببینید.');

        foreach ($ports as $port) {
            $this->assertNotContains(
                (int) $port['published'],
                [80, 443, 3306, 3307, 6379, 8000],
                "پورتِ «{$port['published']}» با سرویسِ موجودِ سرور تصادم می‌کند: {$port['raw']}",
            );
        }
    }

    /**
     * ⚠️ هر پورت باید فقط روی `127.0.0.1` منتشر شود.
     *
     * ─── چرا این از پورتِ غیرِ استاندارد هم مهم‌تر است ────────────────────
     * Docker برای پورتِ منتشرشده قاعده‌ی iptables می‌سازد که **پیش از**
     * زنجیره‌ی فایروالِ میزبان اعمال می‌شود. یعنی `ufw deny` یا هر قاعده‌ی
     * دیگری که ادمین گذاشته، دور زده می‌شود و سرویس روی اینترنت باز
     * می‌ماند — بدونِ اینکه در خروجیِ `ufw status` چیزی دیده شود.
     *
     * دیتابیس و Redisِ باز روی اینترنت، بدترین حالتِ ممکن است.
     */
    public function test_every_published_port_binds_to_loopback_only(): void
    {
        $ports = $this->publishedPorts();

        $this->assertCount(3, $ports);

        foreach ($ports as $port) {
            $this->assertSame(
                '127.0.0.1',
                $port['host'],
                "پورتِ «{$port['raw']}» روی همه‌ی رابط‌ها باز است و فایروالِ میزبان را دور می‌زند.",
            );
        }
    }

    /**
     * ⚠️ رمزها هرگز نباید وارد تصویر شوند.
     *
     * پاک‌کردنشان در لایه‌ی بعدیِ Dockerfile **کمکی نمی‌کند**: لایه‌ی قبلی
     * سرِ جایش می‌ماند و هر کسی که تصویر را دارد می‌تواند بیرون بکشدش.
     * تنها راهِ درست، نرسیدنشان به context است.
     */
    public function test_secrets_never_enter_the_build_context(): void
    {
        $path = base_path('.dockerignore');

        $this->assertFileExists($path);

        /*
         * ⚠️ کامنت‌ها برداشته می‌شوند و **خطِ کامل** سنجیده می‌شود.
         *
         * `assertStringContainsString('.env', …)` در پاسِ خرابکاری پوچ
         * درآمد: وقتی خطِ `.env` را به `# .env` تبدیل کردم، رشته همچنان
         * داخلِ فایل بود (هم در همان کامنت، هم در سربرگِ توضیحی) و تست سبز
         * ماند — در حالی که رمزها دیگر از context کنار گذاشته نمی‌شدند.
         */
        $entries = array_values(array_filter(array_map(
            'trim',
            explode("\n", (string) file_get_contents($path)),
        ), fn (string $line) => $line !== '' && ! str_starts_with($line, '#')));

        foreach (['.env', 'vendor/', 'node_modules/', '.git/'] as $needle) {
            $this->assertContains(
                $needle,
                $entries,
                "«{$needle}» از contextِ بیلد کنار گذاشته نشده.",
            );
        }
    }

    /**
     * فایلِ نمونه نباید رمزِ واقعی یا پیش‌فرض داشته باشد.
     *
     * ⚠️ `APP_KEY` و رمزها عمداً **خالی**اند. پیش‌فرضِ کارکننده یعنی کسی
     * بدونِ عوض‌کردنشان بالا می‌آورد و کلیدِ رمزنگاریِ محصولش همان چیزی
     * می‌شود که در مخزنِ عمومی نوشته شده.
     */
    public function test_the_env_template_ships_no_working_secrets(): void
    {
        $template = (string) file_get_contents(base_path('.env.docker.example'));

        foreach (['APP_KEY', 'DB_PASSWORD', 'DB_ROOT_PASSWORD'] as $key) {
            $this->assertMatchesRegularExpression(
                '/^'.$key.'=\s*$/m',
                $template,
                "«{$key}» در فایلِ نمونه مقدار دارد؛ باید خالی باشد.",
            );
        }
    }

    /**
     * `.env.docker` نباید کامیت شود.
     */
    public function test_the_real_env_file_is_git_ignored(): void
    {
        $gitignore = (string) file_get_contents(base_path('.gitignore'));

        /*
         * ⚠️ الگوی اولم `^\.env(\.\*|\.docker)?$` بود و با خطِ سادهٔ
         * `.env` هم می‌خواند — یعنی سبز می‌ماند در حالی که `.env.docker`
         * اصلاً نادیده گرفته نشده بود. و واقعاً هم نشده بود.
         */
        // `\r?` لازم است چون فایل CRLF دارد و `$` پیش از `\r` نمی‌ایستد
        $this->assertMatchesRegularExpression(
            '/^(\.env\.\*|\.env\.docker)\r?$/m',
            $gitignore,
            '`.env.docker` در .gitignore نیست و رمزِ دیتابیس کامیت می‌شود.',
        );

        /*
         * ⚠️ و اثباتِ واقعی: خودِ git چه می‌گوید.
         *
         * الگوی متنی فقط می‌گوید خطی در فایل هست. اینکه git واقعاً آن فایل
         * را نادیده می‌گیرد چیزِ دیگری است — ترتیبِ قواعد، الگوی نفی، یا
         * فایلی که از قبل ردیابی شده می‌تواند نتیجه را برعکس کند.
         */
        exec('git check-ignore -q .env.docker', $output, $status);

        $this->assertSame(0, $status, 'خودِ git فایلِ `.env.docker` را نادیده نمی‌گیرد.');
    }

    /**
     * ⚠️ کارگرِ صف نباید مهاجرت بزند.
     *
     * اگر هر دو کانتینرِ `app` و `worker` هنگامِ بالاآمدن `migrate` بزنند،
     * دو پردازه هم‌زمان روی جدولِ مهاجرت می‌نویسند. `CONTAINER_ROLE` این
     * را از هم جدا می‌کند.
     */
    public function test_only_the_web_container_runs_migrations(): void
    {
        $compose = $this->compose();

        $this->assertMatchesRegularExpression('/CONTAINER_ROLE:\s*app/', $compose);
        $this->assertMatchesRegularExpression('/CONTAINER_ROLE:\s*worker/', $compose);

        $entrypoint = (string) file_get_contents(base_path('docker/entrypoint.sh'));

        $this->assertStringContainsString('CONTAINER_ROLE:-app', $entrypoint);
        $this->assertStringContainsString('artisan migrate', $entrypoint);
    }

    /**
     * افزونه‌های PHPی که وابستگی‌ها لازم دارند باید در تصویر نصب شوند.
     *
     * ⚠️ فهرست از `composer.lock` خوانده می‌شود، نه از حافظه. اگر روزی
     * پکیجی افزونه‌ی تازه‌ای بخواهد، این تست همان‌جا می‌گوید — نه اینکه
     * اولین اجرا در محصول با «Class not found» بیفتد.
     */
    public function test_the_image_installs_every_extension_the_lock_file_demands(): void
    {
        $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);

        $required = [];

        foreach ($lock['packages'] ?? [] as $package) {
            foreach (array_keys($package['require'] ?? []) as $dependency) {
                if (str_starts_with($dependency, 'ext-')) {
                    $required[] = substr($dependency, 4);
                }
            }
        }

        $required = array_unique($required);

        $this->assertNotEmpty($required);

        $dockerfile = (string) file_get_contents(base_path('docker/php/Dockerfile'));

        /*
         * ⚠️ فقط فرمانِ **نصب** سنجیده می‌شود، نه کلِ فایل.
         *
         * در پاسِ خرابکاری، `gd` را از فهرستِ `docker-php-ext-install`
         * برداشتم و تست سبز ماند — چون همان نام در خطِ
         * `docker-php-ext-configure gd …` و در کامنتِ بالایش هم هست.
         * تصویری بدونِ `gd` یعنی هر PDF و هر splash در محصول می‌ترکد.
         */
        /*
         * ⚠️ ادامه‌های خط **اول** یکی می‌شوند.
         *
         * فرمانِ نصب چندخطی است (`\` در انتهای هر خط) و فایل CRLF دارد.
         * الگوی اولم بعد از خطِ نخست می‌ایستاد و فقط
         * `docker-php-ext-install -j"$(nproc)" \` را می‌گرفت — یعنی هیچ
         * افزونه‌ای در آن نبود و تست برای همه‌شان قرمز می‌شد.
         */
        $joined = (string) preg_replace('/\\\s*\R\s*/', ' ', $dockerfile);

        $this->assertSame(
            1,
            preg_match('/docker-php-ext-install[^
]*/', $joined, $install),
            'فرمانِ نصبِ افزونه‌ها در Dockerfile پیدا نشد؛ ساختار عوض شده؟',
        );

        $installed = $install[0];

        /*
         * افزونه‌هایی که تصویرِ پایه‌ی `php:*-fpm-alpine` از قبل دارد.
         *
         * فهرست‌کردنشان در Dockerfile خطا می‌دهد («already loaded»)، پس
         * اینجا مستثنا می‌شوند — ولی صریح، نه با نادیده‌گرفتنِ خاموش.
         */
        $builtIn = [
            'ctype', 'dom', 'fileinfo', 'filter', 'hash', 'iconv', 'json',
            'libxml', 'mbstring', 'openssl', 'pcre', 'session', 'simplexml',
            'tokenizer', 'xml', 'xmlreader', 'xmlwriter', 'zlib',
        ];

        foreach ($required as $extension) {
            if (in_array($extension, $builtIn, true)) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/\b'.preg_quote($extension, '/').'\b/',
                $installed,
                "افزونه‌ی «{$extension}» را وابستگی‌ها می‌خواهند ولی Dockerfile نصبش نمی‌کند.",
            );
        }
    }

    /**
     * ⚠️ `memory_limit` نباید زیرِ ۲۵۶ مگابایت باشد.
     *
     * R28 اندازه گرفت: mPDF هنگامِ ساختِ PDFِ فارسی حدودِ ۳۰ مگابایت را
     * دائمی نگه می‌دارد و ساختِ دسته‌ای روی ۱۲۸ مگابایت با «Allowed memory
     * size exhausted» می‌افتد. این عدد یادگارِ یک شکستِ واقعی است، نه
     * احتیاط.
     */
    public function test_php_memory_limit_survives_pdf_generation(): void
    {
        $ini = (string) file_get_contents(base_path('docker/php/php.ini'));

        $this->assertSame(1, preg_match('/^memory_limit\s*=\s*(\d+)M/m', $ini, $match));
        $this->assertGreaterThanOrEqual(256, (int) $match[1]);
    }

    /**
     * ⚠️ Redis نباید کارِ صف را دور بیندازد.
     *
     * این Redis هم کش است هم صف. با `allkeys-lru`، زیرِ فشارِ حافظه هیچ
     * فرقی بین کلیدِ کش و کارِ صف‌شده نمی‌گذارد — یعنی قبضی صادر نشده یا
     * پیامکی نرفته و **هیچ‌جا خطایی ثبت نمی‌شود**.
     */
    public function test_redis_never_evicts_queued_jobs(): void
    {
        $this->assertStringContainsString('noeviction', $this->compose());
        $this->assertStringNotContainsString('allkeys-lru', $this->compose());
    }

    /**
     * سندِ استقرار باید صریح بگوید چه چیزی روی سرورِ فعلی عوض نشده.
     *
     * قیدِ کارفرما کلامی است؛ اگر جایی نوشته نشود، نفرِ بعدی که این مخزن
     * را برمی‌دارد آن را نمی‌داند.
     */
    public function test_the_deployment_doc_states_the_hands_off_constraint(): void
    {
        $doc = (string) file_get_contents(base_path('docs/DEPLOYMENT.md'));

        $this->assertStringContainsString('nginx', $doc);
        $this->assertStringContainsString('PHP 7.4', $doc);
        $this->assertStringContainsString('phpMyAdmin', $doc);
    }
}
