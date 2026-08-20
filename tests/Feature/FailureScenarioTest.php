<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\BuildBackupJob;
use App\Models\Backup;
use App\Models\User;
use App\Services\Features\FeatureFlags;
use App\Services\Health\HealthReport;
use App\Support\PrivateFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * رفتارِ سامانه وقتی چیزی می‌شکند (R47).
 *
 * ─── چرا این تست‌ها جدا از بقیه‌اند ──────────────────────────────────────────
 * بقیه‌ی مجموعه می‌سنجد که سامانه در **حالتِ سالم** درست کار کند. اینجا
 * چیزِ دیگری سنجیده می‌شود: وقتی دیتابیس، کش، صف یا درگاهِ بانکی از دسترس
 * خارج می‌شوند، خرابی چه شکلی به کاربر می‌رسد.
 *
 * ⚠️ فرقِ یک سامانه‌ی قابلِ‌اتکا با یک سامانه‌ی شکننده در همین است. هر دو
 * وقتی همه‌چیز خوب است کار می‌کنند؛ تفاوت در لحظه‌ی خرابی معلوم می‌شود —
 * اینکه پیامِ روشنی بدهد یا صفحه‌ی سفید، و اینکه یک خرابیِ کوچک همان‌جا
 * بماند یا کلِ سامانه را با خود پایین بکشد.
 */
class FailureScenarioTest extends TestCase
{
    use RefreshDatabase;

    private ?string $realConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        // ⚠️ بدونِ این، فایل‌های آزمونِ بازیابی در storageِ واقعی می‌نشینند
        Storage::fake(PrivateFiles::name());

        $this->realConnection = config('database.default');
    }

    protected function tearDown(): void
    {
        // ⚠️ پیش از parent: بازگشتِ تراکنشِ RefreshDatabase اتصالِ پیش‌فرض را
        // در همان لحظه حل می‌کند و با اتصالِ شکسته خودش می‌ترکد
        config(['database.default' => $this->realConnection]);

        parent::tearDown();
    }

    /**
     * دیتابیس را از دسترس خارج می‌کند بی‌آنکه تراکنشِ تست را بشکند.
     *
     * اتصالِ اصلی دست‌نخورده می‌ماند و فقط پیش‌فرض به یک اتصالِ شکسته‌ی
     * تازه می‌چرخد.
     */
    private function breakDatabase(): void
    {
        config([
            'database.connections.broken' => [
                'driver' => 'sqlite',
                'database' => base_path('storage/framework/testing/no-such-file.sqlite'),
                'prefix' => '',
            ],
            'database.default' => 'broken',
        ]);
    }

    private function activeUser(): User
    {
        return User::factory()->create([
            'role' => UserRole::SuperAdmin,
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  از دست رفتنِ دیتابیس
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ با دیتابیسِ مرده، بررسیِ سلامت باید ۵۰۳ بدهد — نه ۲۰۰.
     *
     * این همان دروغی بود که در R43 پیدا شد و چون دروازه‌ی بازگشتِ استقرار
     * است، اینجا دوباره و از زاویه‌ی «سناریوی شکست» سنجیده می‌شود.
     */
    public function test_the_service_reports_itself_down_when_the_database_dies(): void
    {
        $this->breakDatabase();

        $this->get('/up')->assertStatus(503);
    }

    /**
     * ⚠️ صفحه‌ی فرود با دیتابیسِ مرده باید **تمیز** بیفتد، نه سالم بماند.
     *
     * ─── فرضیه‌ای که رد شد ──────────────────────────────────────────────────
     * نسخه‌ی اولِ این تست ادعا می‌کرد صفحه‌ی فرود باید زنده بماند، چون
     * عملاً ایستاست. اجرا شد و ۵۰۰ داد. ردیابی نشان داد استثنا هنگامِ
     * **رندرِ ویو** رخ می‌دهد: `csrf_token()` و `@auth` در چیدمان نشست را
     * باز می‌کنند و نشستِ این پروژه روی **دیتابیس** است.
     *
     * پس آن ادعا از اساس غلط بود: هیچ صفحه‌ای در این معماری بدونِ
     * دیتابیس سرو نمی‌شود.
     *
     * ─── چرا عمداً اصلاح نشد ─────────────────────────────────────────────────
     * برداشتنِ نشست از صفحه‌ی فرود، توکنِ CSRF و نمایشِ وضعیتِ ورودِ کاربر
     * را می‌شکند — یعنی قابلیتِ واقعی فدای سناریویی می‌شود که در آن کلِ
     * سامانه (نشست، کش، صف، همه روی همان دیتابیس) پایین است.
     *
     * ─── پس این تست چه چیزی را قفل می‌کند ────────────────────────────────────
     * قراردادِ واقعی: خرابی باید **بی‌نشتی و به فارسی** به کاربر برسد.
     * پیش از R47 همان لحظه قالبِ انگلیسیِ «Server Error» لاراول را
     * می‌دید.
     */
    public function test_a_dead_database_fails_cleanly_and_in_persian(): void
    {
        config(['app.debug' => false]);

        $this->breakDatabase();

        $body = (string) $this->get('/')->getContent();

        // ① هیچ جزئیاتِ داخلی بیرون نمی‌آید
        foreach (['SQLSTATE', 'no-such-file.sqlite', 'vendor'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "صفحه‌ی خطا «{$leak}» را لو داد.");
        }

        // ② و کاربر پیامی می‌بیند که می‌فهمد
        $this->assertMatchesRegularExpression(
            '/[\x{0600}-\x{06FF}]/u',
            $body,
            'صفحه‌ی خطا فارسی نیست؛ ساکن قالبِ انگلیسیِ لاراول را می‌بیند.',
        );

        $this->assertStringContainsString('dir="rtl"', $body);
    }

    /**
     * صفحه‌ی ۵۰۰ هم مثلِ ۵۰۳ باید کاملاً خودبسنده باشد.
     *
     * ⚠️ این صفحه دقیقاً وقتی رندر می‌شود که چیزی خراب است؛ هر `asset()`
     * می‌تواند همان‌جا شکست بخورد و صفحه‌ی خرابی را خراب‌تر نشان بدهد.
     */
    public function test_the_error_page_is_self_contained(): void
    {
        $html = (string) view('errors.500')->render();

        foreach (['http://', 'https://', '<script', '/build/', 'asset('] as $external) {
            $this->assertStringNotContainsString($external, $html);
        }

        $this->assertStringContainsString('lang="fa"', $html);
    }

    /**
     * ⚠️ پرچمِ قابلیت با دیتابیسِ مرده باید به پیش‌فرض برگردد، نه بترکد.
     *
     * سازوکاری که برای مدیریتِ بحران ساخته شده نباید خودش در بحران
     * منبعِ خرابیِ تازه شود (R45).
     */
    public function test_feature_flags_fall_back_when_the_database_is_gone(): void
    {
        $this->breakDatabase();

        $this->assertTrue(app(FeatureFlags::class)->enabled('messenger'));
    }

    /**
     * پیامِ خطای API نباید جزئیاتِ داخلی را بیرون بدهد.
     *
     * ⚠️ استثنای PDO رشته‌ی اتصال، نامِ کاربرِ دیتابیس و مسیرِ سرور را در
     * خود دارد. رساندنش به کاربر یعنی نقشه‌ای برای مهاجم — دقیقاً وقتی که
     * سامانه ضعیف‌ترین حالتش را دارد.
     */
    public function test_an_internal_failure_is_not_leaked_to_the_client(): void
    {
        $user = $this->activeUser();

        /*
         * ⚠️ رفتارِ **محصول** سنجیده می‌شود، نه محیطِ توسعه.
         *
         * در توسعه `APP_DEBUG` روشن است و جزئیات را عمداً نشان می‌دهد؛
         * بدونِ این خط، تست روی کدِ کاملاً درست قرمز می‌شد. آنچه باید
         * تضمین شود این است که در محصول چیزی بیرون نرود — و `EnvironmentGuard`
         * از R44 خودش آنجا debug را خاموش نگه می‌دارد.
         */
        config(['app.debug' => false]);

        $this->breakDatabase();

        /*
         * ⚠️ مسیر عمداً `system/complexes` است و نه `units`.
         *
         * نسخه‌ی اول `units` را می‌زد و خرابکاریِ «پیامِ استثنا را خام
         * بفرست» از دستش در رفت. علتش این بود که میان‌افزارِ مستأجر پیش
         * از هر کوئری ۴۰۹ («ابتدا یک مجتمع را انتخاب کنید») برمی‌گرداند —
         * یعنی پاسخی سنجیده می‌شد که اصلاً خطای دیتابیس نداشت.
         */
        $body = (string) $this->actingAs($user)->getJson('/api/v1/system/complexes')->getContent();

        foreach (['no-such-file.sqlite', 'SQLSTATE', 'vendor\\laravel', 'vendor/laravel'] as $leak) {
            $this->assertStringNotContainsString(
                $leak,
                $body,
                "پاسخِ خطا «{$leak}» را لو می‌دهد.",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  از دست رفتنِ کش و صف
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ صفِ خوابیده نباید درخواستِ کاربر را بشکند.
     *
     * ─── مرزی که این تست تعریف می‌کند ───────────────────────────────────────
     * کارهای سنگین (ساختِ بکاپ، PDF قبض‌ها) عمداً به صف رفته‌اند تا چرخه‌ی
     * درخواست کوتاه بماند. نتیجه‌اش این است که کاربر باید **بلافاصله**
     * پاسخ بگیرد، حتی اگر کارگر اصلاً بالا نباشد — و بعداً وقتی کارگر
     * برگشت، کار انجام شود.
     *
     * اگر این نگذرد یعنی «به صف بردن» فقط اسمش صف بوده.
     */
    public function test_a_stopped_worker_does_not_break_the_request(): void
    {
        Queue::fake();

        $admin = $this->activeUser();

        $this->actingAs($admin)
            ->postJson('/api/v1/system/backups')
            ->assertStatus(202);

        Queue::assertPushed(BuildBackupJob::class);

        /*
         * ⚠️ نکته‌ی اصلی همین ادعاست، نه `assertPushed`.
         *
         * `Queue::fake()` بینِ `dispatch` و `dispatchSync` فرقی نمی‌گذارد و
         * هر دو را «pushed» ثبت می‌کند — خرابکاریِ «همگام اجرا کن» با
         * `assertPushed` تنها گرفته نشد. چیزی که واقعاً باید ثابت شود این
         * است که کار در چرخه‌ی درخواست **اجرا نشده**: رکورد باید هنوز
         * `pending` باشد.
         */
        $this->assertSame(
            'pending',
            Backup::withoutGlobalScopes()->latest('id')->value('status'),
            'کارِ سنگین در چرخه‌ی درخواست اجرا شد؛ با کارگرِ خوابیده کاربر منتظر می‌ماند.',
        );
    }

    /**
     * ⚠️ محدودیتِ نرخ باید در نبودِ کش **ببندد**، نه باز کند.
     *
     * ─── چرا جهتِ شکست اینجا حیاتی است ──────────────────────────────────────
     * محدودیتِ نرخ روی کش می‌نشیند. اگر کش از دسترس خارج شود و پیاده‌سازی
     * «شکستِ باز» داشته باشد، دقیقاً در لحظه‌ای که سامانه ضعیف است، سقفِ
     * تلاشِ ورود برداشته می‌شود و حمله‌ی حدسِ رمز بی‌مانع می‌ماند.
     *
     * اینجا سنجیده می‌شود که نبودِ کش دستِ‌کم درخواست را ۵۰۰ نکند و رفتار
     * قابلِ‌پیش‌بینی بماند.
     */
    public function test_login_still_behaves_when_the_rate_limiter_store_is_empty(): void
    {
        Cache::flush();

        $this->postJson('/api/v1/login', [
            'phone' => '09120000000',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  از دست رفتنِ سرویسِ بیرونی
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ وب‌هوکِ هشدار نباید زمان‌بند را قفل کند.
     *
     * این کد از داخلِ زمان‌بند اجرا می‌شود؛ یک وب‌هوکِ بی‌پاسخ می‌تواند
     * اجرای بعدی را عقب بیندازد و کلِ زمان‌بند را بخواباند — یعنی ابزارِ
     * هشدار خودش منبعِ خرابیِ بعدی شود.
     */
    public function test_a_dead_alert_webhook_does_not_break_the_health_command(): void
    {
        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        config([
            'health.alert.webhook' => 'https://hooks.example.test/alert',
            'health.alert.throttle_minutes' => 1,
        ]);

        Cache::forget(HealthReport::HEARTBEAT_KEY);

        // زمان‌بندِ خوابیده = degraded، پس هشدار فرستاده می‌شود و وب‌هوک می‌شکند
        $this->artisan('health:check')->assertSuccessful();
    }

    /**
     * ⚠️ خطای صفحه‌ی ۵۰۳ باید بدونِ هیچ درخواستِ شبکه‌ای رندر شود.
     *
     * این صفحه دقیقاً وقتی نشان داده می‌شود که برنامه بالا **نیست**؛ هر
     * `asset()` یا فونتِ بیرونی همان‌جا شکست می‌خورد و صفحه‌ی خرابی را
     * خراب‌تر نشان می‌دهد (R43).
     */
    public function test_the_maintenance_page_needs_nothing_from_the_server(): void
    {
        $html = (string) view('errors.503')->render();

        foreach (['http://', 'https://', '<script', '/build/'] as $external) {
            $this->assertStringNotContainsString($external, $html);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  یکپارچگیِ داده هنگامِ شکست
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ بازیابیِ نیمه‌کاره نباید داده را نصفه رها کند.
     *
     * ─── چرا این بدترین حالتِ ممکن است ──────────────────────────────────────
     * بازیابی اول همه‌چیز را پاک می‌کند و بعد دوباره پر می‌کند. اگر وسطِ
     * کار خطایی رخ بدهد و تراکنش برنگردد، دیتابیس در حالتی می‌ماند که نه
     * داده‌ی قدیمی دارد نه جدید — و هیچ بکاپی هم نمی‌تواند بگوید کدام
     * ردیف‌ها رفته‌اند.
     *
     * اینجا فایلی داده می‌شود که اعتبارسنجی ردش می‌کند؛ داده باید **کاملاً
     * دست‌نخورده** بماند.
     */
    public function test_a_rejected_restore_leaves_the_data_untouched(): void
    {
        $admin = $this->activeUser();

        $before = DB::table('users')->count();

        $this->actingAs($admin)->post('/api/system/backups/restore', [
            'backup' => UploadedFile::fake()
                ->createWithContent('broken.json', '{"not":"a backup"}'),
            'confirm' => 'بازیابی',
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame($before, DB::table('users')->count(), 'بازیابیِ ردشده داده را دست زد.');
    }

    /**
     * کلیدهای خارجی باید پس از شکستِ بازیابی هم دوباره روشن شوند.
     *
     * ⚠️ بازیابی آن‌ها را موقتاً خاموش می‌کند. اگر روشن‌کردنشان در مسیرِ
     * خطا انجام نشود، دیتابیس **پس از** آن لحظه هر ردیفِ بی‌والدی را
     * می‌پذیرد و خرابیِ داده بی‌صدا انباشته می‌شود.
     */
    public function test_foreign_keys_are_back_on_after_a_failed_restore(): void
    {
        $admin = $this->activeUser();

        $this->actingAs($admin)->post('/api/system/backups/restore', [
            'backup' => UploadedFile::fake()
                ->createWithContent('broken.json', '{"tables":{"no_such_table":[]}}'),
            'confirm' => 'بازیابی',
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $enabled = DB::getDriverName() === 'sqlite'
            ? (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys
            : 1;

        $this->assertSame(1, $enabled, 'کلیدهای خارجی پس از شکستِ بازیابی خاموش ماندند.');
    }

    /**
     * ⚠️ و روشن‌کردنِ دوباره باید در `finally` باشد، نه در مسیرِ موفق.
     *
     * ─── چرا این تست ساختاری است و نه رفتاری ────────────────────────────────
     * تلاش شد شکستی وسطِ بازیابی ساخته شود تا رفتار سنجیده شود، ولی ممکن
     * نبود: کنترلر همه‌چیز را **پیش از** خاموش‌کردنِ کلیدها اعتبارسنجی
     * می‌کند و هر بارِ خرابی با ۴۲۲ برمی‌گردد.
     *
     * یعنی تستِ رفتاریِ بالا بی‌اثر است — کلیدها اصلاً خاموش نشده بودند که
     * دوباره روشن شوند. خرابکاریِ «`ON` را به `OFF` عوض کن» هم به همین
     * دلیل از دستش در رفت.
     *
     * پس چیزی سنجیده می‌شود که تضمین را واقعاً می‌سازد: قرارگرفتنِ
     * روشن‌کردن در `finally`. بدونِ آن، یک خطای غیرمنتظره وسطِ درج،
     * دیتابیس را **برای همیشه** بدونِ کلیدِ خارجی رها می‌کند و از آن به بعد
     * هر ردیفِ بی‌والدی بی‌صدا پذیرفته می‌شود.
     */
    public function test_the_foreign_key_reset_is_guaranteed_by_a_finally_block(): void
    {
        $source = (string) file_get_contents(
            app_path('Http/Controllers/Api/System/BackupController.php'),
        );

        // کامنت‌ها پاک می‌شوند تا توضیحِ همین قاعده، خودش تست را سبز نکند
        $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
        $source = (string) preg_replace('#^\s*//.*$#m', '', $source);

        $this->assertMatchesRegularExpression(
            '/\}\s*finally\s*\{[^}]*foreign_keys = ON/s',
            $source,
            'روشن‌کردنِ کلیدهای خارجی در finally نیست؛ خطای وسطِ بازیابی آن‌ها را خاموش رها می‌کند.',
        );
    }
}
