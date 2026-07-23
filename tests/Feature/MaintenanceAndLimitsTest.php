<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\OccupancyStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * نگهداری خودکار (انقضا و پاکسازی) و محدودیت‌های تازه‌ی رسید و رمز عبور.
 */
class MaintenanceAndLimitsTest extends TestCase
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
            'name' => 'مجتمع نگهداری', 'slug' => 'mnt-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'fake',
        ]);

        $this->admin = User::create([
            'complex_id' => $this->complex->id, 'name' => 'مدیر', 'phone' => '09127770001',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->resident = User::create([
            'complex_id' => $this->complex->id, 'name' => 'ساکن', 'phone' => '09127770002',
            'role' => UserRole::Owner, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_number' => '1', 'floor' => 1,
            'area' => 100, 'residents_count' => 2, 'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied, 'uses_elevator' => true,
        ]);

        $this->resident->units()->attach($this->unit->id, [
            'complex_id' => $this->complex->id, 'relation' => 'owner',
            'share_percent' => 100, 'is_current' => true,
        ]);
    }

    private function makeBill(float $total = 500000, float $paid = 0): Bill
    {
        return Bill::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_id' => $this->unit->id,
            'period' => '1405-04', 'total_amount' => $total, 'paid_amount' => $paid,
            'status' => BillStatus::Unpaid, 'issued_at' => now(),
        ]);
    }

    /* ------------------- نگهداری خودکار اشتراک ------------------- */

    public function test_maintenance_expires_finished_subscriptions(): void
    {
        $subscription = Subscription::create([
            'complex_id' => $this->complex->id, 'user_id' => $this->admin->id,
            'plan' => SubscriptionPlan::Pro, 'status' => 'active', 'method' => 'receipt',
            'amount' => 100, 'months' => 1,
            'starts_at' => now()->subMonths(2), 'ends_at' => now()->subDay(),
        ]);

        $this->artisan('subscriptions:maintain')->assertExitCode(0);

        $this->assertSame('expired', $subscription->fresh()->status);
    }

    public function test_maintenance_closes_abandoned_online_checkouts(): void
    {
        $abandoned = Subscription::create([
            'complex_id' => $this->complex->id, 'user_id' => $this->admin->id,
            'plan' => SubscriptionPlan::Pro, 'status' => 'pending', 'method' => 'online',
            'amount' => 100, 'months' => 1,
        ]);
        // Eloquent هنگام ساخت، created_at را روی «الان» می‌گذارد؛ برای شبیه‌سازی
        // تراکنش رهاشده باید بعد از ساخت عقب برده شود.
        $abandoned->forceFill(['created_at' => now()->subHours(5)])->saveQuietly();

        // درخواست رسید منتظر بررسی انسانی است و نباید بسته شود
        $receipt = Subscription::create([
            'complex_id' => $this->complex->id, 'user_id' => $this->admin->id,
            'plan' => SubscriptionPlan::Pro, 'status' => 'pending', 'method' => 'receipt',
            'amount' => 100, 'months' => 1,
        ]);
        $receipt->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        $this->artisan('subscriptions:maintain')->assertExitCode(0);

        $this->assertSame('failed', $abandoned->fresh()->status);
        $this->assertSame('pending', $receipt->fresh()->status);
    }

    /* --------------------- پاکسازی فایل رسید --------------------- */

    public function test_deleting_a_payment_removes_its_receipt_file(): void
    {
        Storage::fake('local');
        $path = UploadedFile::fake()->image('r.jpg')->store('receipts/'.$this->complex->id, 'local');

        $payment = Payment::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_id' => $this->unit->id,
            'user_id' => $this->resident->id, 'amount' => 1000,
            'method' => PaymentMethod::Receipt, 'status' => PaymentStatus::Pending,
            'receipt_path' => $path,
        ]);

        Storage::disk('local')->assertExists($path);
        $payment->delete();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_prune_command_removes_orphaned_receipt_files(): void
    {
        Storage::fake('local');

        // فایلی که هیچ رکوردی به آن اشاره نمی‌کند (مثل بازمانده‌ی حذف آبشاری)
        $orphan = UploadedFile::fake()->image('orphan.jpg')->store('receipts/99', 'local');

        // و فایلی که رکورد زنده دارد
        $kept = UploadedFile::fake()->image('kept.jpg')->store('receipts/'.$this->complex->id, 'local');
        Payment::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_id' => $this->unit->id,
            'user_id' => $this->resident->id, 'amount' => 1000,
            'method' => PaymentMethod::Receipt, 'status' => PaymentStatus::Pending,
            'receipt_path' => $kept,
        ]);

        $this->artisan('receipts:prune')->assertExitCode(0);

        Storage::disk('local')->assertMissing($orphan);
        Storage::disk('local')->assertExists($kept);
    }

    public function test_prune_dry_run_deletes_nothing(): void
    {
        Storage::fake('local');
        $orphan = UploadedFile::fake()->image('orphan.jpg')->store('receipts/99', 'local');

        $this->artisan('receipts:prune', ['--dry-run' => true])->assertExitCode(0);

        Storage::disk('local')->assertExists($orphan);
    }

    /* --------------------- محدودیت‌های رسید --------------------- */

    public function test_receipt_amount_cannot_exceed_the_bill_remainder(): void
    {
        Storage::fake('local');
        $bill = $this->makeBill(total: 500000);

        $this->actingAs($this->resident)->postJson("/api/pay/{$bill->id}/receipt", [
            'amount' => 99000000,
            'receipt' => UploadedFile::fake()->image('r.jpg'),
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        // مبلغ منطقی پذیرفته می‌شود
        $this->actingAs($this->resident)->postJson("/api/pay/{$bill->id}/receipt", [
            'amount' => 500000,
            'receipt' => UploadedFile::fake()->image('r.jpg'),
        ])->assertStatus(201);
    }

    public function test_only_one_pending_receipt_per_bill(): void
    {
        Storage::fake('local');
        $bill = $this->makeBill();

        $this->actingAs($this->resident)->postJson("/api/pay/{$bill->id}/receipt", [
            'amount' => 100000, 'receipt' => UploadedFile::fake()->image('a.jpg'),
        ])->assertStatus(201);

        $this->actingAs($this->resident)->postJson("/api/pay/{$bill->id}/receipt", [
            'amount' => 100000, 'receipt' => UploadedFile::fake()->image('b.jpg'),
        ])->assertStatus(422);

        $this->assertSame(1, Payment::withoutGlobalScopes()->count());
    }

    public function test_receipt_rejects_a_future_payment_date(): void
    {
        Storage::fake('local');
        $bill = $this->makeBill();

        $this->actingAs($this->resident)->postJson("/api/pay/{$bill->id}/receipt", [
            'amount' => 100000,
            'paid_on' => now()->addWeek()->toDateString(),
            'receipt' => UploadedFile::fake()->image('r.jpg'),
        ])->assertStatus(422)->assertJsonValidationErrors('paid_on');
    }

    /* --------------------- رمز عبور و پیام‌رسان --------------------- */

    public function test_creating_a_resident_requires_a_strong_password(): void
    {
        $payload = [
            'name' => 'ساکن تازه', 'phone' => '09127779999', 'role' => 'owner',
        ];

        // فقط حرف، بدون رقم
        $this->actingAs($this->admin)->postJson('/api/residents', $payload + ['password' => 'onlyletters'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        // کوتاه
        $this->actingAs($this->admin)->postJson('/api/residents', $payload + ['password' => 'a1b2'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->actingAs($this->admin)->postJson('/api/residents', $payload + ['password' => 'strong123'])
            ->assertStatus(201);
    }

    public function test_manager_creation_requires_a_strong_password(): void
    {
        $this->actingAs($this->admin)->postJson('/api/managers', [
            'name' => 'مدیر تازه', 'phone' => '09127778888', 'password' => '123456',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_admin_can_close_and_reopen_messaging_for_a_resident(): void
    {
        // مقدار پیش‌فرض ستون در دیتابیس است، پس مدلِ در حافظه باید تازه شود
        $this->assertTrue((bool) $this->resident->fresh()->can_message);

        $this->actingAs($this->admin)
            ->patchJson("/api/residents/{$this->resident->id}/toggle-messaging")
            ->assertOk()
            ->assertJsonPath('resident.canMessage', false);

        // و سرور واقعاً جلوی ارسال پیام را می‌گیرد
        $this->actingAs($this->resident)->postJson('/api/messenger', ['body' => 'سلام'])
            ->assertStatus(403);

        $this->actingAs($this->admin)
            ->patchJson("/api/residents/{$this->resident->id}/toggle-messaging")
            ->assertOk()
            ->assertJsonPath('resident.canMessage', true);
    }

    public function test_a_resident_cannot_change_messaging_permission(): void
    {
        $this->actingAs($this->resident)
            ->patchJson("/api/residents/{$this->resident->id}/toggle-messaging")
            ->assertStatus(403);
    }
}
