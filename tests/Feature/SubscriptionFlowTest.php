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
 * چرخه‌ی کامل اشتراک: خرید با رسید، بررسی توسط ادمین کل، و اعمال واقعی
 * محدودیت‌های پلن.
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

        $this->complex = Complex::create([
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

    /* --------------------- خرید با آپلود رسید --------------------- */

    public function test_admin_can_upload_a_subscription_receipt(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin)->postJson('/api/subscription/receipt', [
            'plan' => 'pro',
            'paid_on' => now()->toDateString(),
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertStatus(201)
            ->assertJsonPath('subscription.status', 'pending')
            ->assertJsonPath('subscription.hasReceipt', true);

        $subscription = Subscription::firstOrFail();
        $this->assertSame('receipt', $subscription->method);
        // مبلغ باید از enum بیاید نه از درخواست
        $this->assertEquals(SubscriptionPlan::Pro->price(), (float) $subscription->amount);
        Storage::disk('local')->assertExists($subscription->receipt_path);
    }

    public function test_receipt_upload_rejects_bad_files_and_future_dates(): void
    {
        Storage::fake('local');

        // فایل اجرایی نباید پذیرفته شود
        $this->actingAs($this->admin)->postJson('/api/subscription/receipt', [
            'plan' => 'pro',
            'receipt' => UploadedFile::fake()->create('hack.php', 40, 'application/x-php'),
        ])->assertStatus(422)->assertJsonValidationErrors('receipt');

        // تاریخ واریز در آینده بی‌معنی است
        $this->actingAs($this->admin)->postJson('/api/subscription/receipt', [
            'plan' => 'pro',
            'paid_on' => now()->addWeek()->toDateString(),
            'receipt' => UploadedFile::fake()->image('r.jpg'),
        ])->assertStatus(422)->assertJsonValidationErrors('paid_on');
    }

    public function test_residents_cannot_buy_a_subscription(): void
    {
        Storage::fake('local');

        $this->actingAs($this->resident)->postJson('/api/subscription/receipt', [
            'plan' => 'pro',
            'receipt' => UploadedFile::fake()->image('r.jpg'),
        ])->assertStatus(403);

        $this->actingAs($this->resident)
            ->post('/subscription/checkout', ['plan' => 'pro'])
            ->assertStatus(403);
    }

    public function test_only_one_pending_receipt_request_at_a_time(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin)->postJson('/api/subscription/receipt', [
            'plan' => 'pro', 'receipt' => UploadedFile::fake()->image('a.jpg'),
        ])->assertStatus(201);

        $this->actingAs($this->admin)->postJson('/api/subscription/receipt', [
            'plan' => 'pro', 'receipt' => UploadedFile::fake()->image('b.jpg'),
        ])->assertStatus(422);

        $this->assertSame(1, Subscription::count());
    }

    /* ------------------ بررسی توسط ادمین کل ------------------ */

    public function test_super_admin_approves_a_receipt_and_the_plan_becomes_active(): void
    {
        Storage::fake('local');
        $this->makeUnits(20);

        $this->actingAs($this->admin)->postJson('/api/subscription/receipt', [
            'plan' => 'pro', 'receipt' => UploadedFile::fake()->image('r.jpg'),
        ])->assertStatus(201);

        $subscription = Subscription::firstOrFail();

        // پیش از تایید، محدودیت رایگان هنوز برقرار است
        $this->actingAs($this->admin)->postJson('/api/units', [
            'unit_number' => '21', 'floor' => 2, 'area' => 100,
            'residents_count' => 2, 'coefficient' => 1, 'occupancy_status' => 'owner_occupied',
        ])->assertStatus(402);

        $this->actingAs($this->superAdmin)
            ->postJson("/api/system/subscriptions/{$subscription->id}/approve")
            ->assertOk();

        $subscription->refresh();
        $this->assertSame('active', $subscription->status);
        $this->assertNotNull($subscription->ends_at);
        $this->assertSame($this->superAdmin->id, $subscription->reviewed_by);

        // و حالا محدودیت برداشته شده است
        $this->actingAs($this->admin)->postJson('/api/units', [
            'unit_number' => '21', 'floor' => 2, 'area' => 100,
            'residents_count' => 2, 'coefficient' => 1, 'occupancy_status' => 'owner_occupied',
        ])->assertStatus(201);
    }

    public function test_super_admin_can_reject_a_receipt(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin)->postJson('/api/subscription/receipt', [
            'plan' => 'pro', 'receipt' => UploadedFile::fake()->image('r.jpg'),
        ]);
        $subscription = Subscription::firstOrFail();

        $this->actingAs($this->superAdmin)
            ->postJson("/api/system/subscriptions/{$subscription->id}/reject", ['note' => 'مبلغ نمی‌خواند'])
            ->assertOk();

        $subscription->refresh();
        $this->assertSame('failed', $subscription->status);
        $this->assertSame('مبلغ نمی‌خواند', $subscription->review_note);
    }

    public function test_a_request_cannot_be_reviewed_twice(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin)->postJson('/api/subscription/receipt', [
            'plan' => 'pro', 'receipt' => UploadedFile::fake()->image('r.jpg'),
        ]);
        $subscription = Subscription::firstOrFail();

        $this->actingAs($this->superAdmin)->postJson("/api/system/subscriptions/{$subscription->id}/approve")->assertOk();
        $this->actingAs($this->superAdmin)->postJson("/api/system/subscriptions/{$subscription->id}/approve")->assertStatus(422);
    }

    /** مدیر مجتمع نباید بتواند رسید خودش را تایید کند. */
    public function test_complex_admin_cannot_reach_the_review_endpoints(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin)->postJson('/api/subscription/receipt', [
            'plan' => 'pro', 'receipt' => UploadedFile::fake()->image('r.jpg'),
        ]);
        $subscription = Subscription::firstOrFail();

        $this->actingAs($this->admin)->getJson('/api/system/subscriptions')->assertStatus(403);
        $this->actingAs($this->admin)
            ->postJson("/api/system/subscriptions/{$subscription->id}/approve")
            ->assertStatus(403);

        $this->assertSame('pending', $subscription->fresh()->status);
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
            ->post('/subscription/checkout', ['plan' => 'pro'])
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

        $otherComplex = Complex::create(['name' => 'دیگری', 'slug' => 'other-'.uniqid()]);
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
