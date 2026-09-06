<?php

namespace Tests\Feature;

use App\Casts\EncryptedJson;
use App\Logging\SecretScrubber;
use App\Models\Complex;
use App\Support\SecretValue;
use App\Support\SystemSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Tests\TestCase;

/**
 * نگه‌داریِ راز: رمزگذاریِ اعتبارنامه‌ها، لاگ، و باندلِ فرانت (R55 / M-01).
 *
 * ─── یافته‌ی بازِ مرحله‌ی اول، که اینجا بسته شد ─────────────────────────────
 * `SECURITY_AUDIT.md` مورد **M-01** را باز گذاشته بود:
 * `complexes.gateway_config` و `settings.sms_config` متنِ ساده در دیتابیس
 * بودند. آسیب‌پذیریِ از راه دور نیست، ولی **تشدیدکننده** است — هر نشتِ
 * دیتابیس مستقیماً به اعتبارنامه‌ی زنده تبدیل می‌شود.
 *
 * ⚠️ دلیلِ عقب افتادنش این بود که رفعش **مهاجرتِ داده** لازم دارد و
 * مهاجرتی که وسطِ کار قطع شود، نیمی رمزشده و نیمی ساده جا می‌گذارد. حالا
 * که R57 محافظِ مهاجرت را ساخته، این تست‌ها همان بی‌خطری را اثبات می‌کنند:
 * خواندن هر دو شکل را می‌پذیرد و نوشتن همیشه رمز می‌کند.
 */
class SecretHandlingTest extends TestCase
{
    use RefreshDatabase;

    private const GATEWAY = [
        'mellat' => ['terminal_id' => '9876543', 'username' => 'bank-user', 'password' => 'S3cretBankPass'],
    ];

    private const SMS = [
        'sms_driver' => 'kavenegar',
        'apikey' => 'kv-live-4f8b21c9e07a55d3',
        'sender' => '10008663',
        'password' => 'sms-panel-secret',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        SecretScrubber::flush();
        SystemSettings::forget();
    }

    private function complex(): Complex
    {
        return Complex::create(['type' => 'residential',
            'name' => 'مجتمع راز',
            'slug' => 'secret-'.uniqid(),
            'currency' => 'toman',
            'charge_due_day' => 10,
            'payment_gateway' => 'mellat',
            'gateway_config' => self::GATEWAY,
        ]);
    }

    /** مقدارِ خامِ ستون، بی‌واسطه از دیتابیس — بدونِ کستِ مدل. */
    private function rawGatewayConfig(int $id): string
    {
        return (string) DB::table('complexes')->where('id', $id)->value('gateway_config');
    }

    private function rawSmsConfig(): string
    {
        return (string) DB::table('settings')
            ->whereNull('complex_id')
            ->where('key', 'sms_config')
            ->value('value');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ① اعتبارنامه‌ی درگاه
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ ادعا این نیست که «مقدار برمی‌گردد» — که با متنِ ساده هم برمی‌گشت.
     *
     * ادعا این است که **ستونِ خام خوانا نیست**. بدونِ این بخش، تست با
     * کستِ `'array'`ِ قبلی هم سبز می‌ماند.
     */
    public function test_the_gateway_credentials_are_unreadable_in_the_database(): void
    {
        $complex = $this->complex();

        /*
         * ⚠️ `assertEquals` و نه `assertSame` — و این را MySQL نشان داد.
         *
         * نوعِ `json` در MySQL کلیدهای شیء را **بازمرتب می‌کند** (بر پایه‌ی
         * طول و سپس مقدار). محتوا یکی است ولی ترتیب فرق می‌کند، و
         * `assertSame` روی آرایه‌ی انجمنی به ترتیب حساس است.
         *
         * محصول به ترتیب وابسته نیست (همه‌جا با نامِ کلید خوانده می‌شود)،
         * پس ادعا هم نباید باشد.
         */
        $this->assertEquals(self::GATEWAY, $complex->fresh()->gateway_config);

        $raw = $this->rawGatewayConfig($complex->id);

        $this->assertStringNotContainsString('S3cretBankPass', $raw, 'رمزِ درگاه خام در دیتابیس است.');
        $this->assertStringNotContainsString('bank-user', $raw);
        $this->assertStringNotContainsString('9876543', $raw);
        /*
         * ⚠️ شکلِ ذخیره **پاکتِ JSON** است، نه رمزنگاشتِ خام.
         *
         * ستون با `$table->json(...)` ساخته شده و MySQL محتوایش را
         * اعتبارسنجی می‌کند؛ رمزنگاشتِ خام آنجا رد می‌شود. این را اجرای
         * واقعی روی سرور نشان داد، نه تستِ محلی — چون روی SQLite همان
         * ستون فقط TEXT است.
         */
        $this->assertTrue(EncryptedJson::isWrapped($raw), 'مقدارِ ذخیره‌شده در پاکتِ رمزشده نیست.');

        $this->assertNotNull(
            json_decode($raw, true),
            'مقدارِ ذخیره‌شده JSONِ معتبر نیست؛ روی MySQL ستونِ json ردش می‌کند.',
        );
    }

    /**
     * ⚠️ ردیفِ **قدیمیِ متنِ ساده** باید همچنان خوانده شود.
     *
     * این تحملْ همان چیزی است که مهاجرتِ بی‌قطعی را ممکن می‌کند: در فاصله‌ی
     * بینِ شروع و پایانِ مهاجرت، هر دو شکل روی دیسک‌اند.
     */
    public function test_a_legacy_plaintext_row_is_still_readable(): void
    {
        $complex = $this->complex();

        DB::table('complexes')->where('id', $complex->id)->update([
            'gateway_config' => json_encode(self::GATEWAY, JSON_UNESCAPED_UNICODE),
        ]);

        $this->assertEquals(self::GATEWAY, Complex::find($complex->id)->gateway_config);
    }

    /** و اولین ذخیره‌ی بعدی باید همان ردیف را رمز کند. */
    public function test_writing_always_re_encrypts(): void
    {
        $complex = $this->complex();

        DB::table('complexes')->where('id', $complex->id)->update([
            'gateway_config' => json_encode(self::GATEWAY, JSON_UNESCAPED_UNICODE),
        ]);

        $model = Complex::find($complex->id);
        $model->gateway_config = self::GATEWAY;
        $model->save();

        $this->assertTrue(EncryptedJson::isWrapped($this->rawGatewayConfig($complex->id)));
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ② اعتبارنامه‌ی پیامک
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_sms_credentials_are_unreadable_in_the_database(): void
    {
        SystemSettings::set('sms_config', self::SMS);

        $this->assertSame(self::SMS, SystemSettings::getJson('sms_config'));

        $raw = $this->rawSmsConfig();

        $this->assertStringNotContainsString('kv-live-4f8b21c9e07a55d3', $raw, 'کلیدِ پیامک خام در دیتابیس است.');
        $this->assertStringNotContainsString('sms-panel-secret', $raw);
        $this->assertTrue(SecretValue::isProtected($raw));
    }

    /** و کلیدِ غیرِ راز نباید بی‌جهت رمز شود — وگرنه خواندنش گران و مبهم می‌شود. */
    public function test_a_non_secret_setting_stays_plain(): void
    {
        SystemSettings::set('site_title', 'ساکنا');

        $raw = (string) DB::table('settings')
            ->whereNull('complex_id')
            ->where('key', 'site_title')
            ->value('value');

        $this->assertSame('ساکنا', $raw);
        $this->assertFalse(SystemSettings::isSecret('site_title'));
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ③ کلیدِ عوض‌شده — که نباید بی‌صدا رد شود
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ اگر `APP_KEY` عوض شود، مقدارِ رمزشده **نه** خوانده می‌شود و **نه**
     * JSONِ سالم است.
     *
     * برگرداندنِ آرایه‌ی خالی در آن حالت یعنی «درگاهی تنظیم نشده» — سامانه
     * بی‌سروصدا به پرداختِ رسیدی برمی‌گردد و هیچ‌کس نمی‌فهمد چرا. پس
     * استثنا پرتاب می‌شود، با پیامی که علت را می‌گوید.
     */
    public function test_an_unreadable_value_fails_loudly_instead_of_silently(): void
    {
        $complex = $this->complex();

        /*
         * ⚠️ شبیه‌سازی باید **پاکتِ JSONِ معتبر** باشد، با محتوای خرابِ داخلش.
         *
         * شکلِ اولِ این تست یک رشته‌ی غیرِ JSON مستقیم در ستون می‌نوشت.
         * روی SQLite کار می‌کرد و روی MySQL خودِ **درج** با
         * `3140 Invalid JSON text` رد می‌شد — یعنی تست هرگز به چیزی که
         * می‌خواست بسنجد نمی‌رسید.
         *
         * و این خودش خبرِ خوبی است: روی MySQL چنین مقداری اصلاً نمی‌تواند
         * در ستون بنشیند. حالتی که واقعاً ممکن است، پاکتی است که
         * محتوایش با کلیدِ دیگری رمز شده باشد.
         */
        DB::table('complexes')->where('id', $complex->id)->update([
            'gateway_config' => json_encode(['__enc' => 'not-a-valid-ciphertext']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_KEY/');

        Complex::find($complex->id)->gateway_config;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ④ مهاجرتِ داده
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ مهاجرت باید **تکرارپذیر** باشد.
     *
     * ─── چرا شکلِ اولِ این تست پوچ بود ───────────────────────────────────
     * اول با `migrate → rollback → migrate` نوشته بودمش. سبز بود، ولی
     * خرابکاریِ «محافظِ `isProtected` را بردار» **از دستش در رفت** — چون
     * `rollback` بینِ دو اجرا مقدار را به متنِ ساده برمی‌گرداند، پس `up()`
     * هرگز روی دادهٔ از-پیش-رمزشده اجرا نمی‌شد.
     *
     * حالا `up()` مستقیم و **دو بار پشتِ‌هم** صدا زده می‌شود؛ همان چیزی که
     * پس از قطعِ میانی و اجرای دوباره رخ می‌دهد. رمزگذاریِ دولایه، خواندن
     * را به JSONِ بی‌معنا می‌رساند.
     */
    public function test_the_migration_is_safe_to_run_twice(): void
    {
        $complex = $this->complex();

        // هر دو مقدار را به شکلِ **قدیمیِ متنِ ساده** برمی‌گردانیم
        DB::table('complexes')->where('id', $complex->id)->update([
            'gateway_config' => json_encode(self::GATEWAY, JSON_UNESCAPED_UNICODE),
        ]);

        SystemSettings::set('sms_config', self::SMS);
        DB::table('settings')->whereNull('complex_id')->where('key', 'sms_config')->update([
            'value' => json_encode(self::SMS, JSON_UNESCAPED_UNICODE),
        ]);
        SystemSettings::forget();

        $migration = require database_path('migrations/2026_08_27_000001_encrypt_stored_credentials.php');

        $migration->up();
        $migration->up();

        SystemSettings::forget();

        $this->assertEquals(self::GATEWAY, Complex::find($complex->id)->gateway_config, 'دو بار اجرا مقدار را خراب کرد.');
        $this->assertSame(self::SMS, SystemSettings::getJson('sms_config'));
        $this->assertTrue(EncryptedJson::isWrapped($this->rawGatewayConfig($complex->id)));
        $this->assertTrue(SecretValue::isProtected($this->rawSmsConfig()));
    }

    /** و برگشتش باید دقیقاً به همان متنِ سادهٔ پیشین برساند. */
    public function test_the_migration_reverses_cleanly(): void
    {
        $complex = $this->complex();

        SystemSettings::set('sms_config', self::SMS);

        $migration = require database_path('migrations/2026_08_27_000001_encrypt_stored_credentials.php');

        $migration->up();
        $migration->down();

        SystemSettings::forget();

        $this->assertEquals(
            self::GATEWAY,
            json_decode($this->rawGatewayConfig($complex->id), true),
            'برگشت، مقدارِ خام را به JSONِ سادهٔ پیشین نرساند.',
        );

        $this->assertFalse(EncryptedJson::isWrapped($this->rawGatewayConfig($complex->id)));
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ⑤ لاگ
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ نقصی که اندازه‌گیری پیدا کرد.
     *
     * `KavenegarSms` کلیدِ API را **داخلِ مسیرِ URL** می‌گذارد — طراحیِ خودِ
     * سرویس است:
     *
     *     https://api.kavenegar.com/v1/{apikey}/sms/send.json
     *
     * و درست پایینش `Log::error(... $e->getMessage())`. پیامِ استثنای HTTP
     * در Guzzle آدرسِ کامل را دارد، یعنی اولین قطعیِ شبکه کلیدِ زنده را در
     * `storage/logs` می‌نوشت.
     */
    public function test_a_secret_never_reaches_the_log_file(): void
    {
        SystemSettings::set('sms_config', self::SMS);
        SecretScrubber::flush();

        $scrubbed = (new SecretScrubber)(new LogRecord(
            new \DateTimeImmutable,
            'test',
            Level::Error,
            '[SMS:kavenegar] exception: cURL error 28 for https://api.kavenegar.com/v1/'
                .self::SMS['apikey'].'/sms/send.json',
            ['url' => 'https://api.kavenegar.com/v1/'.self::SMS['apikey'].'/sms/send.json'],
        ));

        $this->assertStringNotContainsString(
            self::SMS['apikey'],
            $scrubbed->message,
            'کلیدِ پیامک در پیامِ لاگ ماند.',
        );

        $this->assertStringNotContainsString(
            self::SMS['apikey'],
            json_encode($scrubbed->context, JSON_UNESCAPED_UNICODE),
            'کلیدِ پیامک در contextِ لاگ ماند.',
        );
    }

    /** و کلیدهای حساس در `context` باید با نامشان هم پاک شوند. */
    public function test_a_sensitive_context_key_is_masked_by_name(): void
    {
        $scrubbed = (new SecretScrubber)(new LogRecord(
            new \DateTimeImmutable,
            'test',
            Level::Warning,
            'پرداخت شکست خورد',
            ['gateway_config' => ['password' => 'anything'], 'bill_id' => 12],
        ));

        $this->assertStringNotContainsString('anything', json_encode($scrubbed->context));
        $this->assertSame(12, $scrubbed->context['bill_id'], 'داده‌ی بی‌خطر هم پاک شد؛ لاگ بی‌فایده می‌شود.');
    }

    /** و پیامِ عادی نباید دست بخورد — وگرنه لاگ خوانا نمی‌ماند. */
    public function test_an_ordinary_message_passes_through_untouched(): void
    {
        Log::info('پیامِ عادی بدونِ راز');

        $scrubbed = (new SecretScrubber)(new LogRecord(
            new \DateTimeImmutable,
            'test',
            Level::Info,
            'پیامِ عادی بدونِ راز',
            ['unit' => '۱۰۱'],
        ));

        $this->assertSame('پیامِ عادی بدونِ راز', $scrubbed->message);
        $this->assertSame(['unit' => '۱۰۱'], $scrubbed->context);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ⑥ فرانت
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ Vite **هر** متغیرِ `VITE_`دار را داخلِ باندل جاسازی می‌کند.
     *
     * یعنی اگر روزی کسی رازی را `VITE_SMS_KEY` نام بگذارد، آن راز در
     * جاوااسکریپتِ عمومی می‌نشیند و هر بازدیدکننده‌ای می‌بیندش. این تست
     * فهرست را به یک نامِ بی‌خطر محدود می‌کند.
     */
    public function test_no_secret_is_exposed_to_the_frontend(): void
    {
        $allowed = ['VITE_APP_NAME'];

        preg_match_all('/^(VITE_[A-Z0-9_]+)=/m', (string) file_get_contents(base_path('.env.example')), $matches);

        $this->assertSame(
            $allowed,
            array_values(array_unique($matches[1])),
            'متغیرِ VITE_ تازه‌ای اضافه شده؛ هر چه این پیشوند را داشته باشد در باندلِ عمومی می‌نشیند.',
        );
    }

    /**
     * و `localStorage` نباید چیزی جز راحتیِ کاربر نگه دارد.
     *
     * ⚠️ اندازه‌گیری: پنج مصرف‌کننده هست — شماره‌ی «مرا به خاطر بسپار»،
     * پوسته، جست‌وجوهای اخیر، بستنِ پیشنهادِ نصب، و یک قلابِ عمومی. هیچ‌کدام
     * توکن یا رمز نگه نمی‌دارند، و احرازِ هویت با کوکیِ `HttpOnly` است که
     * جاوااسکریپت اصلاً نمی‌بیندش.
     */
    public function test_the_browser_never_stores_a_credential(): void
    {
        $forbidden = ['token', 'password', 'secret', 'apikey', 'jwt', 'bearer'];

        foreach ($this->frontendSources() as $path => $code) {
            if (! str_contains($code, 'localStorage')) {
                continue;
            }

            foreach ($forbidden as $word) {
                $this->assertSame(
                    0,
                    preg_match('/localStorage[^\n]*'.$word.'/i', $code),
                    "«{$path}» چیزی شبیهِ «{$word}» در localStorage می‌گذارد.",
                );
            }
        }
    }

    /**
     * فایل‌های فرانت، بدونِ کامنت.
     *
     * ⚠️ پاک‌سازی لازم است: همین فایل‌ها در توضیحشان می‌نویسند «این جای
     * توکن را نمی‌گیرد». دو الگوی جدا — یکی‌کردنشان با پرچمِ `s` باعث
     * می‌شود `//.*` تا آخرِ فایل را ببلعد.
     *
     * @return array<string, string>
     */
    private function frontendSources(): array
    {
        $out = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());
            $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);
            $code = (string) preg_replace('#^\s*//.*$#m', '', $code);

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(base_path()) + 1));

            $out[$relative] = $code;
        }

        return $out;
    }

    /**
     * و سندِ چرخش باید وجود داشته باشد و دستورهایش واقعی باشند.
     *
     * ⚠️ پیامِ خطای `SecretValue` صریحاً به این سند ارجاع می‌دهد. سندی که
     * وسطِ بحران خوانده می‌شود و دستورش کار نمی‌کند، از نبودش بدتر است —
     * همان تله‌ی R42 با `backup:run`ِ ناموجود.
     */
    public function test_the_rotation_guide_is_complete_and_runnable(): void
    {
        $path = base_path('docs/SECRETS.md');

        $this->assertFileExists($path);

        $guide = (string) file_get_contents($path);

        foreach (['APP_KEY', 'gateway_config', 'sms_config', 'VITE_', 'localStorage'] as $topic) {
            $this->assertStringContainsString($topic, $guide, "سندِ رازها بخشِ «{$topic}» را ندارد.");
        }

        $available = array_keys(app(Kernel::class)->all());

        preg_match_all('/php artisan ([a-z][a-z0-9:_-]*)/', $guide, $matches);

        $this->assertNotEmpty($matches[1], 'سند هیچ دستوری توصیه نمی‌کند؛ الگو خراب است.');

        foreach (array_unique($matches[1]) as $command) {
            $this->assertContains(
                $command,
                $available,
                "سندِ رازها «php artisan {$command}» را توصیه می‌کند ولی چنین دستوری وجود ندارد.",
            );
        }
    }

    /**
     * ⚠️ این تست‌ها باید روی **MySQL** هم بدوند، نه فقط SQLite.
     *
     * ─── چرا این ادعا وجود دارد ─────────────────────────────────────────
     * نسخه‌ی اولِ رمزگذاری همه‌ی ۱۴ تست را سبز کرد و بعد **روی سرور
     * شکست**: ستونِ `gateway_config` با `$table->json(...)` ساخته شده و
     * MySQL محتوایش را اعتبارسنجی می‌کند، در حالی که همان ستون روی
     * SQLite فقط TEXT است.
     *
     * تنها چیزی که این را می‌گرفت، اجرای همین تست‌ها روی موتورِ واقعی
     * بود. این ادعا نمی‌گذارد کسی روزی آن را از jobِ MySQL بیرون بیاورد.
     */
    public function test_these_tests_also_run_against_mysql_in_ci(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
        $workflow = (string) preg_replace('/^\s*#.*$/m', '', $workflow);

        $this->assertStringContainsString(
            'SecretHandlingTest',
            $workflow,
            'jobِ MySQL دیگر این تست‌ها را نمی‌دواند؛ نقصِ نوعِ ستون دوباره نامرئی می‌شود.',
        );
    }
}
