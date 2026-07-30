<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ErrorEvent;
use App\Models\Setting;
use App\Models\User;
use App\Services\ErrorRecorder;
use App\Support\Observability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * تنظیماتِ پایش و ثبتِ خطا.
 *
 * قیدِ محصولی که این تست‌ها نگه می‌دارند: **صاحبِ پروژه باید بتواند بدونِ هیچ
 * تغییری در کد، شناسه‌های تحلیلیِ خودش را وصل کند** — یا از `.env` یا از پنل،
 * و پنل همیشه مقدم باشد.
 */
class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    }

    /**
     * ساختِ استثنا از **یک خطِ ثابت**.
     *
     * اثرِ انگشتِ گروه‌بندی شاملِ فایل و شماره‌ی خط است، پس دو `new RuntimeException`
     * که در دو خطِ متفاوتِ تست نوشته شوند عمداً دو خطای جدا شمرده می‌شوند — و
     * این درست است، چون دو نقطه‌ی متفاوتِ کد واقعاً دو باگ‌اند. برای سنجیدنِ
     * گروه‌بندی باید هر دو بار از همین‌جا ساخته شوند.
     */
    private function sampleException(string $message): RuntimeException
    {
        return new RuntimeException($message);
    }

    public function test_env_value_is_used_when_panel_is_empty(): void
    {
        config()->set('observability.ga4.measurement_id', 'G-FROMENV');

        $this->assertSame('G-FROMENV', Observability::config()['ga4']['measurement_id']);
        $this->assertSame('env', Observability::forPanel()['ga4_measurement_id']['source']);
    }

    public function test_panel_value_overrides_env(): void
    {
        config()->set('observability.ga4.measurement_id', 'G-FROMENV');
        Observability::save(['ga4_measurement_id' => 'G-FROMPANEL']);

        $this->assertSame('G-FROMPANEL', Observability::config()['ga4']['measurement_id']);
        $this->assertSame('panel', Observability::forPanel()['ga4_measurement_id']['source']);
    }

    public function test_clearing_panel_value_falls_back_to_env(): void
    {
        config()->set('observability.ga4.measurement_id', 'G-FROMENV');
        Observability::save(['ga4_measurement_id' => 'G-FROMPANEL']);
        Observability::save(['ga4_measurement_id' => '']);

        // خالی‌کردن یعنی «برگرد به .env»، نه «خالی باشد»
        $this->assertSame('G-FROMENV', Observability::config()['ga4']['measurement_id']);
    }

    public function test_secrets_are_encrypted_at_rest_and_masked_when_read(): void
    {
        Observability::save(['ga4_api_secret' => 'super-secret-value']);

        $rawRow = Setting::whereNull('complex_id')->where('key', 'observability')->value('value');

        // مقدارِ خام نباید در دیتابیس قابلِ خواندن باشد
        $this->assertStringNotContainsString('super-secret-value', (string) $rawRow);

        // ولی مقدارِ مؤثر باید درست بازگردانده شود
        $this->assertSame('super-secret-value', Observability::config()['ga4']['api_secret']);

        // و در پاسخِ پنل فقط ماسک دیده شود
        $panel = Observability::forPanel()['ga4_api_secret'];
        $this->assertStringStartsWith('••••', $panel['value']);
        $this->assertStringNotContainsString('super-secret', $panel['value']);
    }

    public function test_resubmitting_the_mask_keeps_the_stored_secret(): void
    {
        Observability::save(['ga4_api_secret' => 'original-secret']);
        $masked = Observability::forPanel()['ga4_api_secret']['value'];

        // ادمین فرم را بدونِ دست‌زدن به این فیلد ذخیره می‌کند
        Observability::save(['ga4_api_secret' => $masked]);

        // اگر این بشکند، هر ذخیره‌ی فرم توکن را با «••••» خراب می‌کرد
        $this->assertSame('original-secret', Observability::config()['ga4']['api_secret']);
    }

    public function test_client_config_never_leaks_secrets(): void
    {
        Observability::save([
            'ga4_measurement_id' => 'G-PUBLIC',
            'ga4_api_secret' => 'must-not-leak',
            'sentry_auth_token' => 'token-must-not-leak',
        ]);

        $client = Observability::clientConfig();
        $encoded = json_encode($client);

        $this->assertStringContainsString('G-PUBLIC', (string) $encoded);
        $this->assertStringNotContainsString('must-not-leak', (string) $encoded);
        $this->assertStringNotContainsString('token-must-not-leak', (string) $encoded);
    }

    public function test_nothing_is_injected_into_the_page_when_nothing_is_configured(): void
    {
        // هیچ شناسه‌ای تنظیم نشده ⇒ تگِ پیکربندی اصلاً نباید چاپ شود
        config()->set('observability.sentry.dsn', null);
        config()->set('observability.sentry.client_dsn', null);
        config()->set('observability.ga4.measurement_id', null);
        config()->set('observability.gtm.container_id', null);
        config()->set('observability.clarity.project_id', null);

        $this->assertSame([], Observability::clientConfig());
    }

    public function test_sentry_environment_only_ships_alongside_a_dsn(): void
    {
        // `sentry_environment` پیش‌فرضی از APP_ENV دارد؛ نباید به‌تنهایی
        // باعث شود تگِ پیکربندی در هر صفحه چاپ شود.
        config()->set('observability.sentry.environment', 'production');
        config()->set('observability.sentry.dsn', null);
        config()->set('observability.sentry.client_dsn', null);

        $this->assertArrayNotHasKey('sentryEnvironment', Observability::clientConfig());

        Observability::save(['sentry_dsn' => 'https://key@example.ingest.sentry.io/1']);
        $this->assertArrayHasKey('sentryEnvironment', Observability::clientConfig());
    }

    public function test_only_super_admin_can_read_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::ComplexAdmin]))
            ->getJson('/api/system/observability')
            ->assertForbidden();

        $this->actingAs($this->superAdmin())
            ->getJson('/api/system/observability')
            ->assertOk()
            ->assertJsonStructure(['fields', 'services', 'summary']);
    }

    public function test_measurement_id_format_is_validated(): void
    {
        $this->actingAs($this->superAdmin())
            ->putJson('/api/system/observability', ['ga4_measurement_id' => 'not-a-valid-id'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ga4_measurement_id');
    }

    public function test_server_exceptions_are_recorded_and_grouped(): void
    {
        ErrorRecorder::fromException($this->sampleException('چیزی خراب شد'), 'http://x/test', 'GET');
        ErrorRecorder::fromException($this->sampleException('چیزی خراب شد'), 'http://x/test', 'GET');

        // دو رخدادِ یکسان ⇒ یک ردیف با شمارنده‌ی ۲، نه دو ردیف
        $this->assertSame(1, ErrorEvent::count());
        $this->assertSame(2, ErrorEvent::first()->occurrences);
    }

    public function test_same_message_from_different_places_stays_separate(): void
    {
        // اثرِ انگشت شاملِ محل است: دو نقطه‌ی متفاوتِ کد دو باگِ متفاوت‌اند،
        // حتی اگر پیامشان یکی باشد.
        ErrorRecorder::fromException(new RuntimeException('یکسان'));
        ErrorRecorder::fromException(new RuntimeException('یکسان'));

        $this->assertSame(2, ErrorEvent::count());
    }

    public function test_expected_exceptions_are_not_recorded(): void
    {
        // ۴۰۴ و خطای اعتبارسنجی «باگ» نیستند و فقط پنل را شلوغ می‌کنند
        ErrorRecorder::fromException(
            new NotFoundHttpException('missing')
        );
        ErrorRecorder::fromException(
            ValidationException::withMessages(['x' => 'y'])
        );

        $this->assertSame(0, ErrorEvent::count());
    }

    public function test_browser_can_report_errors_without_being_logged_in(): void
    {
        // ارزشمندترین خطاها همان‌هایی‌اند که پیش از ورود رخ می‌دهند
        $this->postJson('/api/client-errors', [
            'type' => 'TypeError',
            'message' => 'x is not a function',
            'url' => 'https://example.test/auth',
        ])->assertNoContent();

        $this->assertSame(1, ErrorEvent::where('source', 'client')->count());
    }

    public function test_client_error_requires_a_message(): void
    {
        $this->postJson('/api/client-errors', ['type' => 'TypeError'])
            ->assertStatus(422);
    }

    public function test_super_admin_can_resolve_an_error(): void
    {
        ErrorRecorder::fromException($this->sampleException('یک خطا'));
        $event = ErrorEvent::first();

        $this->actingAs($this->superAdmin())
            ->patchJson("/api/system/observability/errors/{$event->id}/resolve")
            ->assertOk();

        $this->assertTrue($event->fresh()->is_resolved);
    }

    public function test_recurring_error_reopens_a_resolved_one(): void
    {
        ErrorRecorder::fromException($this->sampleException('برگشت‌پذیر'));
        ErrorEvent::query()->update(['is_resolved' => true]);

        ErrorRecorder::fromException($this->sampleException('برگشت‌پذیر'));

        // خطایی که دوباره رخ داده دیگر «بررسی‌شده» نیست
        $this->assertFalse(ErrorEvent::first()->is_resolved);
    }
}
