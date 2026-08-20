<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Services\Features\FeatureFlags;
use App\Services\Health\HealthReport;
use App\Support\EnvironmentGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * جداسازیِ محیط، مدیریتِ راز و پرچمِ قابلیت (R44).
 */
class EnvironmentTest extends TestCase
{
    use RefreshDatabase;

    private ?string $realConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realConnection = config('database.default');
    }

    /** کلیدهای `KEY=` یک فایلِ env، بدونِ کامنت. */
    private function keys(string $file): array
    {
        $path = base_path($file);

        $this->assertFileExists($path);

        $raw = str_replace(chr(13).chr(10), chr(10), (string) file_get_contents($path));

        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $raw, $matches);

        return $matches[1];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  محافظِ محیط
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ مهم‌ترین تستِ این مرحله.
     *
     * ─── چه چیزی اندازه‌گیری شد ────────────────────────────────────────────
     * با `APP_ENV=production` و `APP_DEBUG=true`، یک استثنای مهارنشده پاسخی
     * **۱٫۴ مگابایتی** برگرداند که مقدارِ واقعیِ یک متغیرِ محیطیِ نشانه‌گذاری‌شده
     * در آن پیدا شد — یعنی در محصول، رمزِ دیتابیس، `APP_KEY` و کلیدِ درگاهِ
     * بانکی روی صفحه چاپ می‌شوند.
     *
     * راهِ رسیدن به این حالت کوتاه است: `.env.example` عمداً `APP_DEBUG=true`
     * دارد و هر کسی که آن را روی سرور کپی کند، همین‌جا می‌ایستد.
     */
    public function test_debug_is_forced_off_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production', 'app.debug' => true]);

        $violations = (new EnvironmentGuard)->enforce();

        $this->assertFalse(config('app.debug'), 'debug در محصول روشن ماند.');
        $this->assertNotEmpty($violations, 'تخلف گزارش نشد.');
    }

    /**
     * ⚠️ خاموش‌کردنِ بی‌صدا یعنی اشتباه برای همیشه بماند.
     *
     * محافظ به‌عمد برنامه را نمی‌ترکاند (چون آن استثنا خودش با debugِ روشن
     * رندر می‌شد و همان نشتی را راه می‌انداخت)، پس تنها راهِ دیده‌شدنِ
     * تخلف، سنجه‌ی سلامت است.
     */
    public function test_the_violation_surfaces_in_the_health_report(): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production', 'app.debug' => true]);

        (new EnvironmentGuard)->enforce();

        $check = app(HealthReport::class)->run()['checks']['config'];

        $this->assertSame(HealthReport::DEGRADED, $check['status']);
        $this->assertStringContainsString('app.debug', (string) $check['detail']);
    }

    /** خارج از محصول، debug باید دست‌نخورده بماند — وگرنه توسعه کور می‌شود. */
    public function test_debug_is_untouched_outside_production(): void
    {
        $this->app['env'] = 'local';
        config(['app.env' => 'local', 'app.debug' => true]);

        $violations = (new EnvironmentGuard)->enforce();

        $this->assertTrue(config('app.debug'));
        $this->assertSame([], $violations);
    }

    /**
     * ⚠️ محافظ باید واقعاً در بوت صدا زده شود.
     *
     * ─── شکافی که خرابکاریِ عمدی نشان داد ──────────────────────────────────
     * تست‌های بالا `enforce()` را مستقیم صدا می‌زنند، پس با پاک‌کردنِ آن خط
     * از `AppServiceProvider` **همه‌شان سبز می‌مانند** — یعنی کلاسی سالم
     * داشتیم که هیچ‌وقت اجرا نمی‌شد.
     *
     * محیطِ تست `local` است و نمی‌شود provider را دوباره با محیطِ محصول بوت
     * کرد، پس اینجا خودِ اتصال سنجیده می‌شود؛ رفتارش را تست‌های بالا
     * پوشش می‌دهند.
     */
    public function test_the_guard_is_wired_into_the_application_boot(): void
    {
        $source = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));

        /*
         * کامنت‌ها پاک می‌شوند: توضیحِ همین فایل نامِ کلاس را می‌نویسد و
         * بدونِ این، تست روی کدِ خراب هم سبز می‌ماند.
         *
         * ⚠️ دو الگوی جدا، و این اجباری است. نسخه‌ی اول هر دو را در یک
         * الگو با پرچمِ `s` گذاشته بود؛ آن پرچم `.` را شاملِ خطِ جدید
         * می‌کند، پس `//.*$` کلِ فایل را از اولین `//` به بعد می‌بلعید و
         * تست روی کدِ کاملاً درست قرمز شد.
         */
        $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
        $source = (string) preg_replace('#^\s*//.*$#m', '', $source);

        $this->assertMatchesRegularExpression(
            '/\(new EnvironmentGuard\)->enforce\(\);/',
            $source,
            'EnvironmentGuard در بوتِ برنامه صدا زده نمی‌شود.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  اعتبارسنجیِ محیط
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ `env:check` باید پیکربندیِ ناامن را ناموفق اعلام کند.
     *
     * در `deploy.yml` پیش از مهاجرت اجرا می‌شود، پس شکستش جلوی دست‌زدن به
     * دیتابیس را می‌گیرد — یعنی پیش از اینکه بازگشت گران شود.
     */
    public function test_the_environment_check_fails_on_an_unsafe_production_config(): void
    {
        config(['app.url' => 'http://localhost', 'session.secure' => false]);

        $this->artisan('env:check --env=production')->assertFailed();
    }

    /**
     * هر قاعده باید **جداگانه** سنجیده شود.
     *
     * ⚠️ تستِ بالا با چند مشکلِ هم‌زمان اجرا می‌شود و به همین دلیل
     * نمی‌تواند بگوید کدام قاعده کار می‌کند: با خاموش‌کردنِ قاعده‌ی کوکی،
     * قاعده‌ی `APP_URL` همچنان شکست می‌داد و تست سبزِ کاذب می‌ماند —
     * خرابکاریِ عمدی همین را نشان داد. اینجا هر قاعده تنها می‌ایستد.
     *
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('unsafeProductionSettings')]
    public function test_each_production_rule_stands_on_its_own(array $config, string $expected): void
    {
        // پایه‌ی امن، تا فقط همان یک قاعده بشکند
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.debug' => false,
            'app.url' => 'https://sakena.ir',
            'session.secure' => true,
            'logging.channels.single.level' => 'warning',
            'logging.channels.stack.channels' => ['daily'],
        ]);

        config($config);

        $this->artisan('env:check --env=production')
            ->expectsOutputToContain($expected)
            ->assertFailed();
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function unsafeProductionSettings(): array
    {
        return [
            'debug روشن' => [['app.debug' => true], 'APP_DEBUG'],
            'کوکیِ ناامن' => [['session.secure' => false], 'SESSION_SECURE_COOKIE'],
            'آدرسِ محلی' => [['app.url' => 'http://localhost'], 'APP_URL'],
            'لاگِ نچرخان' => [['logging.channels.stack.channels' => ['single']], 'LOG_STACK'],
            'کلیدِ خالی' => [['app.key' => ''], 'APP_KEY'],
        ];
    }

    public function test_the_environment_check_passes_on_a_safe_config(): void
    {
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://sakena.ir',
            'session.secure' => true,
            'logging.channels.single.level' => 'warning',
        ]);

        /*
         * ⚠️ اینجا `putenv()` کار نمی‌کند و همین باعث شد نسخه‌ی اولِ
         * `env:check` بازنویسی شود: مخزنِ Env لاراول مقدارِ بوت را نگه
         * می‌دارد و `env()` تغییرِ زمانِ اجرا را نمی‌بیند. یعنی هر قاعده‌ای
         * که مستقیم `env()` بخواند، اصلاً قابلِ آزمودن نیست.
         */
        config([
            'app.debug' => false,
            'logging.channels.stack.channels' => ['daily'],
        ]);

        $this->artisan('env:check --env=production')->assertSuccessful();
    }

    /** `APP_KEY` خالی در هر محیطی خطاست. */
    public function test_an_empty_app_key_is_always_a_problem(): void
    {
        config(['app.key' => '']);

        $this->artisan('env:check --env=local')->assertFailed();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  فایل‌های محیط
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ نمونه‌ها نباید از هم جدا بیفتند.
     *
     * ─── چرا این تست، مهم‌ترین محافظِ سه‌فایلی‌بودن است ──────────────────────
     * سه فایلِ نمونه‌ی نزدیک‌به‌هم، مسئله‌ی خودشان را می‌سازند: کلیدِ تازه‌ای
     * که کسی به `.env.example` اضافه می‌کند، بی‌صدا از نمونه‌ی محصول جا
     * می‌ماند. آن‌وقت روزِ استقرار، تنظیمی که در توسعه بوده روی سرور نیست
     * و علتش هیچ‌جا نوشته نشده. این تست، هزینه‌ی داشتنِ سه فایل را می‌پردازد.
     */
    public function test_every_environment_example_covers_the_same_keys(): void
    {
        $base = $this->keys('.env.example');

        foreach (['.env.production.example', '.env.staging.example'] as $file) {
            $missing = array_diff($base, $this->keys($file));

            $this->assertSame(
                [],
                array_values($missing),
                "کلیدهای زیر در «{$file}» نیستند: ".implode('، ', $missing),
            );
        }
    }

    /**
     * ⚠️ نمونه‌ی محصول نباید مقدارِ محرمانه داشته باشد.
     *
     * مقدارِ نمونه‌ی محرمانه بدترین حالت است: کسی آن را کپی می‌کند و فکر
     * می‌کند کار کرده — و بدتر، اگر روزی مقدارِ واقعی اشتباهاً اینجا نوشته
     * شود، مستقیم وارد گیت می‌شود.
     */
    public function test_the_production_example_ships_no_secrets(): void
    {
        $raw = (string) file_get_contents(base_path('.env.production.example'));
        $raw = (string) preg_replace('/^\s*#.*$/m', '', str_replace(chr(13), '', $raw));

        $secrets = [
            'APP_KEY', 'DB_PASSWORD', 'DB_USERNAME', 'SMS_API_KEY',
            'MELLAT_PASSWORD', 'MELLAT_USERNAME', 'SAMAN_MERCHANT_ID',
            'SUBSCRIPTION_GATEWAY_PASSWORD', 'SENTRY_AUTH_TOKEN', 'HEALTH_SECRET',
        ];

        foreach ($secrets as $secret) {
            $this->assertMatchesRegularExpression(
                '/^'.$secret.'=\s*$/m',
                $raw,
                "«{$secret}» در نمونه‌ی محصول مقدار دارد؛ باید خالی باشد.",
            );
        }
    }

    /** نمونه‌ی محصول باید تنظیماتِ امن داشته باشد، نه کپیِ محیطِ محلی. */
    public function test_the_production_example_is_actually_hardened(): void
    {
        $raw = (string) file_get_contents(base_path('.env.production.example'));
        $raw = (string) preg_replace('/^\s*#.*$/m', '', str_replace(chr(13), '', $raw));

        foreach ([
            'APP_ENV=production',
            'APP_DEBUG=false',
            'SESSION_SECURE_COOKIE=true',
            'LOG_STACK=daily',
        ] as $expected) {
            $this->assertMatchesRegularExpression('/^'.preg_quote($expected, '/').'$/m', $raw);
        }

        $this->assertDoesNotMatchRegularExpression('/^APP_URL=http:\/\/localhost$/m', $raw);
    }

    /**
     * ⚠️ محیطِ آزمایشی باید حالتِ آزمایشیِ درگاه را روشن داشته باشد.
     *
     * بدونِ آن، آزمونِ پرداخت روی درگاهِ **واقعی** انجام می‌شود و پولِ واقعی
     * جابه‌جا می‌کند.
     */
    public function test_the_staging_example_uses_the_payment_sandbox(): void
    {
        $raw = (string) file_get_contents(base_path('.env.staging.example'));
        $raw = (string) preg_replace('/^\s*#.*$/m', '', str_replace(chr(13), '', $raw));

        $this->assertMatchesRegularExpression('/^PAYMENT_SANDBOX_ENABLED=true$/m', $raw);
        $this->assertMatchesRegularExpression('/^APP_DEBUG=false$/m', $raw);
    }

    /**
     * ⚠️ فایلِ env هرگز نباید وارد گیت شود — ولی نمونه‌ها باید بمانند.
     *
     * الگوی متنی در `.gitignore` کافی نیست؛ اینجا از خودِ git پرسیده می‌شود.
     */
    public function test_git_ignores_the_real_env_files_but_not_the_examples(): void
    {
        foreach (['.env', '.env.staging', '.env.production', '.env.docker'] as $secret) {
            $this->assertSame(0, $this->gitCheckIgnore($secret), "«{$secret}» نادیده گرفته نمی‌شود.");
        }

        foreach (['.env.example', '.env.production.example', '.env.staging.example'] as $example) {
            $this->assertNotSame(0, $this->gitCheckIgnore($example), "«{$example}» از گیت حذف شده.");
        }
    }

    private function gitCheckIgnore(string $path): int
    {
        exec('git -C '.escapeshellarg(base_path()).' check-ignore -q '.escapeshellarg($path), $out, $code);

        return $code;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  پرچمِ قابلیت
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ نسخه‌ی اولِ این تست **همان‌گویانه** بود.
     *
     * `all()` را با `config('features.flags')` مقایسه می‌کرد — یعنی هر دو
     * طرف از یک منبع می‌خواندند و عوض‌کردنِ پیش‌فرض در config هر دو را با
     * هم عوض می‌کرد. خرابکاریِ «پیش‌فرضِ یک پرچم را برعکس کن» بی‌اثر ماند و
     * تست سبز. حالا فهرست صریح است: تغییرِ config باید اینجا شکست بدهد.
     */
    public function test_every_shipped_flag_starts_enabled(): void
    {
        $expected = [
            'messenger' => true,
            'polls' => true,
            'online_payment' => true,
            'support_bot' => true,
            'public_registration' => true,
        ];

        $this->assertSame($expected, app(FeatureFlags::class)->all());
    }

    /**
     * و سازوکارِ پیش‌فرض هم جدا سنجیده می‌شود، با پرچمی که فقط در همین
     * تست وجود دارد — تا سنجشِ رفتار به داده‌ی محصول گره نخورد.
     */
    public function test_a_declared_default_of_false_is_honoured(): void
    {
        config(['features.flags.probe_flag' => [
            'label' => 'آزمایشی',
            'default' => false,
        ]]);

        app(FeatureFlags::class)->flush();

        $this->assertFalse(app(FeatureFlags::class)->enabled('probe_flag'));
    }

    /**
     * ⚠️ تغییرِ پرچم باید **بدونِ استقرارِ دوباره** اثر کند.
     *
     * این کلِ دلیلِ وجودِ این سازوکار است. پرچمی که در `.env` بنشیند نیازمندِ
     * ویرایشِ فایل روی سرور، `config:clear` و راه‌اندازیِ دوباره‌ی PHP-FPM
     * است — یعنی یک استقرارِ کوچک، دقیقاً در بحرانی‌ترین لحظه.
     */
    public function test_a_flag_change_takes_effect_immediately(): void
    {
        $features = app(FeatureFlags::class);

        $this->assertTrue($features->enabled('online_payment'));

        $features->set('online_payment', false);

        $this->assertFalse($features->enabled('online_payment'), 'تغییر بی‌درنگ اثر نکرد.');
        $this->assertDatabaseHas('settings', [
            'complex_id' => null,
            'key' => FeatureFlags::PREFIX.'online_payment',
            'value' => '0',
        ]);
    }

    /**
     * ⚠️ کشِ پرچم‌ها باید با هر نوشتن باطل شود.
     *
     * بدونِ آن، «خاموشش کن» تا یک ساعت هیچ اثری نداشت — و آن یک ساعت
     * دقیقاً همان مدتی است که این ابزار برایش ساخته شده.
     */
    public function test_writing_a_flag_invalidates_the_cache(): void
    {
        $features = app(FeatureFlags::class);

        // کش را گرم می‌کنیم
        $features->all();

        // نوشتنِ مستقیم در دیتابیس، بدونِ باطل‌کردنِ کش
        Setting::query()->create([
            'complex_id' => null,
            'key' => FeatureFlags::PREFIX.'polls',
            'value' => '0',
        ]);

        $this->assertTrue($features->enabled('polls'), 'کش اصلاً گرم نشده بود؛ این تست بی‌اثر است.');

        $features->flush();

        $this->assertFalse($features->enabled('polls'));
    }

    /**
     * ⚠️ پرچمِ ناشناخته `true` می‌دهد، نه `false`.
     *
     * با `false`، یک غلطِ تایپی در نامِ پرچم باعث می‌شد **قابلیت بی‌صدا
     * ناپدید شود** و کسی نفهمد چرا؛ کاربر فقط می‌دید بخشی از سامانه نیست.
     * با `true` رفتار همان چیزی می‌ماند که پیش از افزودنِ پرچم بود.
     */
    public function test_an_unknown_flag_defaults_to_enabled(): void
    {
        $this->assertTrue(app(FeatureFlags::class)->enabled('flag_that_does_not_exist'));
    }

    /**
     * ⚠️ با دیتابیسِ از دسترس خارج، پرچم‌ها باید به پیش‌فرض برگردند.
     *
     * ─── چرا این تست وجود دارد ──────────────────────────────────────────────
     * افزودنِ `feature:support_bot` به گفت‌وگوی پشتیبانی — مسیری عمومی که تا
     * آن لحظه به دیتابیس دست نمی‌زد — چهار تستِ موجود را با
     * `no such table: settings` انداخت.
     *
     * همان اتفاق روی سرورِ واقعی یعنی اگر دیتابیس بیفتد، **هر مسیری که
     * پرچم دارد ۵۰۰ می‌دهد**: ابزاری که برای مدیریتِ بحران ساخته شده،
     * خودش می‌شود منبعِ خرابیِ بعدی.
     */
    public function test_flags_fall_back_to_their_defaults_when_the_store_is_unreachable(): void
    {
        app(FeatureFlags::class)->set('polls', false);

        Cache::flush();

        config([
            'database.connections.broken' => [
                'driver' => 'sqlite',
                'database' => base_path('storage/framework/testing/no-such-file.sqlite'),
                'prefix' => '',
            ],
            'database.default' => 'broken',
        ]);

        $this->assertTrue(
            app(FeatureFlags::class)->enabled('polls'),
            'با دیتابیسِ مرده، خواندنِ پرچم استثنا داد به‌جای بازگشت به پیش‌فرض.',
        );
    }

    /** نوشتنِ پرچمِ تعریف‌نشده باید رد شود. */
    public function test_an_undefined_flag_cannot_be_written(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(FeatureFlags::class)->set('not_a_real_flag', false);
    }

    /** کلیدِ به‌جامانده از پرچمی که حذف شده نباید در خروجی بیاید. */
    public function test_a_stale_stored_key_is_not_reported(): void
    {
        Setting::query()->create([
            'complex_id' => null,
            'key' => FeatureFlags::PREFIX.'removed_feature',
            'value' => '0',
        ]);

        $this->assertArrayNotHasKey('removed_feature', app(FeatureFlags::class)->all());
    }

    // ─────────────────────────────────────────────────────────────────────
    //  اعمالِ پرچم
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ پرچمِ خاموش باید مسیرِ سرور را ببندد، نه فقط دکمه را پنهان کند.
     *
     * تبِ بازِ کاربر، اسکریپت، و نسخه‌ی کش‌شده‌ی فرانت هر سه بدونِ دیدنِ
     * دکمه به همان مسیر می‌رسند.
     */
    public function test_a_disabled_feature_blocks_its_route(): void
    {
        /*
         * ⚠️ مسیر عمداً زیرِ `api/` است.
         *
         * نسخه‌ی اولِ این تست `/probe-feature` را ثبت کرد و **همیشه ۲۰۰**
         * گرفت — چون روتِ فراگیرِ `/{path}` در `web.php` زودتر ثبت شده و
         * هر مسیری را با `view('spa')` جواب می‌دهد. یعنی میان‌افزار اصلاً
         * اجرا نمی‌شد و تست چیزی را نمی‌سنجید. همان الگو در R43 هم برای
         * `/up` پیش آمد؛ روتِ فراگیر فقط `api`، `build` و `storage` را
         * کنار می‌گذارد.
         */
        Route::middleware('feature:polls')
            ->get('/api/probe-feature', fn () => response()->json(['ok' => true]));

        $this->getJson('/api/probe-feature')->assertOk();

        app(FeatureFlags::class)->set('polls', false);

        $this->getJson('/api/probe-feature')
            ->assertStatus(403)
            ->assertJsonPath('code', 'feature_disabled');
    }

    /** ثبت‌نامِ عمومی واقعاً پشتِ پرچم است. */
    public function test_registration_is_behind_its_flag(): void
    {
        app(FeatureFlags::class)->set('public_registration', false);

        $this->postJson('/api/v1/register', [])
            ->assertStatus(403)
            ->assertJsonPath('feature', 'public_registration');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  مسیرهای API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ مسیرِ عمومی نباید شرح و پیش‌فرضِ داخلی را بدهد.
     *
     * آن متن‌ها برای تیم نوشته شده‌اند و بعضی‌شان می‌گویند خاموش‌کردنِ کدام
     * قابلیت چه چیزی را باز می‌کند — که نقشه‌ای است برای کسی که دنبالِ
     * ضعف می‌گردد.
     */
    public function test_the_public_flag_endpoint_returns_only_booleans(): void
    {
        $response = $this->getJson('/api/v1/features')->assertOk();

        foreach ($response->json('data') as $key => $value) {
            $this->assertIsBool($value, "«{$key}» بولین نیست.");
        }

        $this->assertStringNotContainsString('description', $response->getContent());
    }

    /** تغییرِ پرچم فقط کارِ سوپرادمین است. */
    public function test_only_a_super_admin_can_change_a_flag(): void
    {
        $this->putJson('/api/v1/system/features/polls', ['enabled' => false])
            ->assertStatus(401);

        // ⚠️ `is_active` لازم است: کاربرِ غیرفعال پیش از بررسیِ نقش ۴۰۳ می‌گیرد
        // و آن‌وقت تست به‌ازای دلیلِ اشتباه سبز می‌شد
        $resident = User::factory()->create(['role' => UserRole::Tenant->value, 'is_active' => true]);

        $this->actingAs($resident)
            ->putJson('/api/v1/system/features/polls', ['enabled' => false])
            ->assertForbidden();

        $this->assertTrue(app(FeatureFlags::class)->enabled('polls'), 'پرچم با وجودِ رد شدن عوض شد.');

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin->value, 'is_active' => true]);

        $this->actingAs($admin)
            ->putJson('/api/v1/system/features/polls', ['enabled' => false])
            ->assertOk();

        $this->assertFalse(app(FeatureFlags::class)->enabled('polls'));
    }

    /** کلیدِ تعریف‌نشده از مسیرِ API هم باید رد شود، با ۴۲۲ نه ۵۰۰. */
    public function test_the_api_rejects_an_undefined_flag(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin->value, 'is_active' => true]);

        $this->actingAs($admin)
            ->putJson('/api/v1/system/features/not_a_real_flag', ['enabled' => false])
            ->assertStatus(422);

        $this->assertDatabaseMissing('settings', ['key' => FeatureFlags::PREFIX.'not_a_real_flag']);
    }

    protected function tearDown(): void
    {
        Cache::flush();

        /*
         * ⚠️ پیش از `parent::tearDown()`: بازگشتِ تراکنشِ `RefreshDatabase`
         * همان‌جا انجام می‌شود و اتصالِ پیش‌فرض را در همان لحظه حل می‌کند.
         * اگر اتصالِ شکسته هنوز پیش‌فرض باشد، خودِ بازگشت می‌ترکد (همان
         * چیزی که در R43 کلِ یک کلاسِ تست را خواباند).
         */
        config(['database.default' => $this->realConnection]);

        parent::tearDown();
    }
}
