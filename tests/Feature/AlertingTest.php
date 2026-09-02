<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Logging\SecretScrubber;
use App\Models\Complex;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\SystemHealthNotification;
use App\Services\Health\AnomalyReport;
use App\Services\Health\HealthAlerter;
use App\Services\Health\HealthReport;
use App\Services\Sms\SmsManager;
use App\Support\SmsOutcome;
use App\Support\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * هشدارهای نشانه‌ای (R64).
 *
 * ⚠️ چهار خرابی که سرور را کاملاً سالم نشان می‌دهند.
 */
class AlertingTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────────────────
    //  ① جهشِ خطا
    // ─────────────────────────────────────────────────────────────────────

    /** بدونِ پایه، هیچ نرخی قابلِ محاسبه نیست — و هشدارِ ساختگی بدتر از سکوت است. */
    public function test_the_first_scan_only_records_a_baseline(): void
    {
        $check = $this->anomaly('errors');

        $this->assertSame(HealthReport::OK, $check['status']);
        $this->assertStringContainsString('پایه', $check['detail']);
        $this->assertNotNull(Cache::get(AnomalyReport::ERROR_SNAPSHOT_KEY));
    }

    /**
     * 🔴 مهم‌ترین تستِ این سنجه.
     *
     * `error_events` با اثرِ انگشت گروه می‌کند: خطایی که هزاران بار تکرار
     * شود **یک ردیف** است. سنجه‌ای که ردیف بشمارد دقیقاً همین حالت — بدترین
     * حالتِ ممکن — را نمی‌بیند.
     */
    public function test_one_looping_error_in_a_single_row_still_trips_the_alert(): void
    {
        $this->errorRow(occurrences: 10);
        $this->anomaly('errors'); // ثبتِ پایه

        $this->travel(30)->minutes();

        // همان **یک** ردیف، با شمارشِ بالا رفته
        DB::table('error_events')->update(['occurrences' => 5000]);

        $this->assertSame(1, DB::table('error_events')->count(), 'ردیف نباید اضافه شده باشد.');

        $check = $this->anomaly('errors');

        $this->assertSame(HealthReport::DEGRADED, $check['status']);
        $this->assertGreaterThan(60, $check['per_hour']);
    }

    /** نرخِ زیرِ آستانه، زندگیِ عادیِ هر سامانه‌ای است. */
    public function test_a_few_scattered_errors_stay_quiet(): void
    {
        $this->errorRow(occurrences: 1);
        $this->anomaly('errors');

        $this->travel(30)->minutes();

        DB::table('error_events')->update(['occurrences' => 3]);

        $this->assertSame(HealthReport::OK, $this->anomaly('errors')['status']);
    }

    /**
     * ⚠️ جدول هرس می‌شود، پس مجموع می‌تواند **کم** شود.
     *
     * تفسیرِ عددِ منفی به‌عنوان نرخ یعنی هشدار درست بعد از هر هرس.
     */
    public function test_pruning_the_table_does_not_look_like_a_spike(): void
    {
        $this->errorRow(occurrences: 9000);
        $this->anomaly('errors');

        $this->travel(30)->minutes();

        DB::table('error_events')->delete();

        $check = $this->anomaly('errors');

        $this->assertSame(HealthReport::OK, $check['status']);
        $this->assertStringContainsString('هرس', $check['detail']);
    }

    /**
     * 🔴 نقصی که همین تست‌ها پیدا کردند.
     *
     * دو اجرا با چند ثانیه فاصله یعنی تقسیم بر یک دقیقه؛ دو خطای بی‌ضرر به
     * «۱۲۰ در ساعت» تعمیم داده می‌شد. و پایه نباید جابه‌جا شود، وگرنه اجرای
     * دستی پنجره‌ی واقعی را صفر می‌کند و جهشِ بعدی از دست می‌رود.
     */
    public function test_two_scans_seconds_apart_do_not_invent_a_rate(): void
    {
        $this->errorRow(occurrences: 1);
        $this->anomaly('errors');

        $baseline = Cache::get(AnomalyReport::ERROR_SNAPSHOT_KEY);

        DB::table('error_events')->update(['occurrences' => 40]);

        $check = $this->anomaly('errors');

        $this->assertSame(HealthReport::OK, $check['status']);
        $this->assertSame(
            $baseline,
            Cache::get(AnomalyReport::ERROR_SNAPSHOT_KEY),
            'اجرای دستی پایه را جابه‌جا کرد و پنجره‌ی واقعی را صفر می‌کند.',
        );

        // ...ولی همان جهش پس از گذشتِ پنجره‌ی واقعی دیده می‌شود
        $this->travel(30)->minutes();

        $this->assertSame(HealthReport::DEGRADED, $this->anomaly('errors')['status']);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ② جهشِ شکستِ پرداخت
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ نسبت به‌تنهایی کافی نیست: یک انصراف در مجتمعِ کم‌تراکنش، پنجاه
     * درصد است.
     */
    public function test_a_single_failure_in_a_quiet_hour_is_not_a_spike(): void
    {
        $this->payments(failed: 1, successful: 1);

        $check = $this->anomaly('payments');

        $this->assertSame(HealthReport::OK, $check['status']);
        $this->assertStringContainsString('کف', $check['detail']);
    }

    public function test_a_real_failure_spike_is_announced(): void
    {
        $this->payments(failed: 6, successful: 2);

        $check = $this->anomaly('payments');

        $this->assertSame(HealthReport::DEGRADED, $check['status']);
        $this->assertSame(75, $check['percent']);
    }

    /**
     * 🔴 «بلاتکلیف» (R59) شکست حساب می‌شود: از دیدِ ساکن با ناموفق فرقی
     * ندارد، و از دیدِ اپراتور بدتر است چون ممکن است پول کسر شده باشد.
     */
    public function test_indeterminate_payments_count_as_failures(): void
    {
        $this->payments(failed: 0, successful: 6);

        $this->assertSame(HealthReport::OK, $this->anomaly('payments')['status']);

        for ($i = 0; $i < 4; $i++) {
            DB::table('audit_logs')->insert([
                'action' => 'payment.indeterminate',
                'created_at' => now(),
            ]);
        }

        $check = $this->anomaly('payments');

        $this->assertSame(HealthReport::DEGRADED, $check['status']);
        $this->assertSame(4, $check['indeterminate']);
    }

    /** پرداخت‌های کهنه نباید در پنجره‌ی امروز شمرده شوند. */
    public function test_old_failures_fall_out_of_the_window(): void
    {
        $this->payments(failed: 6, successful: 0);

        DB::table('payments')->update(['updated_at' => now()->subDay()]);

        $this->assertSame(HealthReport::OK, $this->anomaly('payments')['status']);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ③ شکستِ پیامک
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 تستِ **سیم‌کشی**، نه تستِ واحد.
     *
     * درسِ چهاربارتکرارشده‌ی R59 تا R63: تستی که خودش شمارنده را پر کند،
     * فقط سنجه را می‌سنجد نه اتصالش به دنیای واقعی. اینجا پیامک از
     * **`SmsManager` با درایورِ واقعی** فرستاده می‌شود و فقط لایه‌ی HTTP
     * جعلی است.
     */
    public function test_a_failing_gateway_reaches_the_alert_through_the_manager(): void
    {
        $this->useKavenegar();

        Http::fake(['api.kavenegar.com/*' => Http::response('', 500)]);

        $sms = app(SmsManager::class);

        foreach (['09120000001', '09120000002', '09120000003'] as $phone) {
            $this->assertFalse($sms->sendOtp($phone, '123456'));
        }

        $check = $this->anomaly('sms');

        $this->assertSame(HealthReport::DOWN, $check['status']);
        $this->assertSame(3, $check['failed']);
        $this->assertStringContainsString('ورود با کد ممکن نیست', $check['detail']);
    }

    /** ارسالِ موفق نباید چیزی روشن کند. */
    public function test_a_working_gateway_stays_quiet(): void
    {
        $this->useKavenegar();

        Http::fake(['api.kavenegar.com/*' => Http::response(['return' => ['status' => 200]])]);

        $sms = app(SmsManager::class);

        foreach (['09120000001', '09120000002', '09120000003'] as $phone) {
            $this->assertTrue($sms->sendOtp($phone, '123456'));
        }

        $check = $this->anomaly('sms');

        $this->assertSame(HealthReport::OK, $check['status']);
        $this->assertSame(3, $check['attempts']);
    }

    /** ⚠️ یک شماره‌ی خاموش هم ناموفق برمی‌گردد؛ زیرِ کفِ تعداد قضاوت نمی‌شود. */
    public function test_a_single_undelivered_message_is_not_an_outage(): void
    {
        SmsOutcome::record(false, 'شماره در دسترس نیست');

        $check = $this->anomaly('sms');

        $this->assertSame(HealthReport::OK, $check['status']);
        $this->assertStringContainsString('کف', $check['detail']);
    }

    /** نیمی ناموفق: خرابی هست ولی درِ ورود هنوز باز است. */
    public function test_a_partial_failure_is_degraded_not_down(): void
    {
        SmsOutcome::record(true);
        SmsOutcome::record(true);
        SmsOutcome::record(false, 'خطای درگاه');
        SmsOutcome::record(false, 'خطای درگاه');

        $this->assertSame(HealthReport::DEGRADED, $this->anomaly('sms')['status']);
    }

    /**
     * 🔴 پیامِ خطای درگاه، **راز** در خود دارد.
     *
     * کاوه‌نگار کلیدِ API را داخلِ مسیرِ URL می‌گذارد و پیامِ استثنای Guzzle
     * آدرسِ کامل را دارد (درسِ R55). این رشته بعداً به وب‌هوکِ بیرونی می‌رود.
     */
    public function test_the_stored_sms_error_is_scrubbed(): void
    {
        $this->useKavenegar();

        SmsOutcome::record(false, 'cURL error: https://api.kavenegar.com/v1/'.self::APIKEY.'/sms/send.json');

        $stored = (string) SmsOutcome::window()['error'];

        $this->assertStringNotContainsString(self::APIKEY, $stored);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ④ شکستِ بکاپ
    // ─────────────────────────────────────────────────────────────────────

    public function test_a_fresh_backup_keeps_the_check_green(): void
    {
        $this->backupRow('completed', now());

        $this->assertSame(HealthReport::OK, $this->anomaly('backups')['status']);
    }

    /**
     * 🔴 `BuildBackupJob::failed()` این ستون را می‌نوشت و **هیچ‌جای کد
     * نمی‌خواندش**.
     */
    public function test_a_failed_backup_row_is_no_longer_invisible(): void
    {
        $this->backupRow('completed', now());
        $this->backupRow('failed', now());

        $check = $this->anomaly('backups');

        $this->assertSame(HealthReport::DEGRADED, $check['status']);
        $this->assertStringContainsString('شکست‌خورده', $check['detail']);
    }

    /**
     * ⚠️ ساکت‌ترین شکلِ مرگِ بکاپ: ردیف **پیش از** صف‌شدن ساخته می‌شود، پس
     * کارگرِ مرده نه خطایی می‌سازد نه ردیفِ قرمزی.
     */
    public function test_a_backup_stuck_in_pending_means_the_worker_is_dead(): void
    {
        $this->backupRow('completed', now());
        $this->backupRow('pending', now()->subHours(3));

        $check = $this->anomaly('backups');

        $this->assertSame(HealthReport::DEGRADED, $check['status']);
        $this->assertStringContainsString('در انتظار', $check['detail']);
    }

    /** زمان‌بندی که اجرا نمی‌شود، هیچ خطایی نمی‌سازد — فقط بکاپ کهنه می‌شود. */
    public function test_a_backup_older_than_the_rpo_is_reported(): void
    {
        $this->backupRow('completed', now()->subHours(50));

        $check = $this->anomaly('backups');

        $this->assertSame(HealthReport::DEGRADED, $check['status']);
        $this->assertStringContainsString('RPO', $check['detail']);
    }

    public function test_having_no_backup_at_all_is_reported(): void
    {
        $check = $this->anomaly('backups');

        $this->assertSame(HealthReport::DEGRADED, $check['status']);
        $this->assertStringContainsString('هیچ بکاپ', $check['detail']);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  تحویل
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ سنجیدنِ خودِ گزارش کافی نیست؛ باید ثابت شود دستور آن را به مسیرِ
     * تحویل می‌دهد.
     */
    public function test_the_command_delivers_through_the_alert_channel(): void
    {
        Notification::fake();
        Http::fake();
        config(['health.alert.webhook' => 'https://hooks.example.test/alert']);

        User::factory()->create(['role' => UserRole::SuperAdmin->value]);

        // هیچ بکاپی وجود ندارد → قطعاً یک مشکل هست
        $this->assertSame(0, Artisan::call('alerts:scan'));

        Notification::assertSentTimes(SystemHealthNotification::class, 1);
        Http::assertSent(fn ($request) => $request->url() === 'https://hooks.example.test/alert'
            && isset($request['problems']['backups']));
    }

    /**
     * 🔴 نقصی که هنگامِ R64 پیدا شد: پاک‌کننده‌ی رازِ R55 فقط روی مسیرِ
     * **لاگ** بود، ولی همین متن‌ها به **وب‌هوک** هم می‌روند — یعنی به یک
     * سرویسِ بیرونی.
     */
    public function test_the_webhook_never_carries_a_secret(): void
    {
        Notification::fake();
        Http::fake();
        config(['health.alert.webhook' => 'https://hooks.example.test/alert']);

        $this->useKavenegar();

        app(HealthAlerter::class)->dispatch([
            'status' => HealthReport::DEGRADED,
            'checks' => [
                'sms' => [
                    'status' => HealthReport::DEGRADED,
                    'detail' => 'cURL error: https://api.kavenegar.com/v1/'.self::APIKEY.'/sms/send.json',
                ],
            ],
        ]);

        Http::assertSent(function ($request): bool {
            $this->assertStringNotContainsString(
                self::APIKEY,
                (string) $request->body(),
                'کلیدِ زنده‌ی پیامک به سرویسِ بیرونی رفت.',
            );

            return true;
        });
    }

    /**
     * 🔴 `backups:verify` از R58 وجود داشت ولی خروجی‌اش فقط به **stdoutِ
     * cron** می‌رفت — یعنی «بکاپِ شما قابلِ بازیابی نیست» جایی نوشته می‌شد
     * که هیچ‌کس نمی‌خواندش.
     */
    public function test_the_weekly_verify_now_reaches_a_human(): void
    {
        Notification::fake();
        Http::fake();
        config(['health.alert.webhook' => 'https://hooks.example.test/alert']);

        $this->assertSame(1, Artisan::call('backups:verify'));

        Http::assertSent(fn ($request) => isset($request['problems']['backup-verify']));
    }

    /** سنجه‌های تازه باید واقعاً زمان‌بندی شده باشند، وگرنه هیچ‌وقت اجرا نمی‌شوند. */
    public function test_the_scan_is_scheduled(): void
    {
        $console = (string) file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString("Schedule::command('alerts:scan", $console);
    }

    /**
     * ⚠️ محافظِ ساختاری: هیچ‌کس نباید درایورِ پیامک را مستقیم بسازد.
     *
     * شمارش در `SmsManager` نشسته؛ ساختنِ مستقیمِ درایور یعنی همان ارسال
     * شمرده نشود و هشدار روی داده‌ی ناقص تصمیم بگیرد.
     */
    public function test_no_one_bypasses_the_sms_manager(): void
    {
        $offenders = [];

        foreach (['app', 'routes'] as $directory) {
            foreach ($this->phpFilesUnder(base_path($directory)) as $file) {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen(base_path()) + 1));

                if (str_starts_with($relative, 'app/Services/Sms/')) {
                    continue;
                }

                /*
                 * ⚠️ پیشوندِ فضای‌نام هم باید گرفته شود.
                 *
                 * 🔴 نسخه‌ی اولِ این الگو `new\s+(Kavenegar|...)Sms` بود و
                 * خرابکاری از تور رد شد: `new \App\Services\Sms\LogSms`
                 * دقیقاً همان دور زدن است و الگو نمی‌دیدش.
                 */
                if (preg_match('/new\s+[\\\\A-Za-z0-9_]*(Kavenegar|Ippanel|Melipayamak|Log)Sms\b/', (string) file_get_contents($file))) {
                    $offenders[] = $relative;
                }
            }
        }

        $this->assertSame([], $offenders, 'درایورِ پیامک باید فقط از راهِ SmsManager ساخته شود.');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  کمکی
    // ─────────────────────────────────────────────────────────────────────

    private const APIKEY = 'KAVE-LIVE-KEY-8f3a91c7b2';

    /** @return array<string, mixed> */
    private function anomaly(string $check): array
    {
        return app(AnomalyReport::class)->run()['checks'][$check];
    }

    private function errorRow(int $occurrences): void
    {
        DB::table('error_events')->insert([
            'source' => 'server',
            'fingerprint' => 'fp-'.md5('یک خطای تکرارشونده'),
            'type' => 'RuntimeException',
            'message' => 'یک خطای تکرارشونده',
            'occurrences' => $occurrences,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payments(int $failed, int $successful): void
    {
        $complex = Complex::factory()->create();
        $unit = Unit::factory()->create(['complex_id' => $complex->id]);

        $rows = [];

        foreach ([PaymentStatus::Failed->value => $failed, PaymentStatus::Success->value => $successful] as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $rows[] = [
                    'complex_id' => $complex->id,
                    'unit_id' => $unit->id,
                    'amount' => 100000,
                    'method' => PaymentMethod::Online->value,
                    'status' => $status,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if ($rows !== []) {
            DB::table('payments')->insert($rows);
        }
    }

    private function backupRow(string $status, \DateTimeInterface $createdAt): void
    {
        DB::table('backups')->insert([
            'complex_id' => null,
            'type' => 'full',
            'status' => $status,
            'disk' => 'local',
            'path' => $status === 'completed' ? 'backups/system-'.uniqid().'.bak' : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function useKavenegar(): void
    {
        SystemSettings::set('sms_driver', 'kavenegar');
        SystemSettings::set('sms_config', ['apikey' => self::APIKEY, 'sender' => '10004346']);

        /*
         * ⚠️ `SecretScrubber` رازها را **یک بار در هر پردازه** می‌خواند (R55،
         * تا خواندنشان به‌ازای هر خطِ لاگ تکرار نشود). در اجرای کاملِ مجموعه،
         * تستی که پیش‌تر اجرا شده همان حافظه را با فهرستِ خالی پر کرده است.
         *
         * ⚠️ این تفاوت را فقط اجرای کاملِ مجموعه نشان داد؛ با `--filter` هر
         * دو تستِ راز سبز بودند.
         */
        SecretScrubber::flush();
    }

    /** @return array<int, string> */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
