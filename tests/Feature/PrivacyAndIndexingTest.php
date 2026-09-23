<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Controllers\SeoController;
use App\Logging\SecretScrubber;
use App\Models\Complex;
use App\Models\ErrorEvent;
use App\Models\User;
use App\Services\ErrorRecorder;
use App\Support\Observability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Tests\TestCase;

/**
 * حریمِ خصوصی و ایندکس (مرحلهٔ ۵ · فاز ۷ · بندهای ۵۶، ۵۸، ۵۹).
 *
 * سه نقصِ اندازه‌گیری‌شده:
 *  ① پوسته‌ی داشبورد هیچ noindexی نداشت و robots.txt فقط `/dashboard` را
 *    می‌بست؛ `/auth` هم بسته بود و پس خزنده noindexِ آن را نمی‌دید.
 *  ② Clarity (ضبطِ جلسه) در CSPِ داشبورد هم مجاز بود.
 *  ③ جدولِ خطاها آدرسِ کامل با کوئری و متنِ استثنای دیتابیس با مقدارهای
 *    خودِ کوئری (موبایل، هشِ رمز) را نگه می‌داشت.
 */
class PrivacyAndIndexingTest extends TestCase
{
    use RefreshDatabase;

    /** سطح‌های خصوصی — از هر نوع پاسخ: HTML، JSON، ریدایرکت، ۴۰۴ِ SPA. */
    private const PRIVATE_PATHS = [
        '/dashboard', '/units', '/bills/5', '/settings/complex', '/messenger',
        '/auth', '/auth/verify', '/auth/forgot', '/offline',
        '/api/v1/me', '/bills/1/invoice.pdf', '/no-such-page', '/demo/extra',
    ];

    private function noindex(string $path): bool
    {
        return str_contains((string) $this->get($path)->headers->get('X-Robots-Tag'), 'noindex');
    }

    /* ── ایندکس ──────────────────────────────────────────────────────────── */

    public function test_the_public_pages_and_crawler_files_stay_indexable(): void
    {
        $paths = [...array_column(SeoController::PUBLIC_PATHS, 'loc'), ...SeoController::CRAWLER_FILES];
        $this->assertGreaterThanOrEqual(6, count($paths));

        foreach ($paths as $path) {
            $this->get($path)->assertOk();
            $this->assertFalse($this->noindex($path), "{$path} نباید noindex باشد");
        }
    }

    public function test_every_private_surface_is_noindex(): void
    {
        foreach (self::PRIVATE_PATHS as $path) {
            $this->assertTrue($this->noindex($path), "{$path} باید noindex باشد");
        }
    }

    public function test_the_dashboard_stays_noindex_for_a_signed_in_user(): void
    {
        $complex = Complex::create(['type' => 'residential', 'name' => 'م', 'slug' => 'm-idx',
            'currency' => 'toman', 'charge_due_day' => 10, 'payment_gateway' => 'fake']);
        $admin = User::create(['complex_id' => $complex->id, 'name' => 'مدیر', 'phone' => '09120007001',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'), 'is_active' => true]);

        $response = $this->actingAs($admin)->get('/dashboard')->assertOk();

        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        // پوسته از کشِ service worker هم که بیاید، خودش می‌گوید
        $response->assertSee('<meta name="robots" content="noindex,nofollow">', false);
    }

    /** یک منبع: هر آدرسِ نقشه‌ی سایت قابلِ ایندکس است و برعکس. */
    public function test_the_sitemap_and_the_indexable_list_agree(): void
    {
        preg_match_all('#<loc>https?://[^/]+(/[^<]*)</loc>#', (string) $this->get('/sitemap.xml')->getContent(), $m);

        $this->assertSame(array_column(SeoController::PUBLIC_PATHS, 'loc'), $m[1]);
    }

    /**
     * 🔴 صفحه‌ای که robots.txt ببندد، noindexش هرگز دیده نمی‌شود و آدرسِ
     * لختش باز هم ایندکس می‌شود. پس هیچ مسیری که noindex می‌دهد نباید در
     * robots بسته باشد — جز API که JSON است و خزیدنش فقط بار است.
     */
    public function test_robots_never_hides_a_noindex_page_from_the_crawler(): void
    {
        preg_match_all('/^Disallow:\s*(\S+)/m', (string) $this->get('/robots.txt')->getContent(), $m);

        foreach ($m[1] as $path) {
            if (str_starts_with($path, '/api/')) {
                continue;
            }

            $this->assertFalse($this->noindex($path), "robots.txt مسیرِ noindexِ {$path} را از خزنده پنهان می‌کند");
        }

        $this->assertNotContains('/auth', $m[1]);
    }

    /* ── ضبطِ جلسه ───────────────────────────────────────────────────────── */

    public function test_session_recording_is_allowed_only_on_public_pages(): void
    {
        Observability::save(['clarity_project_id' => 'abc123']);

        $this->assertStringContainsString('clarity.ms', (string) $this->get('/')->headers->get('Content-Security-Policy'));

        foreach (['/dashboard', '/auth', '/bills/5', '/api/v1/me'] as $path) {
            $csp = (string) $this->get($path)->headers->get('Content-Security-Policy');
            $this->assertStringNotContainsString('clarity', $csp, "{$path} نباید ضبطِ جلسه را مجاز کند");
        }
    }

    /* ── جدولِ خطاها ─────────────────────────────────────────────────────── */

    public function test_a_server_error_is_stored_without_query_or_personal_data(): void
    {
        $sql = 'SQLSTATE[23000]: (SQL: insert into users (phone, password) values '
            .'(09121234567, $2y$12$'.str_repeat('a', 53).'))';

        ErrorRecorder::fromException(new RuntimeException($sql), 'https://app.test/api/v1/search?q=راز&phone=09121234567', 'GET');

        $event = ErrorEvent::sole();

        $this->assertSame('https://app.test/api/v1/search', $event->url);
        $this->assertStringNotContainsString('09121234567', $event->message);
        $this->assertStringNotContainsString('$2y$12$', $event->message);
        $this->assertStringContainsString('[موبایل …4567]', $event->message);
        $this->assertStringNotContainsString('09121234567', (string) $event->stack);
    }

    public function test_a_browser_error_is_stored_without_query(): void
    {
        $this->postJson('/api/client-errors', [
            'type' => 'TypeError',
            'message' => 'شکست برای 09351234567',
            'url' => 'https://app.test/pay/7?error=پیام#frag',
        ])->assertNoContent();

        $event = ErrorEvent::sole();

        $this->assertSame('https://app.test/pay/7', $event->url);
        $this->assertStringNotContainsString('09351234567', $event->message);
    }

    /** یک خطا با دو شماره‌ی متفاوت، یک ردیف است نه دو. */
    public function test_masking_happens_before_grouping(): void
    {
        // در یک حلقه، تا فایل و خطِ استثنا یکی باشد و فقط شماره فرق کند
        foreach (['09121231111', '09359871111'] as $phone) {
            ErrorRecorder::fromException(new RuntimeException("duplicate {$phone}"));
        }

        $this->assertSame(1, ErrorEvent::count());
        $this->assertSame(2, (int) ErrorEvent::sole()->occurrences);
    }

    /* ── لاگ: دادهٔ شخصی از روی شکلش ─────────────────────────────────────── */

    private function scrub(string $message, array $context = []): LogRecord
    {
        SecretScrubber::flush();

        return (new SecretScrubber)(new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: $message,
            context: $context,
        ));
    }

    public function test_every_form_of_a_mobile_number_is_masked(): void
    {
        foreach (['09121234567', '+989121234567', '00989121234567', '989121234567'] as $phone) {
            $out = $this->scrub("to={$phone} failed")->message;

            $this->assertStringNotContainsString('912123', $out, $phone);
            $this->assertStringContainsString('…4567', $out, $phone);
        }
    }

    public function test_numbers_that_only_look_similar_are_left_alone(): void
    {
        // مهرِ زمانی، عددِ درونِ عددِ بزرگ‌تر، و تلفنِ ثابت
        $text = 'at 1726000000000 id=109121234567 tel=02188776655 amount=500000';

        $this->assertSame($text, $this->scrub($text)->message);
    }

    public function test_password_hashes_are_masked(): void
    {
        $bcrypt = '$2y$12$'.str_repeat('Ab1./', 10).'xyz';
        $argon = '$argon2id$v=19$m=65536,t=4,p=1$c2FsdA$aGFzaA';

        $out = $this->scrub("a {$bcrypt} b {$argon} c")->message;

        $this->assertStringNotContainsString('$2y$', $out);
        $this->assertStringNotContainsString('$argon2id$', $out);
        $this->assertStringStartsWith('a ', $out);
    }

    /**
     * ⚠️ کارت فقط اگر Luhn بگذراند. شماره‌ی مرجعِ ۱۶ رقمیِ بانک باید در لاگِ
     * پرداخت بماند؛ وگرنه روزِ اختلاف هیچ ردی نیست.
     */
    public function test_a_card_number_is_masked_but_a_bank_reference_is_not(): void
    {
        $out = $this->scrub('card 6037-9975-9915-0001 and 6037997599150001 ref 1234567812345678')->message;

        $this->assertStringNotContainsString('9975', $out);
        $this->assertSame(2, substr_count($out, '[کارت …0001]'));
        $this->assertStringContainsString('ref 1234567812345678', $out);
    }

    public function test_an_iban_and_otp_keys_are_masked(): void
    {
        $record = $this->scrub('settle to IR820540102680020817909002', [
            'otp' => '12345', 'card_number' => '6037997599150001', 'status' => 'ok',
        ]);

        $this->assertStringContainsString('[شبا …9002]', $record->message);
        $this->assertStringNotContainsString('12345', json_encode($record->context, JSON_UNESCAPED_UNICODE));
        $this->assertSame('ok', $record->context['status']);
    }
}
