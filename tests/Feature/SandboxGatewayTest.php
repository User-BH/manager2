<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\OccupancyStatus;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use App\Services\Payment\GatewayManager;
use App\Services\Subscription\SubscriptionGatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * درگاه ساختگی نباید روی سرور واقعی کار کند.
 *
 * `FakeGateway` هر بازگشتی را تایید می‌کند، پس اگر روی production فعال بماند
 * هر «پرداخت آنلاین» بدون جابه‌جایی پول، قبض را تسویه‌شده علامت می‌زند.
 */
class SandboxGatewayTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $admin;

    private User $resident;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::create([
            'name' => 'مجتمع سندباکس', 'slug' => 'sbx-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'fake',
        ]);

        $this->admin = User::create([
            'complex_id' => $this->complex->id, 'name' => 'مدیر', 'phone' => '09181110001',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->resident = User::create([
            'complex_id' => $this->complex->id, 'name' => 'ساکن', 'phone' => '09181110002',
            'role' => UserRole::Owner, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_number' => '1', 'floor' => 1, 'area' => 100,
            'residents_count' => 2, 'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied, 'uses_elevator' => true,
        ]);

        $this->resident->units()->attach($this->unit->id, [
            'complex_id' => $this->complex->id, 'relation' => 'owner',
            'share_percent' => 100, 'is_current' => true,
        ]);
    }

    /** شبیه‌سازی سرور واقعی: سندباکس خاموش. */
    private function asProduction(): void
    {
        config(['payment.sandbox_enabled' => false]);
    }

    private function makeBill(): Bill
    {
        return Bill::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_id' => $this->unit->id, 'period' => '1405-04',
            'total_amount' => 500000, 'paid_amount' => 0, 'status' => BillStatus::Unpaid, 'issued_at' => now(),
        ]);
    }

    /* --------------------- درگاه شارژ مجتمع --------------------- */

    public function test_the_sandbox_driver_is_refused_on_a_production_server(): void
    {
        $this->asProduction();

        $this->expectException(RuntimeException::class);

        app(GatewayManager::class)->for($this->complex);
    }

    public function test_the_sandbox_driver_still_works_outside_production(): void
    {
        config(['payment.sandbox_enabled' => true]);

        $this->assertInstanceOf(
            \App\Services\Payment\FakeGateway::class,
            app(GatewayManager::class)->for($this->complex),
        );
    }

    public function test_the_online_button_is_hidden_when_the_sandbox_is_off(): void
    {
        $this->asProduction();
        $bill = $this->makeBill();

        $this->actingAs($this->resident)->getJson("/api/pay/{$bill->id}")
            ->assertOk()
            ->assertJsonPath('onlineEnabled', false);
    }

    public function test_starting_an_online_payment_does_not_settle_the_bill(): void
    {
        $this->asProduction();
        $bill = $this->makeBill();

        // کاربر مسیر پرداخت آنلاین را مستقیم صدا می‌زند (دور زدن رابط کاربری)
        $this->actingAs($this->resident)
            ->post("/pay/{$bill->id}/online")
            ->assertRedirect();

        // مهم‌ترین بخش: قبض نباید تسویه شده باشد
        $this->assertSame(0.0, (float) $bill->fresh()->paid_amount);
        $this->assertSame(BillStatus::Unpaid, $bill->fresh()->status);

        // و تراکنشِ نیمه‌کاره باید شکست‌خورده ثبت شود، نه در انتظار
        $payment = Payment::withoutGlobalScopes()->latest('id')->first();
        $this->assertNotNull($payment);
        $this->assertSame('failed', $payment->status->value);
    }

    /* --------------------- تنظیمات مجتمع --------------------- */

    public function test_the_sandbox_option_is_not_offered_on_a_production_server(): void
    {
        $this->asProduction();
        $this->complex->update(['payment_gateway' => 'none']);

        $gateways = $this->actingAs($this->admin)->getJson('/api/settings')
            ->assertOk()
            ->json('options.gateways');

        $this->assertNotContains('fake', array_column($gateways, 'value'));
    }

    public function test_a_direct_request_cannot_switch_to_the_sandbox_in_production(): void
    {
        $this->asProduction();
        $this->complex->update(['payment_gateway' => 'none']);

        // فهرست گزینه‌ها فقط رابط کاربری است؛ سرور باید خودش رد کند
        $this->actingAs($this->admin)->putJson('/api/settings', $this->settingsPayload('fake'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_gateway');

        $this->assertSame('none', $this->complex->fresh()->payment_gateway);
    }

    public function test_a_complex_already_on_sandbox_can_still_save_other_settings(): void
    {
        $this->asProduction();

        // فرم نباید قفل شود؛ درگاه به‌هرحال در GatewayManager مسدود است
        $this->actingAs($this->admin)
            ->putJson('/api/settings', array_merge($this->settingsPayload('fake'), ['name' => 'نام تازه']))
            ->assertOk();

        $this->assertSame('نام تازه', $this->complex->fresh()->name);
    }

    public function test_switching_to_the_sandbox_is_allowed_outside_production(): void
    {
        config(['payment.sandbox_enabled' => true]);
        $this->complex->update(['payment_gateway' => 'none']);

        $this->actingAs($this->admin)->putJson('/api/settings', $this->settingsPayload('fake'))->assertOk();

        $this->assertSame('fake', $this->complex->fresh()->payment_gateway);
    }

    /* --------------------- درگاه اشتراک --------------------- */

    public function test_the_subscription_sandbox_is_disabled_in_production(): void
    {
        $this->asProduction();
        config(['subscription.gateway' => 'sandbox']);

        $this->assertFalse(app(SubscriptionGatewayManager::class)->isEnabled());

        $this->expectException(RuntimeException::class);
        app(SubscriptionGatewayManager::class)->driver();
    }

    public function test_the_subscription_sandbox_works_outside_production(): void
    {
        config(['payment.sandbox_enabled' => true, 'subscription.gateway' => 'sandbox']);

        $this->assertTrue(app(SubscriptionGatewayManager::class)->isEnabled());
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(string $gateway): array
    {
        return [
            'name' => $this->complex->name,
            'currency' => 'toman',
            'charge_due_day' => 10,
            'payment_gateway' => $gateway,
            'penalty_type' => 'fixed',
            'penalty_value' => 0,
            'penalty_grace_days' => 0,
        ];
    }
}
