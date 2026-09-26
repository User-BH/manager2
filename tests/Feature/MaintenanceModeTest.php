<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\OccupancyStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureNotInMaintenance;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * حالتِ تعمیر از پنلِ سوپرادمین (مرحلهٔ ۵ · فاز ۹ · بندِ ۷۹).
 *
 * 🔴 پیش از این فاز وجود نداشت. «Maintenance» در نامِ یک تستِ قدیمی به
 * نگهداریِ اشتراک‌ها اشاره داشت، نه به بستنِ سایت؛ تنها راه `artisan down`ِ
 * اسکریپتِ استقرار بود که از پنل روشن نمی‌شود.
 */
class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;

    private User $manager;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        // موضوعِ این تست خودِ عملیات است، نه نگهبانِ تأییدِ دوباره (فاز ۱۰)
        $this->withConfirmedPassword();

        $complex = Complex::factory()->create();

        $this->resident = User::factory()->create([
            'complex_id' => $complex->id, 'role' => UserRole::Owner, 'is_active' => true,
        ]);
        $this->manager = User::factory()->create([
            'complex_id' => $complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true,
        ]);
        $this->superAdmin = User::factory()->create([
            'complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true,
            'password' => Hash::make('secret123'),
        ]);
    }

    /** روشن‌کردن از همان مسیرِ پنل، نه با دست‌بردن در دیتابیس. */
    private function turnOn(): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/system/features/maintenance_mode', ['enabled' => true])
            ->assertOk();
    }

    public function test_it_is_off_by_default(): void
    {
        $this->actingAs($this->resident)->get('/dashboard')->assertOk();
        $this->actingAs($this->resident)->getJson('/api/v1/me')->assertOk();
    }

    public function test_a_regular_user_sees_the_persian_page_and_a_clear_api_answer(): void
    {
        $this->turnOn();

        $this->actingAs($this->resident)->get('/dashboard')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '600')
            ->assertSee('<html lang="fa" dir="rtl">', false);

        $this->actingAs($this->manager)->getJson('/api/v1/me')
            ->assertStatus(503)
            ->assertJsonPath('code', 'maintenance')
            ->assertJsonPath('maintenance', true)
            ->assertJsonPath('message', EnsureNotInMaintenance::MESSAGE);
    }

    public function test_a_guest_reaching_a_private_page_sees_it_too(): void
    {
        $this->turnOn();
        auth()->logout();

        $this->get('/units')->assertStatus(503);
    }

    /** سوپرادمین در همه‌ی مدت کار می‌کند — و خودش خاموشش می‌کند. */
    public function test_the_super_admin_keeps_working_and_can_turn_it_off(): void
    {
        $this->turnOn();

        $this->actingAs($this->superAdmin)->get('/dashboard')->assertOk();
        $this->actingAs($this->superAdmin)->getJson('/api/v1/me')->assertOk();

        $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/system/features/maintenance_mode', ['enabled' => false])
            ->assertOk();

        $this->actingAs($this->resident)->getJson('/api/v1/me')->assertOk();
    }

    public function test_only_the_super_admin_can_turn_it_on(): void
    {
        $this->actingAs($this->manager)
            ->putJson('/api/v1/system/features/maintenance_mode', ['enabled' => true])
            ->assertForbidden();

        $this->actingAs($this->resident)->getJson('/api/v1/me')->assertOk();
    }

    /**
     * 🔴 بازگشت از بانک هرگز بسته نمی‌شود: پول همان لحظه کسر شده. ورود هم
     * باز است، وگرنه سوپرادمینی که نشستش تمام شده راهِ خاموش‌کردن نداشت.
     */
    public function test_the_lifelines_stay_open(): void
    {
        $this->turnOn();
        auth()->logout();

        foreach (['/', '/demo', '/support', '/auth', '/robots.txt', '/up', '/api/v1/features', '/api/v1/site-settings'] as $path) {
            $this->assertNotSame(503, $this->get($path)->status(), "{$path} نباید بسته باشد");
        }

        // ورود به منطقِ خودش می‌رسد (اعتبارسنجی)، نه به دیوارِ تعمیر
        $this->postJson('/api/v1/login', [])->assertStatus(422);
    }

    /**
     * 🔴 بازگشتِ بانک با پرداختِ **واقعی** به کنترلر می‌رسد و تسویه می‌شود.
     *
     * ⚠️ نسخه‌ی اولِ این تست پرداختِ ناموجود (`/pay/callback/999999`) را
     * می‌زد و ۴۰۴ می‌خواست؛ خرابکاری نشان داد پوچ است: اتصالِ مدلِ مسیر
     * پیش از میدل‌ورِ تعمیر ۴۰۴ می‌دهد، پس بستنِ بازگشت هم همان ۴۰۴ را
     * می‌داد و تست سبز می‌ماند.
     */
    public function test_a_real_bank_return_still_settles_during_maintenance(): void
    {
        $complex = $this->resident->complex;
        $complex->update(['payment_gateway' => 'fake']);

        $unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_number' => '9', 'floor' => 1, 'area' => 80,
            'residents_count' => 1, 'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied, 'uses_elevator' => false,
        ]);
        $bill = Bill::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_id' => $unit->id, 'period' => '1405-04',
            'total_amount' => 100000, 'paid_amount' => 0, 'status' => BillStatus::Unpaid, 'issued_at' => now(),
        ]);
        $payment = Payment::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_id' => $unit->id, 'bill_id' => $bill->id,
            'user_id' => $this->resident->id, 'amount' => 100000,
            'method' => PaymentMethod::Online, 'status' => PaymentStatus::Pending,
            'period' => '1405-04', 'gateway' => 'fake', 'ref_id' => 'FAKE-1',
        ]);

        $this->turnOn();
        auth()->logout();

        $response = $this->get('/pay/callback/'.$payment->id.'?ref=FAKE-1');

        $this->assertNotSame(503, $response->status());
        $this->assertSame(PaymentStatus::Success, $payment->fresh()->status);
    }

    public function test_the_super_admin_can_still_sign_in_during_maintenance(): void
    {
        $this->turnOn();
        auth()->logout();

        $response = $this->postJson('/api/v1/login', ['phone' => $this->superAdmin->phone, 'password' => 'secret123']);

        $this->assertNotSame(503, $response->status());
    }

    public function test_turning_it_on_is_audited(): void
    {
        $this->turnOn();

        $entry = AuditLog::where('action', 'feature.toggled')->sole();
        $this->assertSame($this->superAdmin->id, $entry->user_id);
        $this->assertSame('maintenance_mode', $entry->properties['flag']);
        $this->assertTrue($entry->properties['enabled']);
    }

    /** نامِ غلط در فهرستِ باز یعنی مسیری که بی‌صدا بسته می‌ماند. */
    public function test_every_open_route_name_really_exists(): void
    {
        foreach (EnsureNotInMaintenance::OPEN_ROUTES as $name) {
            $this->assertTrue(
                Route::has($name) || Route::has('api.'.$name),
                "مسیرِ «{$name}» وجود ندارد؛ فهرستِ بازِ حالتِ تعمیر کهنه است.",
            );
        }
    }
}
