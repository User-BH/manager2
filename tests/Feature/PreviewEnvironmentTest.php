<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Payment\FakeGateway;
use App\Services\Payment\GatewayManager;
use App\Services\Sms\LogSms;
use App\Services\Sms\SmsManager;
use App\Services\Subscription\SubscriptionGatewayManager;
use App\Support\Observability;
use App\Support\Preview;
use App\Support\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Preview و ایمنیِ داده (مرحلهٔ ۹ · فاز ۸؛ تصمیمِ ۱۱ ِ کارفرما، بندهای ۲۲ تا ۲۵ ِ فایل ۲).
 *
 * Preview همان برنامه است با `APP_ENV=preview`؛ این آزمون‌ها مرزش را می‌سنجند:
 * پایگاه‌داده‌ی برچسب‌دار (Preview هرگز روی دادهٔ واقعی، محصول هرگز روی Preview)،
 * کلیدِ دسترسی، کش و کوکیِ جدا، و جایگزینی‌های ایمنی — پیامک، درگاه، تحلیل، ایندکس.
 * ساختنِ پایگاه‌داده‌ی Preview در پردازه‌ی جدا: `PreviewResetTest`.
 */
class PreviewEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'preview-access-key-for-tests-0123456789';

    /**
     * ⚠️ درخواست با `env = production` میزبان‌های مجاز را **ایستا** روی `Request` می‌نشاند
     * (`TrustHosts`) و به آزمون‌های بعدیِ همین پردازه نشت می‌کرد (`SeoTest` با میزبانِ جعلی
     * «Untrusted Host» گرفت). در FPM هر درخواست پردازه‌ی تازه است؛ این فقط پاک‌سازیِ آزمون است.
     */
    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    /* ── برچسبِ پایگاه‌داده ─────────────────────────────────────────────── */

    public function test_a_preview_on_an_unmarked_database_serves_nothing(): void
    {
        $this->asPreview(marked: false);

        $this->getJson('/api/v1/features')->assertStatus(503)->assertJsonPath('code', 'preview.refused');
        $this->get('/')->assertStatus(503);
    }

    /** بندِ ۲۲: «انتشار با جایگزینیِ پایگاه‌داده» — محصول روی پایگاه‌داده‌ی Preview سرویس نمی‌دهد. */
    public function test_production_on_a_preview_database_serves_nothing(): void
    {
        Preview::markDatabase();
        $this->app['env'] = 'production';

        $this->getJson('/api/v1/features')->assertStatus(503)->assertJsonPath('code', 'preview.refused');
    }

    /** محصولِ بی‌برچسب و توسعه‌ی محلی دست نمی‌خورند. */
    public function test_unmarked_production_and_local_development_are_untouched(): void
    {
        $this->app['env'] = 'production';
        $this->getJson('/api/v1/features')->assertOk();

        $this->app['env'] = 'local';
        Preview::markDatabase();
        $this->getJson('/api/v1/features')->assertOk();
    }

    /**
     * نگهبان روی **هر** درخواست است: در محصول دقیقاً یک کوئری، در توسعه و آزمون هیچ —
     * وگرنه سقفِ کوئریِ همه‌ی مسیرها (`ScalabilityTest`، `QueryProfileTest`) بی‌صدا بالا می‌رود.
     */
    public function test_the_boundary_check_costs_one_query_in_production_and_none_elsewhere(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->app['env'] = 'testing';
        $this->assertSame([], Preview::violations());
        $this->assertSame([], $queries, 'نگهبان در آزمون و توسعه کوئری زد.');

        $this->app['env'] = 'production';
        $this->assertSame([], Preview::violations());
        $this->assertCount(1, $queries, 'نگهبان در محصول بیش از یک کوئری زد: '.implode(' | ', $queries));
    }

    public function test_preview_refuses_to_run_on_the_production_database_name(): void
    {
        $this->asPreview();
        config(['preview.production_database' => \DB::connection()->getDatabaseName()]);

        $this->getJson('/api/v1/features')->assertStatus(503);
    }

    /* ── پیکربندیِ ایمنی و کلیدِ دسترسی ─────────────────────────────────── */

    public function test_preview_needs_an_access_key_and_its_own_cache_and_cookie(): void
    {
        foreach ([
            ['preview.access_key' => null],
            ['cache.prefix' => 'sakena-cache-'],
            ['session.cookie' => 'sakena_session'],
        ] as $broken) {
            $this->asPreview();
            config($broken);

            $this->getJson('/api/v1/features')->assertStatus(503);
        }
    }

    /** کلیدِ دسترسی یک بار در نشانی، بعد فقط کوکیِ هش‌شده؛ بی آن ۴۰۳. */
    public function test_the_access_key_gates_every_request(): void
    {
        $this->asPreview();

        $this->getJson('/api/v1/features')->assertForbidden()->assertJsonPath('code', 'preview.refused');
        $this->get('/?preview_access=wrong-key')->assertForbidden();

        $entry = $this->get('/support?preview_access='.self::KEY)->assertRedirect();
        $this->assertStringNotContainsString(self::KEY, (string) $entry->headers->get('Location'), 'کلید در نشانیِ مقصد ماند.');
        $cookie = collect($entry->headers->getCookies())->first(fn ($c) => $c->getName() === Preview::ACCESS_COOKIE);
        $this->assertNotNull($cookie);

        // درخواستِ JSON ِ آزمون کوکی را فقط با `withCredentials` می‌فرستد
        $this->withCredentials()->withCookie(Preview::ACCESS_COOKIE, Preview::accessToken())->getJson('/api/v1/features')->assertOk();
        $this->withCredentials()->withCookie(Preview::ACCESS_COOKIE, self::KEY)->getJson('/api/v1/features')->assertForbidden();
    }

    /* ── جایگزینی‌های ایمنی ─────────────────────────────────────────────── */

    /** پیامکِ واقعی هرگز؛ کدِ همان سازوکارِ واقعی برای بازبین، و نه در لاگ. */
    public function test_preview_never_sends_real_sms_and_shows_the_code_instead(): void
    {
        SystemSettings::set('sms_driver', 'kavenegar');
        SystemSettings::set('sms_config', ['api_key' => 'live-key-that-must-not-be-used']);
        $this->asPreview();
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });

        $this->assertInstanceOf(LogSms::class, app(SmsManager::class)->driver());
        $result = app(OtpService::class)->request('09127000123');

        $this->assertTrue($result['ok']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $result['dev_code']);
        $this->assertStringNotContainsString((string) $result['dev_code'], implode("\n", $logged), 'کد در لاگِ Preview نشست.');

        // و روی محصول هرگز
        $this->app['env'] = 'production';
        app()->forgetInstance(SmsManager::class);
        SystemSettings::set('sms_driver', 'log');
        app(OtpService::class)->clear('09127000124');
        $this->assertNull(app(OtpService::class)->request('09127000124')['dev_code']);
    }

    public function test_preview_never_uses_a_real_payment_gateway(): void
    {
        $this->asPreview();
        $complex = Complex::factory()->create(['payment_gateway' => 'mellat', 'gateway_config' => ['terminal_id' => '123']]);
        config(['subscription.gateway' => 'zarinpal', 'subscription.config.merchant_id' => 'live-merchant']);

        $this->assertInstanceOf(FakeGateway::class, app(GatewayManager::class)->for($complex));
        $this->assertTrue(app(GatewayManager::class)->isOnlineEnabled($complex));
        $this->assertInstanceOf(FakeGateway::class, app(SubscriptionGatewayManager::class)->driver());
    }

    /** آمارِ محصول آلوده نمی‌شود و هیچ صفحه‌ای ایندکس نمی‌شود. */
    public function test_preview_has_no_analytics_and_no_indexing(): void
    {
        config([
            'observability.ga4.measurement_id' => 'G-LIVE123', 'observability.ga4.api_secret' => 'live-secret',
            'observability.gtm.container_id' => 'GTM-LIVE', 'observability.clarity.project_id' => 'clarity-live',
        ]);
        // کنترلِ مثبت: بیرون از Preview همین مقدارها واقعاً بیرون می‌روند — آزمون پوچ نیست
        $this->assertSame('G-LIVE123', Observability::clientConfig()['ga4MeasurementId'] ?? null);

        $this->asPreview();

        $client = Observability::clientConfig();
        $this->assertArrayNotHasKey('ga4MeasurementId', $client);
        $this->assertArrayNotHasKey('gtmContainerId', $client);
        $this->assertArrayNotHasKey('clarityProjectId', $client);
        $this->assertNull(Observability::config()['ga4']['api_secret']);

        $access = $this->withCookie(Preview::ACCESS_COOKIE, Preview::accessToken());
        $access->get('/')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $robots = $access->get('/robots.txt')->assertOk();
        $this->assertStringContainsString('Disallow: /', (string) $robots->getContent());
        $this->assertStringNotContainsString('Sitemap', (string) $robots->getContent());
    }

    /** نوارِ Preview روی صفحه‌ها؛ روی محصول هرگز. */
    public function test_the_banner_shows_on_preview_and_never_in_production(): void
    {
        $this->asPreview();
        $this->withCookie(Preview::ACCESS_COOKIE, Preview::accessToken())->get('/')->assertOk()->assertSee('id="preview-banner"', false);
        $this->withCookie(Preview::ACCESS_COOKIE, Preview::accessToken())->get('/dashboard')->assertSee('id="preview-banner"', false);

        $this->app['env'] = 'production';
        Preview::markDatabase();
        \DB::table('settings')->where('key', Preview::ROLE_KEY)->delete();
        $this->get('/')->assertOk()->assertDontSee('preview-banner', false);
    }

    /* ── preview:reset (قفل‌ها؛ ساختنِ واقعی در `PreviewResetTest`) ─────────── */

    public function test_preview_reset_refuses_outside_preview(): void
    {
        foreach (['production', 'local', 'testing'] as $env) {
            $this->app['env'] = $env;
            $this->artisan('preview:reset', ['--force' => true])->assertFailed();
        }
    }

    /** پایگاه‌داده‌ی بی‌برچسبِ دارای کاربر شاید محصول است: هرگز پاک نمی‌شود. */
    public function test_preview_reset_refuses_an_unmarked_database_with_users(): void
    {
        $user = User::factory()->create(['role' => UserRole::Owner]);
        $this->asPreview(marked: false);

        $this->artisan('preview:reset', ['--force' => true])->assertFailed();
        $this->assertNotNull(User::withoutGlobalScopes()->find($user->id));

        config(['preview.production_database' => \DB::connection()->getDatabaseName()]);
        Preview::markDatabase();
        $this->artisan('preview:reset', ['--force' => true])->assertFailed();
        $this->assertNotNull(User::withoutGlobalScopes()->find($user->id));
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    private function asPreview(bool $marked = true): void
    {
        $this->app['env'] = 'preview';
        config([
            'preview.access_key' => self::KEY,
            'preview.production_database' => null,
            'cache.prefix' => 'sakena_preview_',
            'session.cookie' => 'sakena_preview_session',
        ]);
        app()->forgetInstance(SmsManager::class);

        if ($marked) {
            Preview::markDatabase();
        } else {
            \DB::table('settings')->where('key', Preview::ROLE_KEY)->delete();
        }
    }
}
