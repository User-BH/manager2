<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Support\Jalali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * پنلِ ادمینِ کل: آمار و تعلیقِ مجتمع (R29).
 *
 * ─── باگی که این تست‌ها محافظت می‌کنند ──────────────────────────────────────
 * `complexes.is_active` از اولین مهاجرت وجود داشت، در `fillable` و `casts`
 * هم بود، ولی **هیچ‌جای برنامه خوانده نمی‌شد**. ادمینِ کل می‌توانست مجتمعی
 * را غیرفعال ثبت کند و ساکنانش دقیقاً مثل قبل کار کنند.
 *
 * همان خانواده‌ی باگِ `unit_user.end_date` در R26: ستونی که هست، پر می‌شود،
 * و هیچ‌کس بر اساسش تصمیم نمی‌گیرد.
 */
class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Complex $complex;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'complex_id' => null,
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
        ]);

        $this->complex = Complex::factory()->create(['is_active' => true]);
        $this->manager = User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => UserRole::ComplexAdmin,
            'is_active' => true,
        ]);
    }

    private function suspend(string $reason = 'عدم تمدید اشتراک'): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/system/complexes/{$this->complex->id}/suspend", ['reason' => $reason])
            ->assertOk();
    }

    // ── تعلیق واقعاً اعمال می‌شود ───────────────────────────────────────────

    public function test_a_suspended_complex_locks_its_members_out(): void
    {
        // پیش از تعلیق، همه‌چیز عادی است
        $this->actingAs($this->manager)->getJson('/api/v1/dashboard')->assertOk();

        $this->suspend();

        /*
         * ⚠️ رگرسیونِ اصلی: پیش از R29 این درخواست همچنان ۲۰۰ می‌گرفت،
         * چون `is_active` هیچ‌جا خوانده نمی‌شد.
         */
        $this->actingAs($this->manager)
            ->getJson('/api/v1/dashboard')
            ->assertForbidden()
            ->assertJson(['complexSuspended' => true]);
    }

    public function test_the_suspension_reason_reaches_the_user(): void
    {
        $this->suspend('بدهی اشتراک از مهر ۱۴۰۵');

        $this->assertStringContainsString(
            'بدهی اشتراک از مهر ۱۴۰۵',
            $this->actingAs($this->manager)->getJson('/api/v1/dashboard')->json('message'),
        );
    }

    public function test_residents_are_locked_out_too_not_just_the_manager(): void
    {
        $resident = User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => UserRole::Owner,
            'is_active' => true,
        ]);

        $this->suspend();

        $this->actingAs($resident)->getJson('/api/v1/dashboard')->assertForbidden();
    }

    public function test_the_session_is_not_destroyed_by_a_suspension(): void
    {
        $this->suspend();

        /*
         * برخلافِ حسابِ غیرفعال، کاربر اینجا تقصیری ندارد و حسابش سالم
         * است. خروجِ اجباری باعث می‌شد گمان کند حسابش حذف شده.
         */
        $this->actingAs($this->manager)->getJson('/api/v1/dashboard')->assertForbidden();
        $this->assertAuthenticatedAs($this->manager);
    }

    public function test_a_suspension_does_not_touch_other_complexes(): void
    {
        $other = Complex::factory()->create(['is_active' => true]);
        $otherManager = User::factory()->create([
            'complex_id' => $other->id,
            'role' => UserRole::ComplexAdmin,
            'is_active' => true,
        ]);

        $this->suspend();

        $this->actingAs($otherManager)->getJson('/api/v1/dashboard')->assertOk();
    }

    public function test_the_super_admin_can_still_work_with_a_suspended_complex(): void
    {
        $this->suspend();

        /*
         * تعلیق برای فشار روی مجتمع است، نه کور کردنِ پلتفرم. اگر ادمینِ کل
         * هم بیرون می‌ماند، تنها راهِ بازگرداندن دست‌کاریِ مستقیمِ دیتابیس بود.
         */
        $this->actingAs($this->superAdmin)->getJson('/api/v1/system/complexes')->assertOk();
        $this->actingAs($this->superAdmin)->getJson('/api/v1/dashboard')->assertOk();
    }

    public function test_activating_restores_access_and_clears_the_reason(): void
    {
        $this->suspend('دلیل قدیمی');

        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/system/complexes/{$this->complex->id}/activate")
            ->assertOk();

        $this->actingAs($this->manager)->getJson('/api/v1/dashboard')->assertOk();

        $fresh = $this->complex->fresh();
        $this->assertTrue($fresh->is_active);
        // متنِ کهنه باید پاک شود، وگرنه در تعلیقِ بعدی دوباره ظاهر می‌شد
        $this->assertNull($fresh->suspension_reason);
        $this->assertNull($fresh->suspended_at);
    }

    // ── دسترسی به خودِ ابزار ───────────────────────────────────────────────

    public function test_a_complex_manager_cannot_suspend_anything(): void
    {
        $this->actingAs($this->manager)
            ->postJson("/api/v1/system/complexes/{$this->complex->id}/suspend", ['reason' => 'تست'])
            ->assertForbidden();

        $this->assertTrue($this->complex->fresh()->is_active);
    }

    public function test_a_suspension_without_a_reason_is_refused(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/system/complexes/{$this->complex->id}/suspend", ['reason' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertTrue($this->complex->fresh()->is_active);
    }

    public function test_the_complex_list_shows_the_suspension_state(): void
    {
        $this->suspend('عدم تمدید');

        $row = collect($this->actingAs($this->superAdmin)->getJson('/api/v1/system/complexes')->json('data'))
            ->firstWhere('id', $this->complex->id);

        $this->assertTrue($row['isSuspended']);
        $this->assertSame('عدم تمدید', $row['suspensionReason']);
        $this->assertNotNull($row['suspendedAt']);
    }

    // ── آمار پلتفرم ────────────────────────────────────────────────────────

    public function test_the_stats_count_the_whole_platform_not_one_complex(): void
    {
        $second = Complex::factory()->create(['is_active' => true]);
        Unit::factory()->create(['complex_id' => $this->complex->id]);
        Unit::factory()->create(['complex_id' => $second->id]);

        /*
         * ⚠️ ادمینِ کل ممکن است مجتمعی را انتخاب کرده باشد. بدونِ
         * `withoutGlobalScopes`، `ComplexScope` بی‌سروصدا همه‌ی اعداد را به
         * همان مجتمع محدود می‌کرد و آمارِ «پلتفرم» عددِ یک مجتمع می‌شد.
         */
        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/system/complexes/{$this->complex->id}/select")
            ->assertOk();

        $stats = $this->actingAs($this->superAdmin)->getJson('/api/v1/system/stats');

        $stats->assertOk();
        $this->assertSame(2, $stats->json('complexes.total'));
        $this->assertSame(2, $stats->json('complexes.units'));
    }

    public function test_the_stats_separate_active_from_suspended(): void
    {
        Complex::factory()->create(['is_active' => true]);
        $this->suspend();

        $stats = $this->actingAs($this->superAdmin)->getJson('/api/v1/system/stats');

        // یکی در setUp و یکی اینجا
        $this->assertSame(2, $stats->json('complexes.total'));
        $this->assertSame(1, $stats->json('complexes.active'));
        $this->assertSame(1, $stats->json('complexes.suspended'));
    }

    public function test_platform_revenue_counts_subscriptions_not_resident_payments(): void
    {
        $unit = Unit::factory()->create(['complex_id' => $this->complex->id]);

        // پولِ ساکن به ساختمان — درآمدِ ما نیست
        Payment::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $unit->id,
            'user_id' => $this->manager->id,
            'amount' => 800000,
            'method' => PaymentMethod::Online,
            'status' => PaymentStatus::Success,
            'period' => Jalali::currentPeriod(),
            'paid_at' => now(),
        ]);

        // اشتراکِ مجتمع — این درآمدِ پلتفرم است
        Subscription::create([
            'complex_id' => $this->complex->id,
            'user_id' => $this->manager->id,
            'plan' => 'pro',
            'status' => 'active',
            'amount' => 2500000,
            'months' => 12,
            'starts_at' => now(),
            'ends_at' => now()->addYear(),
            'paid_at' => now(),
        ]);

        $stats = $this->actingAs($this->superAdmin)->getJson('/api/v1/system/stats');

        $this->assertEqualsWithDelta(2500000.0, $stats->json('money.subscriptionRevenue'), 0.01);
        $this->assertEqualsWithDelta(800000.0, $stats->json('money.paymentsVolume'), 0.01);
        $this->assertSame(1, $stats->json('money.activeSubscriptions'));
    }

    public function test_an_unpaid_subscription_is_not_revenue(): void
    {
        Subscription::create([
            'complex_id' => $this->complex->id,
            'user_id' => $this->manager->id,
            'plan' => 'pro',
            'status' => 'pending',
            'amount' => 2500000,
            'months' => 12,
            'starts_at' => now(),
            'ends_at' => now()->addYear(),
        ]);

        $stats = $this->actingAs($this->superAdmin)->getJson('/api/v1/system/stats');

        $this->assertEqualsWithDelta(0.0, $stats->json('money.subscriptionRevenue'), 0.01);
    }

    public function test_the_growth_trend_covers_six_jalali_months(): void
    {
        $trend = $this->actingAs($this->superAdmin)->getJson('/api/v1/system/stats')->json('trend');

        $this->assertCount(6, $trend);
        // آخرین ماه باید دوره‌ی جاری باشد
        $this->assertSame(Jalali::currentPeriod(), $trend[5]['period']);
        $this->assertNotEmpty($trend[0]['label']);
    }

    public function test_the_stats_report_which_analytics_tools_are_configured(): void
    {
        $analytics = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/system/stats')
            ->json('analytics');

        // فقط روشن/خاموش — شناسه‌ها اعتبارنامه‌اند و در آمار جایی ندارند
        $this->assertSame(['ga4', 'gtm', 'clarity', 'sentry'], array_keys($analytics));
        $this->assertIsBool($analytics['sentry']);
    }

    public function test_a_complex_manager_cannot_see_platform_stats(): void
    {
        $this->actingAs($this->manager)->getJson('/api/v1/system/stats')->assertForbidden();
    }
}
