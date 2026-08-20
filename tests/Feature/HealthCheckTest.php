<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\BuildBackupJob;
use App\Models\Backup;
use App\Models\User;
use App\Notifications\SystemHealthNotification;
use App\Services\Health\HealthAlerter;
use App\Services\Health\HealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * بررسیِ سلامت، هشدار و بکاپِ خودکار (R43).
 */
class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    /** نامِ اتصالِ واقعی، تا در پایانِ هر تست برگردانده شود. */
    private ?string $realConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realConnection = config('database.default');
    }

    protected function tearDown(): void
    {
        /*
         * ⚠️ بازگرداندنِ اتصال باید **پیش از** `parent::tearDown()` باشد.
         *
         * `RefreshDatabase` تراکنشش را همان‌جا برمی‌گرداند و اتصالِ پیش‌فرض
         * را در همان لحظه حل می‌کند. اگر اتصالِ شکسته هنوز پیش‌فرض باشد،
         * خودِ بازگشت می‌ترکد و خطایش به تست نسبت داده می‌شود — نه به این
         * سطر که علتِ واقعی است.
         */
        config(['database.default' => $this->realConnection]);

        parent::tearDown();
    }

    /**
     * اتصالِ دیتابیس را واقعاً می‌شکند.
     *
     * ─── چرا `DB::purge` نه ────────────────────────────────────────────────
     * ⚠️ نسخه‌ی اولِ این کمکی تنظیماتِ همان اتصال را خراب می‌کرد و بعد
     * `DB::purge` می‌زد. نتیجه: اتصالی که `RefreshDatabase` تراکنشش را روی
     * آن باز کرده بود دور انداخته می‌شد و از آن به بعد **هر ۲۳ تستِ این
     * کلاس** با «cannot start a transaction within a transaction» می‌افتاد.
     *
     * راهِ درست: یک اتصالِ شکسته‌ی **تازه** ساخته می‌شود و فقط پیش‌فرض به آن
     * می‌چرخد. اتصالِ اصلی و تراکنشِ بازش دست‌نخورده می‌مانند.
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

    /**
     * ⚠️ مهم‌ترین تستِ این مرحله.
     *
     * ─── چرا وجود دارد ─────────────────────────────────────────────────────
     * مسیرِ `/up` پیش از R43 مسیرِ پیش‌فرضِ لاراول بود و فقط می‌گفت «فریم‌ورک
     * بوت شد». اندازه‌گیری شد که با دیتابیسِ کاملاً از دسترس خارج هم **۲۰۰**
     * برمی‌گرداند.
     *
     * چون همین مسیر در R42 دروازه‌ی بازگشتِ استقرار است، آن رفتار یعنی
     * استقراری که دیتابیس را از دست داده، بررسیِ سلامت را رد می‌کند، هرگز
     * برنمی‌گردد، و سایت با صفحه‌ی خطا بالا می‌ماند.
     */
    public function test_the_health_endpoint_fails_when_the_database_is_unreachable(): void
    {
        $this->breakDatabase();

        $this->get('/up')
            ->assertStatus(503)
            ->assertJson(['status' => HealthReport::DOWN]);
    }

    /**
     * با وابستگی‌های سالم، مسیر باید ۲۰۰ بدهد.
     *
     * ─── چرا `ok` مطلق ادعا نمی‌شود ────────────────────────────────────────
     * ⚠️ نسخه‌ی اولِ این تست `status === 'ok'` می‌خواست و روی همین دستگاه
     * افتاد — چون دیسکِ واقعیِ ماشین ۹۳٪ پر بود و سنجه‌ی دیسک درست کار کرده
     * بود. جعل‌کردنِ فضای دیسک برای سبزشدنِ تست یعنی خاموش‌کردنِ همان
     * سنجه‌ای که تازه ساخته‌ایم.
     *
     * پس این تست فقط چیزی را ادعا می‌کند که در اختیارش است: هیچ وابستگیِ
     * حیاتی‌ای `down` نیست و مسیر ۲۰۰ می‌دهد. وضعیتِ دیسک به محیط بستگی
     * دارد و تستِ خودش را دارد.
     */
    public function test_the_health_endpoint_passes_when_the_dependencies_are_up(): void
    {
        Cache::put(HealthReport::HEARTBEAT_KEY, now()->toIso8601String(), 3600);
        config(['health.secret' => 'secret-token']);

        $response = $this->withHeader('X-Health-Secret', 'secret-token')->get('/up');

        $response->assertOk();

        foreach (['database', 'cache', 'storage', 'queue', 'scheduler'] as $check) {
            $this->assertSame(
                HealthReport::OK,
                $response->json("checks.{$check}.status"),
                "سنجه‌ی «{$check}» سالم نیست.",
            );
        }
    }

    /**
     * ⚠️ مسیرِ سلامت نباید به روتِ فراگیرِ SPA بیفتد.
     *
     * روتِ `/{path}` هر مسیری را با `view('spa')` جواب می‌دهد و همیشه ۲۰۰
     * می‌گیرد. اگر روزی `/up` بعد از آن ثبت شود، بررسیِ سلامت دوباره همان
     * دروغی می‌شود که این مرحله برای رفعش بود — و هیچ خطایی هم نمی‌دهد.
     */
    public function test_the_health_route_is_not_swallowed_by_the_spa_catch_all(): void
    {
        $this->breakDatabase();

        $response = $this->get('/up');

        $this->assertSame(
            'application/json',
            explode(';', (string) $response->headers->get('Content-Type'))[0],
            'مسیرِ /up به‌جای گزارشِ JSON، صفحه‌ی SPA را برگرداند.',
        );
    }

    /**
     * پاسخِ عمومی نباید بگوید دقیقاً چه چیزی خراب است.
     *
     * گزارشِ کامل به مهاجم می‌گوید کدام وابستگی مرده و دیسک چقدر پر است —
     * یعنی نقشه‌ی اینکه کِی و کجا فشار بیاورد.
     */
    public function test_the_public_response_hides_the_details(): void
    {
        $this->breakDatabase();

        $response = $this->get('/up');

        $response->assertJsonMissingPath('checks');
        $this->assertSame(['status'], array_keys((array) $response->json()));
    }

    public function test_the_full_report_needs_the_secret(): void
    {
        config(['health.secret' => 'secret-token']);
        Cache::put(HealthReport::HEARTBEAT_KEY, now()->toIso8601String(), 3600);

        $this->get('/up')->assertJsonMissingPath('checks');

        $this->withHeader('X-Health-Secret', 'wrong')
            ->get('/up')
            ->assertJsonMissingPath('checks');

        $this->withHeader('X-Health-Secret', 'secret-token')
            ->get('/up')
            ->assertOk()
            ->assertJsonPath('checks.database.status', HealthReport::OK);
    }

    /**
     * بدونِ رمزِ تنظیم‌شده، گزارشِ کامل هرگز عمومی نمی‌شود.
     *
     * اگر `hash_equals` روی رشته‌ی خالی مقایسه می‌شد، هدرِ خالی یا نبودِ
     * هدر می‌توانست عبور کند و کلِ گزارش روی اینترنت باز شود.
     */
    public function test_an_empty_secret_never_unlocks_the_report(): void
    {
        config(['health.secret' => '']);

        $this->withHeader('X-Health-Secret', '')
            ->get('/up')
            ->assertJsonMissingPath('checks');
    }

    /**
     * ⚠️ پاسخ نباید کش شود.
     *
     * بدونِ این هدر، یک CDN یا پروکسیِ میانی می‌تواند «سالم» را نگه دارد و
     * همان را در تمامِ مدتِ قطعی تحویل بدهد — یعنی دقیقاً وقتی بررسیِ سلامت
     * باید حرف بزند، ساکت می‌ماند.
     */
    public function test_the_health_response_is_never_cached(): void
    {
        $header = (string) $this->get('/up')->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $header);
    }

    /**
     * ⚠️ «دیسکِ پر» نباید گره را از مدار خارج کند.
     *
     * ─── چرا این تفکیک حیاتی است ───────────────────────────────────────────
     * دیسک روی همه‌ی گره‌ها هم‌زمان پر می‌شود. اگر `degraded` هم ۵۰۳ بگیرد،
     * متعادل‌کننده‌ی بار همه را با هم از مدار خارج می‌کند و یک هشدارِ ظرفیت
     * تبدیل به قطعیِ کاملِ سامانه می‌شود — قطعی‌ای که خودِ ابزارِ پایش ساخته
     * است.
     */
    public function test_a_degraded_check_still_returns_two_hundred(): void
    {
        // ضربان عمداً نوشته نمی‌شود: زمان‌بندِ خوابیده یک `degraded` واقعی است
        Cache::forget(HealthReport::HEARTBEAT_KEY);

        $response = $this->get('/up');

        $response->assertOk();
        $this->assertSame(HealthReport::DEGRADED, $response->json('status'));
    }

    /** زمان‌بندِ کهنه باید دیده شود، وگرنه خاموشیِ cron ماه‌ها پنهان می‌ماند. */
    public function test_a_stale_scheduler_heartbeat_is_reported(): void
    {
        Cache::put(
            HealthReport::HEARTBEAT_KEY,
            now()->subMinutes(HealthReport::SCHEDULER_STALE_MINUTES + 10)->toIso8601String(),
            3600,
        );

        $report = app(HealthReport::class)->run();

        $this->assertSame(HealthReport::DEGRADED, $report['checks']['scheduler']['status']);
    }

    public function test_the_heartbeat_command_refreshes_the_scheduler_check(): void
    {
        Cache::forget(HealthReport::HEARTBEAT_KEY);

        $this->artisan('health:heartbeat')->assertSuccessful();

        $report = app(HealthReport::class)->run();

        $this->assertSame(HealthReport::OK, $report['checks']['scheduler']['status']);
    }

    /**
     * ⚠️ هیچ سنجه‌ای حق ندارد خودِ گزارش را بترکاند.
     *
     * اگر استثنا مهار نشود، endpoint پانصد می‌شود و پنج سنجه‌ی دیگر هم دیده
     * نمی‌شوند — یعنی درست وقتی بیشترین نیاز را به گزارش داریم، گزارشی
     * نداریم.
     */
    public function test_a_broken_check_does_not_break_the_whole_report(): void
    {
        $this->breakDatabase();

        $report = app(HealthReport::class)->run();

        $this->assertSame(HealthReport::DOWN, $report['checks']['database']['status']);
        // بقیه‌ی سنجه‌ها همچنان گزارش شده‌اند
        $this->assertArrayHasKey('disk', $report['checks']);
        $this->assertArrayHasKey('storage', $report['checks']);
    }

    /**
     * پیامِ استثنا نباید خام بیرون بیاید.
     *
     * استثناهای PDO رشته‌ی اتصال، نامِ کاربر و گاهی مسیرِ سرور را در خود
     * دارند؛ گزارشی که آن‌ها را چاپ کند، خودش نشتِ اطلاعات است.
     */
    public function test_exception_details_are_truncated(): void
    {
        $this->breakDatabase();

        $detail = app(HealthReport::class)->run()['checks']['database']['detail'];

        $this->assertLessThanOrEqual(125, mb_strlen((string) $detail));
        $this->assertStringNotContainsString("\n", (string) $detail);
    }

    /**
     * ⚠️ هشدارِ تکراری نباید بارها فرستاده شود.
     *
     * با اجرای هر پانزده دقیقه، یک دیسکِ پر روزی ۹۶ هشدارِ یکسان می‌سازد —
     * و صفی که پر از هشدارِ تکراری است همان‌قدر بی‌فایده است که هشدار
     * نداشته باشی.
     */
    public function test_the_same_problem_is_not_announced_twice(): void
    {
        Notification::fake();

        User::factory()->create(['role' => UserRole::SuperAdmin->value]);

        $report = [
            'status' => HealthReport::DEGRADED,
            'checks' => ['disk' => ['status' => HealthReport::DEGRADED, 'detail' => '۸۶٪ اشغال']],
        ];

        $alerter = app(HealthAlerter::class);

        $this->assertSame(['disk'], array_keys($alerter->dispatch($report)));
        $this->assertSame([], $alerter->dispatch($report), 'هشدارِ تکراری دوباره فرستاده شد.');

        Notification::assertSentTimes(SystemHealthNotification::class, 1);
    }

    /**
     * کلیدِ خفه‌کن باید از نامِ سنجه ساخته شود، نه از متنِ شرح.
     *
     * «۸۶٪ اشغال» و «۸۷٪ اشغال» دو متنِ متفاوت‌اند ولی یک مشکل؛ با کلیدِ
     * متنی، هر درصدِ تازه دوباره زنگ می‌زد و خفه‌کن عملاً بی‌اثر می‌شد.
     */
    public function test_the_throttle_key_ignores_the_changing_detail(): void
    {
        Notification::fake();

        $alerter = app(HealthAlerter::class);

        $alerter->dispatch([
            'status' => HealthReport::DEGRADED,
            'checks' => ['disk' => ['status' => HealthReport::DEGRADED, 'detail' => '۸۶٪ اشغال']],
        ]);

        $second = $alerter->dispatch([
            'status' => HealthReport::DEGRADED,
            'checks' => ['disk' => ['status' => HealthReport::DEGRADED, 'detail' => '۹۱٪ اشغال']],
        ]);

        $this->assertSame([], $second, 'تغییرِ درصد، خفه‌کن را دور زد.');
    }

    /** سامانه‌ی سالم نباید هیچ هشداری بسازد. */
    public function test_a_healthy_report_announces_nothing(): void
    {
        Notification::fake();

        $announced = app(HealthAlerter::class)->dispatch([
            'status' => HealthReport::OK,
            'checks' => ['disk' => ['status' => HealthReport::OK, 'detail' => 'خوب']],
        ]);

        $this->assertSame([], $announced);
        Notification::assertNothingSent();
    }

    /**
     * ⚠️ شکستِ اعلان نباید کلِ هشدار را از بین ببرد.
     *
     * وقتی مشکل دقیقاً «دیتابیس مرده است» باشد، نوشتنِ اعلان در همان
     * دیتابیس هم می‌شکند. اگر استثنا مهار نشود، دستور می‌ترکد و لاگِ هشدار
     * هم نوشته نمی‌شود — یعنی در بدترین لحظه، هیچ ردی نمی‌ماند.
     */
    public function test_a_failing_notification_does_not_lose_the_alert(): void
    {
        $this->breakDatabase();

        $announced = app(HealthAlerter::class)->dispatch([
            'status' => HealthReport::DOWN,
            'checks' => ['database' => ['status' => HealthReport::DOWN, 'detail' => 'مرده']],
        ]);

        $this->assertSame(['database'], array_keys($announced));
    }

    /**
     * وب‌هوک باید مهلتِ کوتاه داشته باشد و اگر آدرسی نبود، چیزی نفرستد.
     */
    public function test_the_webhook_is_only_called_when_configured(): void
    {
        Notification::fake();
        Http::fake();

        config(['health.alert.webhook' => null]);

        app(HealthAlerter::class)->dispatch([
            'status' => HealthReport::DOWN,
            'checks' => ['queue' => ['status' => HealthReport::DOWN, 'detail' => 'مرده']],
        ]);

        Http::assertNothingSent();

        config(['health.alert.webhook' => 'https://hooks.example.test/alert']);
        Cache::flush();

        app(HealthAlerter::class)->dispatch([
            'status' => HealthReport::DOWN,
            'checks' => ['queue' => ['status' => HealthReport::DOWN, 'detail' => 'مرده']],
        ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://hooks.example.test/alert'
            && $request['status'] === HealthReport::DOWN);
    }

    /**
     * ⚠️ دستور فقط برای `down` کدِ ناموفق می‌دهد.
     *
     * اگر `degraded` هم ناموفق بود، cron برای «دیسک ۸۶٪» ایمیلِ خطا
     * می‌فرستاد — همان سیلی که باعث می‌شود آدم‌ها هشدارها را نادیده بگیرند.
     */
    public function test_the_command_only_fails_on_a_real_outage(): void
    {
        Cache::forget(HealthReport::HEARTBEAT_KEY);

        // زمان‌بندِ خوابیده = degraded، و دستور باید موفق برگردد
        $this->artisan('health:check')->assertSuccessful();

        $this->breakDatabase();

        $this->artisan('health:check')->assertFailed();
    }

    /**
     * ⚠️ در حالتِ زمان‌بندی‌شده، مشکلِ خفه‌شده نباید خروجی بسازد.
     *
     * نسخه‌ی اولِ این دستور هر بار که وضعیت `ok` نبود جدول را چاپ می‌کرد؛
     * یعنی خفه‌کن درست کار می‌کرد ولی cron هر پانزده دقیقه یک ایمیل
     * می‌فرستاد و همان سیل از مسیرِ دیگری برمی‌گشت.
     */
    public function test_the_scheduled_run_is_silent_for_an_already_announced_problem(): void
    {
        Notification::fake();
        Cache::forget(HealthReport::HEARTBEAT_KEY);

        /*
         * ⚠️ خروجی با `Artisan::output()` گرفته می‌شود، نه با
         * `doesntExpectOutput()`.
         *
         * اندازه‌گیری شد: با خرابکاریِ عمدی (چاپِ جدول در هر اجرا)،
         * `doesntExpectOutput()` همچنان سبز ماند. آن کمکی جدولی را که
         * `$this->table()` می‌نویسد نمی‌بیند، پس تستی که رویش تکیه کند
         * ادعای سکوت می‌کند بی‌آنکه بسنجدش. رشته‌ی واقعیِ خروجی چنین
         * ابهامی ندارد.
         */
        Artisan::call('health:check', ['--quiet-when-ok' => true]);
        $first = Artisan::output();

        Artisan::call('health:check', ['--quiet-when-ok' => true]);
        $second = Artisan::output();

        /*
         * نگهبانِ ضدِپوچی، و اینکه چرا روی نامِ سنجه لنگر می‌اندازد.
         *
         * ⚠️ دو بار اصلاح شد و هر دو بار خرابکاریِ «هرگز جدول چاپ نکن» از
         * دستش در رفت. اولی فقط «خروجی خالی نباشد» را می‌خواست و خطِ `warn`
         * ناخالی نگهش می‌داشت؛ دومی روی نامِ سنجه لنگر انداخت و همان خطِ
         * `warn` نامِ سنجه‌ها را فهرست می‌کند. سرستونِ جدول تنها رشته‌ای است
         * که هیچ مسیرِ دیگری چاپش نمی‌کند.
         */
        $this->assertStringContainsString('میلی‌ثانیه', $first, 'جدولِ تشخیص چاپ نشد.');
        $this->assertSame('', trim($second), 'مشکلِ خفه‌شده دوباره خروجی ساخت.');
    }

    /**
     * ⚠️ بکاپ باید خودکار ساخته شود.
     *
     * ─── چه چیزی کم بود ────────────────────────────────────────────────────
     * سازوکارِ بکاپ کامل بود (ساخت، رمزنگاری، بازیابی، هرسِ دوره‌ای) ولی
     * هیچ‌کدام خودکار شروع نمی‌شدند: تنها راهِ ساختنِ بکاپ، فشردنِ دکمه‌ای در
     * پنل بود. یعنی سامانه هر جمعه بکاپ‌های قدیمی را هرس می‌کرد و هرگز
     * نسخه‌ی تازه‌ای نمی‌ساخت.
     */
    public function test_the_scheduled_backup_queues_a_full_system_backup(): void
    {
        Queue::fake();

        $this->artisan('backups:run')->assertSuccessful();

        $backup = Backup::query()->latest('id')->first();

        $this->assertNotNull($backup);
        $this->assertSame('full', $backup->type);
        $this->assertNull($backup->complex_id);
        // هیچ کاربری این را نساخته؛ نسبت‌دادنش به کسی یعنی سیاهه‌ی ممیزی دروغ بگوید
        $this->assertNull($backup->created_by);

        Queue::assertPushed(BuildBackupJob::class);
    }

    /**
     * ⚠️ بکاپِ خودکار باید **پیش از** هرسِ دوره‌ای اجرا شود.
     *
     * برعکسش یعنی در بدترین لحظه، هرس اجرا شود و نسخه‌ی جایگزینش هنوز
     * ساخته نشده باشد.
     */
    public function test_the_automatic_backup_runs_before_the_pruning(): void
    {
        $console = (string) file_get_contents(base_path('routes/console.php'));
        $console = (string) preg_replace('#^\s*(//|\|).*$#m', '', $console);

        $this->assertSame(1, preg_match("/backups:run'\)\s*->dailyAt\('(\d{2}):/", $console, $run));
        $this->assertSame(1, preg_match("/backups:prune[^']*'\)->weeklyOn\(\d+, '(\d{2}):/", $console, $prune));

        $this->assertLessThan(
            (int) $prune[1],
            (int) $run[1],
            'هرسِ بکاپ پیش از ساختِ نسخه‌ی تازه اجرا می‌شود.',
        );
    }

    /**
     * ⚠️ کانالِ پیش‌فرضِ لاگ باید بچرخد.
     *
     * با `single`، فایلِ `laravel.log` هرگز نمی‌چرخد و تا وقتی دیسک پر شود
     * بزرگ می‌شود. و چون نشست و صف و کشِ این پروژه همه روی همین سرورند،
     * پرشدنِ دیسک یعنی قطعیِ کامل — نه فقط یک لاگِ بزرگ.
     */
    public function test_the_default_log_channel_rotates(): void
    {
        $env = (string) file_get_contents(base_path('.env.example'));
        $env = (string) preg_replace('/^\s*#.*$/m', '', $env);

        $this->assertMatchesRegularExpression('/^LOG_STACK=daily$/m', $env);
        $this->assertArrayHasKey('alerts', config('logging.channels'));
        $this->assertSame('daily', config('logging.channels.alerts.driver'));
    }

    /**
     * صفحه‌ی حالتِ تعمیر باید فارسی و کاملاً خودبسنده باشد.
     *
     * ⚠️ این صفحه دقیقاً وقتی نشان داده می‌شود که برنامه بالا **نیست**؛ هر
     * `asset()` یا فونتِ بیرونی همان‌جا شکست می‌خورد و صفحه‌ی خرابی را
     * خراب‌تر نشان می‌دهد.
     */
    public function test_the_maintenance_page_is_persian_and_self_contained(): void
    {
        $path = resource_path('views/errors/503.blade.php');

        $this->assertFileExists($path);

        $html = (string) file_get_contents($path);
        $html = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $html);

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('lang="fa"', $html);

        foreach (['asset(', 'https://', 'http://', '<script'] as $external) {
            $this->assertStringNotContainsString(
                $external,
                $html,
                "صفحه‌ی ۵۰۳ به «{$external}» وابسته است؛ وقتی برنامه پایین باشد بارگذاری نمی‌شود.",
            );
        }
    }
}
