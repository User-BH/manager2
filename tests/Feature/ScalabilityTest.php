<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Unit;
use App\Models\User;
use App\Support\Jalali;
use App\Support\PrivateFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * مقیاس‌پذیری: بی‌حالتی، بودجه‌ی کوئری و نگه‌داریِ داده (R45).
 *
 * ─── فرضِ کاری ─────────────────────────────────────────────────────────────
 * ۱۰٬۰۰۰ مجتمع و بیش از یک میلیون کاربر. در این مقیاس دو چیز می‌شکند که در
 * مقیاسِ کوچک اصلاً دیده نمی‌شوند:
 *
 * ① **هر کوئریِ اضافه در هر درخواست** ضربدرِ میلیون می‌شود.
 * ② **هر جدولی که سیاستِ نگه‌داری ندارد** بی‌مرز رشد می‌کند تا جایی که
 *    خودش کندی می‌سازد.
 *
 * این تست هر دو را عدد می‌کند، چون چیزی که عدد نداشته باشد بی‌صدا بد
 * می‌شود.
 */
class ScalabilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * سقفِ کوئری برای هر مسیر.
     *
     * ⚠️ این اعداد **اندازه‌گیری‌شده‌اند، نه آرزو**. عددِ واقعیِ امروز
     * سنجیده شد و کمی بالاتر از آن به‌عنوان سقف نشست: هدف گرفتنِ
     * پس‌رفت است، نه شکستنِ تست با هر تغییرِ بی‌ضرر.
     *
     * ⚠️ داشبورد عمداً سقفِ بلندتری دارد. هفده کوئری برای صفحه‌ای که
     * جمع‌های چند حوزه‌ی متفاوت را کنارِ هم می‌گذارد، پرت نیست — ولی
     * سقف‌داشتنش یعنی کسی نمی‌تواند بی‌سروصدا هجدهمی را اضافه کند.
     */
    private const QUERY_BUDGET = [
        '/api/v1/bills' => 8,
        '/api/v1/units' => 8,
        '/api/v1/messenger' => 12,
        '/api/v1/dashboard' => 20,
    ];

    /** یک مجتمع با تعدادِ مشخصی واحد و قبض. */
    private function scene(int $rows): User
    {
        $complex = Complex::factory()->create();

        $manager = User::factory()->create([
            'complex_id' => $complex->id,
            'role' => UserRole::ComplexAdmin,
            'is_active' => true,
        ]);

        $period = Jalali::currentPeriod();

        foreach (range(1, $rows) as $i) {
            $unit = Unit::factory()->create([
                'complex_id' => $complex->id,
                'unit_number' => (string) $i,
            ]);

            Bill::create([
                'complex_id' => $complex->id,
                'unit_id' => $unit->id,
                'period' => $period,
                'base_amount' => 100000,
                'total_amount' => 100000,
                'paid_amount' => 0,
                'due_date' => now()->addDays(10),
            ]);
        }

        return $manager;
    }

    /** تعدادِ کوئریِ یک درخواست. */
    private function queryCount(User $as, string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($as)->getJson($url)->assertOk();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  بودجه‌ی کوئری
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ مهم‌ترین خاصیتِ این فایل: تعدادِ کوئری نباید با حجمِ داده رشد کند.
     *
     * ─── چرا این تست، و نه فقط یک سقفِ ثابت ─────────────────────────────────
     * سقفِ ثابت روی داده‌ی کوچکِ تست، N+1 را نمی‌گیرد: با سه ردیف، سه
     * کوئریِ اضافه زیرِ هر سقفی جا می‌شود. چیزی که در مقیاس می‌شکند
     * **شیب** است، نه مقدارِ مطلق. پس همان مسیر با ۳ و با ۳۰ ردیف سنجیده
     * می‌شود و اختلافشان باید صفر باشد.
     *
     * اندازه‌گیریِ امروز: هر چهار مسیر ثابت‌اند (R13 و R14 کارشان را
     * کرده‌اند). این تست همان‌جا نگهشان می‌دارد.
     */
    #[DataProvider('budgetedEndpoints')]
    public function test_query_count_does_not_grow_with_the_data(string $url, int $budget): void
    {
        $small = $this->queryCount($this->scene(3), $url);
        $large = $this->queryCount($this->scene(30), $url);

        $this->assertLessThanOrEqual(
            $small,
            $large,
            "«{$url}» با ده برابر شدنِ داده {$large} کوئری زد (پیش‌تر {$small}) — نشانه‌ی N+1.",
        );
    }

    #[DataProvider('budgetedEndpoints')]
    public function test_every_endpoint_stays_within_its_query_budget(string $url, int $budget): void
    {
        $count = $this->queryCount($this->scene(30), $url);

        $this->assertLessThanOrEqual(
            $budget,
            $count,
            "«{$url}» با {$count} کوئری از سقفِ {$budget} گذشت.",
        );
    }

    /** @return array<string, array{string, int}> */
    public static function budgetedEndpoints(): array
    {
        $cases = [];

        foreach (self::QUERY_BUDGET as $url => $budget) {
            $cases[$url] = [$url, $budget];
        }

        return $cases;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  بی‌حالتی
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ هیچ کدِ برنامه‌ای نباید نامِ دیسک را هاردکد کند.
     *
     * ─── خرابی‌ای که این جلویش را می‌گیرد ────────────────────────────────────
     * پیش از R45، هجده نقطه `Storage::disk('local')` می‌نوشتند. با دو گره:
     *
     *   ساکن رسید را آپلود می‌کند → روی دیسکِ گره‌ی A می‌نشیند
     *   مدیر می‌خواهد ببیندش      → درخواست به گره‌ی B می‌رسد → ۴۰۴
     *
     * و چون متعادل‌کننده گاهی A را انتخاب می‌کند و گاهی B، خرابی **متناوب**
     * است — بدترین نوع برای رفع‌عیب.
     *
     * ⚠️ کلیدِ `FILESYSTEM_DISK` هم از قبل وجود داشت و کاملاً **مرده** بود:
     * عوض‌کردنش هیچ اثری نداشت چون کد نامِ دیسک را خودش می‌نوشت.
     */
    public function test_no_application_code_hardcodes_a_storage_disk(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            /*
             * ⚠️ کامنت‌ها پاک می‌شوند — و این هشتمین باری است که در این
             * پروژه لازم شده. `PrivateFiles` در توضیحش دقیقاً همان الگویی
             * را می‌نویسد که ممنوع می‌کند، و بدونِ این پاک‌سازی تست روی
             * کدِ کاملاً درست قرمز می‌شد.
             *
             * دو الگوی جدا: یکی‌کردنشان با پرچمِ `s` باعث می‌شود
             * `//.*$` کلِ فایل را ببلعد.
             */
            $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
            $source = (string) preg_replace('#^\s*//.*$#m', '', $source);

            if (preg_match("/Storage::disk\(\s*'(local|public|s3)'\s*\)/", $source)) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'این فایل‌ها نامِ دیسک را هاردکد کرده‌اند: '.implode('، ', $offenders),
        );
    }

    /** دیسکِ خصوصی باید از تنظیمات بیاید و پیش‌فرضش تک‌سروری باشد. */
    public function test_the_private_disk_is_configurable(): void
    {
        $this->assertSame('local', PrivateFiles::name(), 'پیش‌فرض باید local باشد تا نصبِ ساده کار کند.');

        config(['filesystems.private' => 's3']);

        $this->assertSame('s3', PrivateFiles::name(), 'تغییرِ تنظیمات اثر نکرد.');
    }

    /** مقدارِ خالی نباید دیسکِ بی‌نام بسازد. */
    public function test_an_empty_private_disk_setting_falls_back(): void
    {
        config(['filesystems.private' => '']);

        $this->assertSame('local', PrivateFiles::name());
    }

    /**
     * ⚠️ نشست، کش و صف باید روی منبعِ **اشتراکی** باشند.
     *
     * با `file` یا `array`، هر گره حالتِ خودش را دارد: کاربر روی گره‌ی A
     * وارد می‌شود و درخواستِ بعدی‌اش که به B می‌رسد او را نشناخته می‌بیند و
     * بیرونش می‌اندازد. این تنظیم‌ها تنها چیزی هستند که اجرای چندگره‌ای را
     * ممکن می‌کنند.
     */
    public function test_the_production_example_uses_shared_state(): void
    {
        $raw = (string) file_get_contents(base_path('.env.production.example'));
        $raw = (string) preg_replace('/^\s*#.*$/m', '', str_replace(chr(13), '', $raw));

        foreach (['SESSION_DRIVER', 'CACHE_STORE', 'QUEUE_CONNECTION'] as $key) {
            $this->assertSame(
                1,
                preg_match('/^'.$key.'=(.+)$/m', $raw, $match),
                "«{$key}» در نمونه‌ی محصول نیست.",
            );

            $this->assertNotContains(
                trim($match[1]),
                ['file', 'array', 'sync'],
                "«{$key}» روی «{$match[1]}» است؛ بینِ گره‌ها مشترک نیست.",
            );
        }
    }

    /**
     * ⚠️ تفکیکِ خواندن/نوشتن باید بدونِ تنظیمات کاملاً بی‌اثر باشد.
     *
     * اگر `read` با میزبانِ خالی ساخته شود، هر نصبِ تک‌سروری به سرورِ
     * ناموجود وصل می‌شود و کلِ برنامه می‌افتد — یعنی قابلیتی که برای مقیاس
     * اضافه شده، نصب‌های کوچک را می‌شکند.
     */
    public function test_the_read_replica_is_inert_without_configuration(): void
    {
        $mysql = config('database.connections.mysql');

        $this->assertSame([], $mysql['read'], 'اتصالِ خواندن بدونِ DB_READ_HOST ساخته شد.');

        /*
         * ⚠️ `sticky` باید روشن باشد. بدونِ آن، تأخیرِ همانندسازی یعنی
         * کاربر پس از ثبتِ پرداخت صفحه‌ای ببیند که هنوز پرداختش را ندارد —
         * و فکر کند پولش گم شده.
         */
        $this->assertTrue($mysql['sticky'], 'sticky خاموش است؛ کاربر نوشته‌ی خودش را نمی‌بیند.');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  نگه‌داریِ داده
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ هر جدولِ پرترافیک باید سیاستِ نگه‌داری داشته باشد.
     *
     * پیش از R45 هیچ‌کدامِ این پنج جدول نداشتند. `otp_codes` بدترینشان
     * بود: یک ردیف به‌ازای هر تلاشِ ورود که چند دقیقه بعد بی‌ارزش می‌شود و
     * تا ابد می‌ماند.
     */
    public function test_every_high_volume_table_has_a_retention_rule(): void
    {
        $covered = array_column((array) config('retention.rules'), 'table');

        foreach (['otp_codes', 'sessions', 'notifications', 'audit_logs', 'error_events'] as $table) {
            $this->assertContains($table, $covered, "جدولِ «{$table}» سیاستِ نگه‌داری ندارد.");
        }
    }

    public function test_stale_one_time_codes_are_removed(): void
    {
        DB::table('otp_codes')->insert([
            ['phone' => '09120000001', 'code_hash' => 'x', 'expires_at' => now()->subDays(30),
                'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)],
            ['phone' => '09120000002', 'code_hash' => 'y', 'expires_at' => now()->addMinutes(2),
                'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertSame(1, DB::table('otp_codes')->count(), 'کدِ تازه هم پاک شد.');
        $this->assertDatabaseHas('otp_codes', ['phone' => '09120000002']);
    }

    /**
     * ⚠️ اعلانِ **نخوانده** هرگز نباید پاک شود.
     *
     * اعلانِ نخوانده پیامی است که کاربر ندیده؛ پاک‌کردنش یعنی خبری مثل
     * «قبضِ شما سررسید شده» بی‌آنکه کسی ببیندش ناپدید شود.
     */
    public function test_unread_notifications_are_never_pruned(): void
    {
        $user = User::factory()->create();

        $rows = [];

        foreach ([['read_at' => now()->subDays(200)], ['read_at' => null]] as $i => $extra) {
            $rows[] = array_merge([
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\Probe',
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => '{}',
                'created_at' => now()->subDays(200),
                'updated_at' => now()->subDays(200),
            ], $extra);
        }

        DB::table('notifications')->insert($rows);

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertSame(1, DB::table('notifications')->count());
        $this->assertSame(null, DB::table('notifications')->value('read_at'), 'اعلانِ نخوانده پاک شد.');
    }

    /**
     * ⚠️ `last_activity` جدولِ `sessions` مُهرِ یونیکس است، نه تاریخ.
     *
     * مقایسه‌اش با رشته‌ی تاریخ در MySQL خطا نمی‌دهد — بی‌صدا به صفر تبدیل
     * می‌شود و **هر ردیف** را شاملِ حذف می‌کند، یعنی همه‌ی کاربرانِ آنلاین
     * در همان لحظه بیرون انداخته می‌شوند.
     */
    public function test_the_unix_timestamp_column_is_compared_correctly(): void
    {
        DB::table('sessions')->insert([
            ['id' => 'old', 'payload' => '', 'last_activity' => now()->subDays(90)->getTimestamp()],
            ['id' => 'fresh', 'payload' => '', 'last_activity' => now()->getTimestamp()],
        ]);

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertDatabaseHas('sessions', ['id' => 'fresh']);
        $this->assertDatabaseMissing('sessions', ['id' => 'old']);
    }

    /** حالتِ خشک نباید چیزی را پاک کند. */
    public function test_the_dry_run_deletes_nothing(): void
    {
        DB::table('otp_codes')->insert([
            'phone' => '09120000003', 'code_hash' => 'z', 'expires_at' => now()->subDays(30),
            'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30),
        ]);

        $this->artisan('data:prune --dry-run')->assertSuccessful();

        $this->assertSame(1, DB::table('otp_codes')->count(), 'حالتِ خشک داده را پاک کرد.');
    }

    /**
     * ⚠️ حذف باید تکه‌تکه باشد.
     *
     * یک `DELETE` بدونِ سقف روی جدولی با ده‌ها میلیون ردیف، تراکنشی
     * می‌سازد که دقایق طول می‌کشد و در تمامِ آن مدت ردیف‌ها قفل‌اند — یعنی
     * دستوری که برای سلامتِ سامانه نوشته شده، خودش در ساعتِ اجرا سامانه را
     * می‌خواباند.
     *
     * با تکه‌ی دو‌تایی و شش ردیفِ کهنه، باید هر شش پاک شوند: یعنی حلقه
     * واقعاً تا ته می‌رود و یک تکه را رها نمی‌کند.
     */
    public function test_deletion_is_chunked_and_still_complete(): void
    {
        config(['retention.chunk' => 2]);

        $rows = [];

        foreach (range(1, 6) as $i) {
            $rows[] = [
                'phone' => '0912000100'.$i,
                'code_hash' => 'h'.$i,
                'expires_at' => now()->subDays(30),
                'created_at' => now()->subDays(30),
                'updated_at' => now()->subDays(30),
            ];
        }

        DB::table('otp_codes')->insert($rows);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->artisan('data:prune --table=otp_codes')->assertSuccessful();

        $deletes = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_starts_with(strtolower(trim($q['query'])), 'delete'))
            ->count();

        DB::disableQueryLog();

        // ① کامل: هیچ تکه‌ای رها نشده
        $this->assertSame(0, DB::table('otp_codes')->count(), 'حذفِ تکه‌تکه ناتمام ماند.');

        /*
         * ② و واقعاً تکه‌تکه.
         *
         * ⚠️ نسخه‌ی اولِ این تست فقط بندِ ① را داشت و با خرابکاریِ
         * «سقفِ تکه را بردار و همه را یک‌جا پاک کن» سبز ماند — چون
         * وضعیتِ پایانی یکسان است. ولی کلِ دلیلِ وجودِ تکه‌بندی، **نحوه‌ی
         * رسیدن** به آن وضعیت است: یک `DELETE` بی‌سقف روی ده‌ها میلیون
         * ردیف دقایق قفل می‌سازد. پس شمارشِ خودِ دستورها لازم است.
         *
         * ─── آستانه از حساب می‌آید، نه از حدس ──────────────────────────
         * ⚠️ آستانه‌ی اولِ من «بیشتر از ۱» بود و همان خرابکاری از دستش در
         * رفت: حذفِ بی‌سقف هم **دو** دستور می‌زند — یکی که همه‌ی ردیف‌ها را
         * می‌برد و یکی که صفر برمی‌گرداند و حلقه را تمام می‌کند.
         *
         * با شش ردیف و تکه‌ی دوتایی: ۲+۲+۲ و یک دورِ پایانیِ صفر = چهار
         * دستور (یا سه، اگر پیاده‌سازی با تکه‌ی ناتمام زودتر بایستد). پس
         * سه، مرزِ درستی است که تکه‌تکه را از یک‌جا جدا می‌کند.
         */
        $this->assertGreaterThanOrEqual(
            3,
            $deletes,
            "حذف در {$deletes} دستور انجام شد؛ تکه‌بندی اعمال نشده و در مقیاس قفلِ طولانی می‌سازد.",
        );
    }

    /**
     * جدولِ ناموجود نباید کلِ دستور را بترکاند.
     *
     * این دستور شبانه اجرا می‌شود؛ شکستنش یعنی چهار قاعده‌ی سالمِ دیگر هم
     * اجرا نشوند و جدول‌هایشان بی‌صدا شروع به رشد کنند.
     */
    public function test_a_missing_table_does_not_break_the_whole_run(): void
    {
        config(['retention.rules' => array_merge(
            [['table' => 'no_such_table', 'column' => 'created_at', 'days' => 1, 'label' => 'آزمایشی']],
            (array) config('retention.rules'),
        )]);

        $this->artisan('data:prune')->assertSuccessful();
    }
}
