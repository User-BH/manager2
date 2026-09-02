<?php

namespace Tests\Feature;

use App\Jobs\BuildBackupJob;
use App\Logging\RedactSecrets;
use App\Logging\RequestContext;
use App\Services\ErrorRecorder;
use App\Support\RequestId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * شناسه‌ی درخواست و دنبال‌کردنش (R63).
 *
 * ─── 🔴 چه چیزی نبود ───────────────────────────────────────────────────────
 * ⚠️ اندازه‌گیری شد: هیچ شناسه‌ی درخواستی در کلِ سامانه وجود نداشت. وقتی
 * کاربر می‌گفت «پرداختم خراب شد»، تنها راهِ پیداکردنِ آن درخواست گشتن در
 * `storage/logs` بر اساسِ **حدسِ زمان** بود — و در ساعتِ شلوغ ده‌ها
 * درخواستِ مشابه در همان دقیقه هست.
 *
 * ─── چهار جایی که شناسه باید برسد ──────────────────────────────────────────
 * ① پاسخ (هدر و بدنه) — تا کاربر بتواند بخواندش
 * ② لاگ — تا بشود جست‌وجویش کرد
 * ③ کارِ صف — چون سنگین‌ترین کارها آنجا انجام می‌شوند
 * ④ ردیفِ `error_events` — تا پنلِ ادمین هم به همان درخواست وصل شود
 */
class RequestTracingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RequestId::forget();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ① پاسخ
    // ─────────────────────────────────────────────────────────────────────

    public function test_every_response_carries_a_request_id(): void
    {
        $id = $this->getJson('/api/v1/features')->headers->get(RequestId::HEADER);

        $this->assertTrue(
            RequestId::acceptable($id),
            "شناسه‌ی «{$id}» قالبِ درست ندارد.",
        );
    }

    /**
     * 🔴 مهم‌ترین حالت: پاسخِ خطا.
     *
     * ⚠️ درسِ R61 اینجا هم برقرار است — پاسخی که از استثنا ساخته می‌شود از
     * «پس‌ازِ» میدل‌ورها رد نمی‌شود. و این دقیقاً همان پاسخی است که کاربر
     * شناسه‌اش را برای پشتیبانی می‌خواند.
     */
    public function test_an_error_response_carries_the_same_id_in_header_and_body(): void
    {
        $response = $this->getJson('/api/v1/my-bills');

        $response->assertStatus(401);

        $header = $response->headers->get(RequestId::HEADER);

        $this->assertTrue(RequestId::acceptable($header));
        $this->assertSame(
            $header,
            $response->json('requestId'),
            'شناسه‌ی هدر و بدنه یکی نیست؛ کاربر یکی را می‌خواند و ما دیگری را می‌گردیم.',
        );
    }

    /** ⚠️ دو درخواستِ جدا نباید شناسه‌ی یکسان بگیرند. */
    public function test_two_requests_get_different_ids(): void
    {
        $first = $this->getJson('/api/v1/features')->headers->get(RequestId::HEADER);

        RequestId::forget();

        $second = $this->getJson('/api/v1/features')->headers->get(RequestId::HEADER);

        $this->assertNotSame($first, $second);
    }

    /** ⚠️ و یک درخواست باید در تمامِ عمرش یک شناسه داشته باشد. */
    public function test_one_request_keeps_one_id(): void
    {
        $first = RequestId::current();

        $this->assertSame($first, RequestId::current());
        $this->assertSame($first, RequestId::current());
    }

    // ─────────────────────────────────────────────────────────────────────
    //  شناسه‌ی ورودی — و محافظش
    // ─────────────────────────────────────────────────────────────────────

    /**
     * اگر پروکسی شناسه‌ی معتبر داده باشد، همان می‌ماند تا ردِ دو لایه یکی
     * بماند.
     */
    public function test_a_well_formed_incoming_id_is_honoured(): void
    {
        $response = $this->withHeader(RequestId::HEADER, 'ABCDE-23456')
            ->getJson('/api/v1/features');

        $this->assertSame('ABCDE-23456', $response->headers->get(RequestId::HEADER));
    }

    /**
     * 🔴 محافظِ تزریق به لاگ.
     *
     * ⚠️ هدر کاملاً در اختیارِ فرستنده است. اگر بی‌بررسی در لاگ بنشیند،
     * مهاجم می‌تواند خطِ جعلیِ لاگ بسازد یا فایلِ لاگ را با مقداری
     * چندمگابایتی پر کند.
     */
    public function test_a_hostile_incoming_id_is_replaced(): void
    {
        foreach ([
            "OK\n[2026-01-01 00:00:00] production.ERROR: fake",
            str_repeat('A', 5000),
            '../../etc/passwd',
            '<script>alert(1)</script>',
            'aaaaa-bbbbb',
        ] as $hostile) {
            RequestId::forget();

            $id = $this->withHeader(RequestId::HEADER, $hostile)
                ->getJson('/api/v1/features')
                ->headers->get(RequestId::HEADER);

            $this->assertNotSame($hostile, $id, 'شناسه‌ی مهاجم پذیرفته شد.');
            $this->assertTrue(RequestId::acceptable($id));
        }
    }

    /** ⚠️ متقارن: اگر همه‌چیز رد شود، تستِ بالا پوچ است. */
    public function test_the_guard_accepts_only_our_own_format(): void
    {
        $this->assertTrue(RequestId::acceptable(RequestId::current()));
        $this->assertFalse(RequestId::acceptable(null));
        $this->assertFalse(RequestId::acceptable(''));
        // حروفِ مبهم عمداً در الفبا نیستند
        $this->assertFalse(RequestId::acceptable('ABCIO-01LUZ'));
    }

    /** ⚠️ نویسه‌های مبهم نباید در شناسه‌ی تولیدی باشند. */
    public function test_generated_ids_avoid_ambiguous_characters(): void
    {
        for ($i = 0; $i < 200; $i++) {
            RequestId::forget();

            $this->assertDoesNotMatchRegularExpression(
                '/[ILOU01]/',
                RequestId::current(),
                'شناسه نویسه‌ی مبهم دارد؛ خواندنش پای تلفن اشتباه می‌شود.',
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ② لاگ
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 شناسه باید روی **هر** خطِ لاگ بنشیند.
     *
     * ⚠️ همان استدلالِ `SecretScrubber` در R55: رفعِ دستی امروز درست است و
     * فردا نه. هر `Log::info()`ی که کسی بنویسد و شناسه را فراموش کند،
     * همان خطی است که روزِ حادثه لازم می‌شود.
     */
    public function test_the_request_id_lands_on_every_log_line(): void
    {
        $path = storage_path('logs/tracing-test.log');
        File::delete($path);

        config([
            'logging.channels.tracing' => [
                'driver' => 'single',
                'path' => $path,
                'level' => 'debug',
                'tap' => [RequestContext::class],
            ],
        ]);

        $id = RequestId::current();

        Log::channel('tracing')->info('یک خطِ ساده، بدونِ هیچ context');

        $contents = File::get($path);
        File::delete($path);

        $this->assertStringContainsString(
            $id,
            $contents,
            'خطِ لاگ شناسه‌ی درخواست را ندارد.',
        );
    }

    /**
     * 🔴 و پردازنده باید روی **کانال‌های واقعی** سوار باشد.
     *
     * ⚠️ این تست را خرابکاری به من تحمیل کرد: تستِ بالا کانالِ خودش را
     * می‌سازد و تاپ را دستی می‌دهد، پس فقط **کلاسِ پردازنده** را می‌سنجد.
     * وقتی `RequestContext` را از `config/logging.php` برداشتم، سبز ماند —
     * یعنی ادعای «روی هر خطِ لاگ می‌نشیند» اثبات نمی‌شد.
     *
     * قاعده از خودِ config مشتق می‌شود، نه از فهرستِ دستی: هر کانالی که
     * `RedactSecrets` دارد باید `RequestContext` هم داشته باشد. کانالِ
     * تازه‌ای که فردا اضافه شود، همین‌جا گرفته می‌شود.
     */
    public function test_the_real_log_channels_carry_the_processor(): void
    {
        $missing = [];

        foreach ((array) config('logging.channels') as $name => $channel) {
            $taps = (array) ($channel['tap'] ?? []);

            if (! in_array(RedactSecrets::class, $taps, true)) {
                continue;
            }

            if (! in_array(RequestContext::class, $taps, true)) {
                $missing[] = $name;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'این کانال‌ها شناسه‌ی درخواست را روی خطوطشان نمی‌گذارند: '.implode('، ', $missing),
        );

        // ⚠️ متقارن: اگر هیچ کانالی پیدا نشود، ادعای بالا پوچ است
        $this->assertNotEmpty(
            array_filter(
                (array) config('logging.channels'),
                fn ($channel) => in_array(RequestContext::class, (array) ($channel['tap'] ?? []), true),
            ),
            'هیچ کانالی پردازنده را ندارد؛ الگو خراب است.',
        );
    }

    /**
     * و یک سنجشِ رفتاری روی کانالِ واقعیِ `single`.
     *
     * ⚠️ فقط مسیرش عوض می‌شود تا فایلِ لاگِ محصول کثیف نشود؛ فهرستِ تاپ
     * همان است که در `config/logging.php` نوشته شده.
     */
    public function test_the_configured_single_channel_writes_the_id(): void
    {
        $path = storage_path('logs/tracing-single-test.log');
        File::delete($path);

        config(['logging.channels.single.path' => $path]);

        $id = RequestId::current();

        Log::channel('single')->info('خطِ آزمایشی');

        $contents = File::get($path);
        File::delete($path);

        $this->assertStringContainsString($id, $contents);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ③ صف
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 شناسه باید تا داخلِ کارگرِ صف برود.
     *
     * ⚠️ سنگین‌ترین کارهای این سامانه در صف انجام می‌شوند: بکاپ، PDFِ
     * دسته‌ی قبض‌ها، اعلان. یعنی وقتی کاربر می‌گوید «قبض‌هایم ساخته نشد»،
     * خطاهایش در لاگِ **کارگر** است نه در لاگِ درخواستِ او.
     */
    public function test_the_request_id_travels_into_the_queue(): void
    {
        /*
         * ⚠️ `phpunit.xml` صف را `sync` می‌گذارد، یعنی کار همان‌جا اجرا
         * می‌شود و هرگز payloadی ساخته نمی‌شود. برای سنجشِ **انتقال**،
         * درایورِ واقعی لازم است.
         */
        config(['queue.default' => 'database']);

        $sent = RequestId::current();

        Queue::push(new BuildBackupJob(1));

        $payload = json_decode(
            (string) DB::table('jobs')->value('payload'),
            true,
        );

        $this->assertSame(
            $sent,
            $payload[RequestId::PAYLOAD_KEY] ?? null,
            'شناسه در payloadِ کار نیست؛ لاگِ کارگر به درخواست وصل نمی‌شود.',
        );
    }

    /** و کارگر باید همان را **بازگرداند**، نه فقط حملش کند. */
    public function test_the_worker_restores_the_originating_id(): void
    {
        config(['queue.default' => 'database']);

        $sent = RequestId::current();

        Queue::push(new BuildBackupJob(1));

        $job = DB::table('jobs')->first();
        $payload = json_decode((string) $job->payload, true);

        // شبیه‌سازیِ کارگر: شناسه‌ی خودش را دارد، بعد کار می‌رسد
        RequestId::forget();
        $this->assertNotSame($sent, RequestId::current());

        Event::dispatch(new JobProcessing('database', new FakeQueueJob($payload)));

        $this->assertSame(
            $sent,
            RequestId::current(),
            'کارگر شناسه‌ی درخواستِ سازنده را برنگرداند.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ④ ردیفِ خطا
    // ─────────────────────────────────────────────────────────────────────

    public function test_a_recorded_error_carries_the_request_id(): void
    {
        $id = RequestId::current();

        ErrorRecorder::fromException(new RuntimeException('خطای آزمایشی'), '/x', 'GET');

        $this->assertDatabaseHas('error_events', [
            'message' => 'خطای آزمایشی',
            'request_id' => $id,
        ]);
    }

    /**
     * ⚠️ ردیف‌ها با اثرِ انگشت ادغام می‌شوند، پس شناسه باید به **آخرین**
     * رخداد به‌روز شود.
     *
     * کاربری که همین حالا زنگ می‌زند شناسه‌ی همین لحظه را دارد؛ شناسه‌ی
     * سه هفته پیش کمکی نمی‌کند و لاگش هم دیگر روی دیسک نیست.
     */
    public function test_a_repeated_error_tracks_the_latest_request(): void
    {
        /*
         * ⚠️ **همان شیءِ استثنا**، نه دو `new` روی دو خط.
         *
         * اثرِ انگشت شاملِ فایل و شماره‌ی خط است، پس دو استثنای هم‌پیام که
         * روی دو خطِ متفاوت ساخته شده‌اند دو ردیفِ جدا می‌سازند — و آن‌وقت
         * این تست چیزی را که ادعا می‌کند نمی‌سنجد. نسخه‌ی اولش همین
         * اشتباه را داشت.
         */
        $error = new RuntimeException('تکراری');

        ErrorRecorder::fromException($error, '/x', 'GET');

        RequestId::forget();
        $second = RequestId::current();

        ErrorRecorder::fromException($error, '/x', 'GET');

        $this->assertDatabaseHas('error_events', [
            'message' => 'تکراری',
            'request_id' => $second,
            'occurrences' => 2,
        ]);
    }

    /** ⚠️ صفحه‌ی خطای ۵۰۰ باید کد را نشان بدهد، وگرنه کاربر چیزی برای گفتن ندارد. */
    public function test_the_error_page_shows_the_code_to_the_user(): void
    {
        $view = (string) File::get(resource_path('views/errors/500.blade.php'));

        $this->assertStringContainsString('RequestId::current()', $view);
        $this->assertStringContainsString('کدِ پیگیری', $view);
    }

    protected function tearDown(): void
    {
        RequestId::forget();

        parent::tearDown();
    }
}

/**
 * کمترین چیزی که `JobProcessing` لازم دارد.
 *
 * ⚠️ mock نشد و عمداً: این تست باید **قلابِ واقعی** را بسنجد، و آن قلاب
 * فقط `payload()` را صدا می‌زند. یک کلاسِ چندخطی از یک mockِ پیکربندی‌شده
 * روشن‌تر است و کمتر می‌شکند.
 */
class FakeQueueJob extends Job implements \Illuminate\Contracts\Queue\Job
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(private array $payload) {}

    public function payload(): array
    {
        return $this->payload;
    }

    public function getJobId(): string
    {
        return '1';
    }

    public function getRawBody(): string
    {
        return (string) json_encode($this->payload);
    }

    public function attempts(): int
    {
        return 1;
    }
}
