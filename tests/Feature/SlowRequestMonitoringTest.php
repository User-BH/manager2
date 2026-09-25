<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\User;
use App\Services\Health\AnomalyReport;
use App\Services\Health\HealthReport;
use App\Services\Health\SlowRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * پایشِ کندیِ API (مرحلهٔ ۵ · فاز ۹ · بندِ ۷۸: «Slow API»).
 *
 * پیش از این فاز سلامت دیتابیس، کش، دیسک، صف و زمان‌بند را می‌دید و
 * `alerts:scan` جهشِ خطا و شکستِ پرداخت و پیامک و بکاپ را — ولی نه کندی.
 *
 * ⚠️ CPU و حافظه عمداً اینجا نیستند. نسخه‌ی اولِ این فاز آن‌ها را از
 * `/proc` می‌خواند و بعد برداشته شد: `docs/ALERTING.md` از مرحلهٔ چهارم
 * دلیلش را نوشته — بارِ کلِ ماشین است که برنامه‌ی دیگری هم رویش است، و
 * PHP-FPM با تمام‌شدنِ حافظه همان پردازه‌ای را می‌کشد که باید گزارش بدهد.
 * کندی این مشکل را ندارد: فقط درخواست‌های خودمان سنجیده می‌شوند.
 */
class SlowRequestMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $complex = Complex::factory()->create();

        return User::factory()->create(['complex_id' => $complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true]);
    }

    public function test_a_fast_request_is_not_counted(): void
    {
        $this->actingAs($this->user())->getJson('/api/v1/me')->assertOk();

        $this->assertSame(0, SlowRequests::lastHour());
    }

    /**
     * ⚠️ رازِ کوئری عمداً لاتین است. نسخه‌ی اول کلمه‌ی فارسی داشت و خرابکاری
     * («`fullUrl()` را هم لاگ کن») سبز ماند: آدرس درصدرمز می‌شود و کلمه‌ی
     * فارسی هرگز به همان شکل در لاگ نمی‌آمد.
     */
    public function test_slow_requests_are_counted_and_logged_without_the_query(): void
    {
        config(['alerts.latency.slow_ms' => 0]);
        Log::spy();

        $this->actingAs($this->user())->getJson('/api/v1/search?q=private-term-4821')->assertOk();

        $this->assertSame(1, SlowRequests::lastHour());
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            return $message === 'درخواستِ کند'
                && ! str_contains(json_encode($context, JSON_UNESCAPED_UNICODE), 'private-term-4821')
                && isset($context['ms'], $context['route']);
        })->once();
    }

    public function test_widespread_slowness_raises_an_alert(): void
    {
        config(['alerts.latency.slow_per_hour' => 3]);

        for ($i = 0; $i < 3; $i++) {
            SlowRequests::record();
        }
        $this->assertSame(HealthReport::OK, app(AnomalyReport::class)->run()['checks']['latency']['status']);

        SlowRequests::record();
        $this->assertSame(HealthReport::DEGRADED, app(AnomalyReport::class)->run()['checks']['latency']['status']);
    }

    /** پنجره واقعاً یک ساعت است؛ کندیِ دیروز امروز هشدار نمی‌دهد. */
    public function test_old_slowness_falls_out_of_the_window(): void
    {
        SlowRequests::record();
        $this->assertSame(1, SlowRequests::lastHour());

        $this->travel(70)->minutes();

        $this->assertSame(0, SlowRequests::lastHour());
    }

    /**
     * 🔴 کندی هرگز سرور را از مدار بیرون نمی‌کند: `alerts:scan` خبر می‌دهد و
     * `/up` دست نمی‌خورد. سرورِ پرمشغله را بیرون کشیدن بقیه را هم زمین می‌زند.
     */
    public function test_slowness_never_takes_the_server_out_of_rotation(): void
    {
        for ($i = 0; $i < 500; $i++) {
            SlowRequests::record();
        }

        $this->assertSame(HealthReport::DEGRADED, app(AnomalyReport::class)->run()['checks']['latency']['status']);
        $this->assertNotSame(503, $this->get('/up')->status());
    }
}
