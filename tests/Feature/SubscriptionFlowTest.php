<?php

namespace Tests\Feature;

use App\Enums\OccupancyStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * چرخه‌ی کامل اشتراک: خرید و تمدیدِ آنلاین و اعمالِ واقعیِ محدودیت‌های پلن. از ۱۲/۲ رسید و تأییدِ دستیِ ادمینِ کل
 * نیست (تصمیمِ قطعیِ کارفرما)؛ ردیف‌های `method = receipt` ِ این‌جا سابقه‌ی پیش از آن‌اند.
 */
class SubscriptionFlowTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $admin;

    private User $resident;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        // موضوعِ این تست خودِ عملیات است، نه نگهبانِ تأییدِ دوباره (فاز ۱۰)
        $this->withConfirmedPassword();

        $this->complex = Complex::create(['type' => 'residential',
            'name' => 'مجتمع آزمون', 'slug' => 'sub-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'none',
        ]);

        $this->admin = User::create([
            'complex_id' => $this->complex->id, 'name' => 'مدیر', 'phone' => '09121110001',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->resident = User::create([
            'complex_id' => $this->complex->id, 'name' => 'ساکن', 'phone' => '09121110002',
            'role' => UserRole::Owner, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->superAdmin = User::create([
            'name' => 'ادمین کل', 'phone' => '09121110003',
            'role' => UserRole::SuperAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
    }

    private function makeUnits(int $count, int $startAt = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            Unit::withoutGlobalScopes()->create([
                'complex_id' => $this->complex->id,
                'unit_number' => (string) ($startAt + $i),
                'floor' => 1, 'area' => 90, 'residents_count' => 2, 'coefficient' => 1,
                'occupancy_status' => OccupancyStatus::OwnerOccupied, 'uses_elevator' => true,
            ]);
        }
    }

    private function activatePro(): Subscription
    {
        return Subscription::create([
            'complex_id' => $this->complex->id,
            'user_id' => $this->admin->id,
            'plan' => SubscriptionPlan::Pro,
            'status' => 'active',
            'method' => 'receipt',
            'amount' => SubscriptionPlan::Pro->price(),
            'months' => 1,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    /* ------------------- اعمال واقعی محدودیت پلن ------------------- */

    public function test_free_plan_blocks_creating_more_than_twenty_units(): void
    {
        $this->makeUnits(20);

        $this->actingAs($this->admin)->postJson('/api/units', [
            'unit_number' => '21', 'floor' => 2, 'area' => 100,
            'residents_count' => 2, 'coefficient' => 1, 'occupancy_status' => 'owner_occupied',
        ])->assertStatus(402)->assertJsonPath('upgradeRequired', true);

        $this->assertSame(20, Unit::withoutGlobalScopes()->where('complex_id', $this->complex->id)->count());
    }

    public function test_pro_plan_allows_passing_the_unit_limit(): void
    {
        $this->makeUnits(20);
        $this->activatePro();

        $this->actingAs($this->admin)->postJson('/api/units', [
            'unit_number' => '21', 'floor' => 2, 'area' => 100,
            'residents_count' => 2, 'coefficient' => 1, 'occupancy_status' => 'owner_occupied',
        ])->assertStatus(201);

        $this->assertSame(21, Unit::withoutGlobalScopes()->where('complex_id', $this->complex->id)->count());
    }

    /** اشتراکِ منقضی نباید محدودیت را بردارد. */
    public function test_expired_subscription_falls_back_to_the_free_limit(): void
    {
        $this->makeUnits(20);
        $this->activatePro()->update([
            'starts_at' => now()->subMonths(3),
            'ends_at' => now()->subDay(),
        ]);

        $this->actingAs($this->admin)->postJson('/api/units', [
            'unit_number' => '21', 'floor' => 2, 'area' => 100,
            'residents_count' => 2, 'coefficient' => 1, 'occupancy_status' => 'owner_occupied',
        ])->assertStatus(402);
    }

    public function test_free_plan_blocks_excel_export_but_allows_pdf(): void
    {
        $this->makeUnits(1);

        $this->actingAs($this->admin)->get('/bills/export.xlsx')->assertStatus(402);

        $this->activatePro();
        $this->actingAs($this->admin)->get('/bills/export.xlsx')->assertOk();
    }

    public function test_free_plan_blocks_connecting_a_real_bank_gateway(): void
    {
        $payload = [
            'name' => 'مجتمع آزمون', 'currency' => 'toman', 'charge_due_day' => 10,
            'penalty_type' => 'fixed', 'penalty_value' => 0, 'penalty_grace_days' => 0,
        ];

        $this->actingAs($this->admin)
            ->putJson('/api/settings', $payload + ['payment_gateway' => 'mellat'])
            ->assertStatus(402);

        // سندباکس باید در پلن رایگان آزاد باشد
        $this->actingAs($this->admin)
            ->putJson('/api/settings', $payload + ['payment_gateway' => 'fake'])
            ->assertOk();

        $this->activatePro();
        $this->actingAs($this->admin)
            ->putJson('/api/settings', $payload + ['payment_gateway' => 'mellat'])
            ->assertOk();
    }

    /* -------------- رسید و تأییدِ دستی بازنشسته‌اند (۱۲/۲) -------------- */

    /**
     * 🔴 تصمیمِ قطعیِ کارفرما (۱۲/۲): خرید و تمدید فقط آنلاین. مسیرهای قدیمیِ رسید، تأیید و رد یک چرخه‌ی استقرار
     * ۴۱۰ ِ روشن می‌دهند و هیچ ردیفی نمی‌سازند و هیچ اشتراکی را فعال نمی‌کنند — برای مدیر، ساکن و ادمینِ کل.
     * نگهبانِ مدل و تمدیدِ آنلاین: `SubscriptionActivationTest`، `SubscriptionRenewalTest`.
     */
    public function test_the_retired_receipt_route_creates_nothing_for_anyone(): void
    {
        Storage::fake('local');

        foreach ([$this->admin, $this->resident] as $user) {
            $this->actingAs($user)->postJson('/api/subscription/receipt', [
                'plan' => 'pro', 'receipt' => UploadedFile::fake()->image('r.jpg'),
            ])->assertStatus(410)->assertJsonPath('code', 'subscription.online_only');
        }

        $this->assertSame(0, Subscription::count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        // و ساکن خریدِ آنلاین هم نمی‌تواند
        $this->actingAs($this->resident)
            ->post('/subscription/checkout', ['plan' => 'basic'])
            ->assertStatus(403);
    }

    /** مدیرِ مجتمع حتی به نسخه‌ی بازنشسته‌ی مسیرهای ادمینِ کل نمی‌رسد. */
    public function test_complex_admin_cannot_reach_the_review_endpoints(): void
    {
        $subscription = $this->activatePro();

        $this->actingAs($this->admin)->getJson('/api/system/subscriptions')->assertStatus(403);
        $this->actingAs($this->admin)
            ->postJson("/api/system/subscriptions/{$subscription->id}/approve")
            ->assertStatus(403);
    }

    /* ---------------- خرید آنلاین از راه درایور مشترک ---------------- */

    /**
     * درگاه اشتراک همان درایورهای بانکیِ شارژ را استفاده می‌کند (از راه واسط
     * GatewayOrder). این تست ثابت می‌کند مسیر آنلاین برای اشتراک هم کامل کار
     * می‌کند، نه فقط برای قبض.
     */
    public function test_online_checkout_activates_the_subscription(): void
    {
        config(['subscription.gateway' => 'sandbox']);

        $this->actingAs($this->admin)
            ->post('/subscription/checkout', ['plan' => 'basic'])
            ->assertRedirect();

        $subscription = Subscription::firstOrFail();
        $this->assertSame('online', $subscription->method);
        $this->assertSame('fake', $subscription->gateway);
        $this->assertNotNull($subscription->ref_id);

        $this->actingAs($this->admin)
            ->get("/subscription/callback/{$subscription->id}?ref={$subscription->ref_id}")
            ->assertRedirectContains('checkout=success');

        $subscription->refresh();
        $this->assertSame('active', $subscription->status);
        $this->assertNotNull($subscription->tracking_code);
    }

    public function test_checkout_is_disabled_when_a_real_gateway_has_no_terminal(): void
    {
        // درگاه ملت بدون شماره ترمینال نباید «فعال» شمرده شود، وگرنه کاربر
        // وسط راه به خطای بانک می‌خورد.
        config(['subscription.gateway' => 'mellat', 'subscription.config.terminal_id' => null]);

        $this->actingAs($this->admin)->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('checkoutEnabled', false);

        config(['subscription.config.terminal_id' => '123456']);

        $this->actingAs($this->admin)->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('checkoutEnabled', true);
    }

    /* ------------------ اشتراک متعلق به مجتمع است ------------------ */

    public function test_subscription_is_shared_by_every_admin_of_the_complex(): void
    {
        $this->activatePro();

        $secondAdmin = User::create([
            'complex_id' => $this->complex->id, 'name' => 'مدیر دوم', 'phone' => '09121110009',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        // مدیر دوم که خودش خرید نکرده هم باید اشتراک فعال را ببیند
        $this->actingAs($secondAdmin)->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('currentPlan', 'pro')
            ->assertJsonPath('usage.unitLimit', null);
    }

    public function test_another_complex_admin_cannot_cancel_our_subscription(): void
    {
        $subscription = $this->activatePro();

        $otherComplex = Complex::create(['type' => 'residential', 'name' => 'دیگری', 'slug' => 'other-'.uniqid()]);
        $otherAdmin = User::create([
            'complex_id' => $otherComplex->id, 'name' => 'مدیر دیگر', 'phone' => '09121110010',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->actingAs($otherAdmin)
            ->postJson("/api/subscription/{$subscription->id}/cancel")
            ->assertStatus(403);

        $this->assertSame('active', $subscription->fresh()->status);
    }
}
