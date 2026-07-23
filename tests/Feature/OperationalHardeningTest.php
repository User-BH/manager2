<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\OccupancyStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Services\Payment\GatewayManager;
use App\Services\Payment\MellatGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * موارد ۸ تا ۱۳ گزارش بررسی: ترتیب پیام‌رسان، نگه‌داری بکاپ، محدودیت نرخ،
 * لاگ فعالیت و اعمال پلن روی درگاه.
 */
class OperationalHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $admin;

    private User $resident;

    private User $superAdmin;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::create([
            'name' => 'مجتمع عملیاتی', 'slug' => 'ops-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'none', 'messenger_enabled' => true,
        ]);

        $this->admin = User::create([
            'complex_id' => $this->complex->id, 'name' => 'مدیر', 'phone' => '09221110001',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->resident = User::create([
            'complex_id' => $this->complex->id, 'name' => 'ساکن', 'phone' => '09221110002',
            'role' => UserRole::Owner, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->superAdmin = User::create([
            'name' => 'ادمین کل', 'phone' => '09221110003',
            'role' => UserRole::SuperAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
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

    /* ------------------ ۸) ترتیب پیام‌رسان ------------------ */

    public function test_the_messenger_returns_the_newest_messages_not_the_oldest(): void
    {
        for ($i = 1; $i <= 205; $i++) {
            Message::withoutGlobalScopes()->create([
                'complex_id' => $this->complex->id, 'user_id' => $this->resident->id,
                'body' => "پیام شماره {$i}", 'author_name' => 'ساکن',
                'author_role' => 'owner', 'unit_label' => '۱',
                'created_at' => now()->addSeconds($i),
            ]);
        }

        $response = $this->actingAs($this->resident)->getJson('/api/messenger')->assertOk();
        $messages = $response->json('messages');

        $this->assertCount(200, $messages);
        // تازه‌ترین پیام باید آخرِ فهرست باشد (ترتیب نمایش صعودی است)
        $this->assertSame('پیام شماره 205', end($messages)['body']);
        $this->assertSame('پیام شماره 6', $messages[0]['body']);
        $this->assertTrue($response->json('hasOlder'));
    }

    public function test_a_short_history_reports_nothing_older(): void
    {
        Message::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'user_id' => $this->resident->id,
            'body' => 'تنها پیام', 'author_name' => 'ساکن', 'author_role' => 'owner', 'unit_label' => '۱',
        ]);

        $this->actingAs($this->resident)->getJson('/api/messenger')
            ->assertOk()
            ->assertJsonPath('hasOlder', false);
    }

    /* ------------------ ۹) نگه‌داری بکاپ ------------------ */

    public function test_pruning_keeps_only_the_newest_backups(): void
    {
        Storage::fake('local');

        foreach (range(1, 8) as $i) {
            $path = "backups/b{$i}.json";
            Storage::disk('local')->put($path, '{}');
            Backup::create([
                'complex_id' => $this->complex->id, 'type' => 'complex', 'status' => 'completed',
                'disk' => 'local', 'path' => $path, 'size' => 100, 'note' => "بکاپ {$i}",
            ]);
        }

        $this->artisan('backups:prune', ['--keep' => 3])->assertExitCode(0);

        $this->assertSame(3, Backup::count());
        Storage::disk('local')->assertExists('backups/b8.json');
        Storage::disk('local')->assertMissing('backups/b1.json');
    }

    public function test_pruning_counts_each_complex_separately(): void
    {
        Storage::fake('local');

        $other = Complex::create([
            'name' => 'مجتمع دوم', 'slug' => 'ops2-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'none',
        ]);

        /*
         * بدون گروه‌بندی، یک مجتمعِ پرکار سهمیه‌ی بقیه را مصرف می‌کرد و بکاپ
         * آن‌ها حذف می‌شد.
         */
        foreach (range(1, 5) as $i) {
            Backup::create([
                'complex_id' => $this->complex->id, 'type' => 'complex', 'status' => 'completed',
                'disk' => 'local', 'path' => "backups/a{$i}.json", 'size' => 10,
            ]);
        }

        Backup::create([
            'complex_id' => $other->id, 'type' => 'complex', 'status' => 'completed',
            'disk' => 'local', 'path' => 'backups/other.json', 'size' => 10,
        ]);

        $this->artisan('backups:prune', ['--keep' => 2])->assertExitCode(0);

        $this->assertSame(2, Backup::where('complex_id', $this->complex->id)->count());
        $this->assertSame(1, Backup::where('complex_id', $other->id)->count());
    }

    public function test_prune_dry_run_deletes_nothing(): void
    {
        Storage::fake('local');

        foreach (range(1, 5) as $i) {
            Backup::create([
                'complex_id' => null, 'type' => 'full', 'status' => 'completed',
                'disk' => 'local', 'path' => "backups/s{$i}.json", 'size' => 10,
            ]);
        }

        $this->artisan('backups:prune', ['--keep' => 1, '--dry-run' => true])->assertExitCode(0);

        $this->assertSame(5, Backup::count());
    }

    /* ------------------ ۱۰) محدودیت نرخ ------------------ */

    public function test_search_is_rate_limited(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->actingAs($this->admin)->getJson('/api/search?q=خانه')->assertOk();
        }

        $this->actingAs($this->admin)->getJson('/api/search?q=خانه')->assertStatus(429);
    }

    public function test_complex_backups_are_rate_limited(): void
    {
        Storage::fake('local');

        for ($i = 0; $i < 12; $i++) {
            $this->actingAs($this->admin)->postJson('/api/backups')->assertStatus(201);
        }

        $this->actingAs($this->admin)->postJson('/api/backups')->assertStatus(429);
    }

    /* ------------------ ۱۲) لاگ فعالیت ------------------ */

    public function test_deleting_a_resident_is_recorded(): void
    {
        $this->actingAs($this->admin)->deleteJson("/api/residents/{$this->resident->id}")->assertOk();

        $log = AuditLog::where('action', 'resident.deleted')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('ساکن', $log->properties['name']);
        $this->assertSame($this->admin->id, $log->user_id);
    }

    public function test_deactivating_a_resident_is_recorded(): void
    {
        $this->actingAs($this->admin)
            ->patchJson("/api/residents/{$this->resident->id}/toggle-active")->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'resident.deactivated']);
    }

    public function test_deleting_a_unit_is_recorded(): void
    {
        $this->actingAs($this->admin)->deleteJson("/api/units/{$this->unit->id}")->assertOk();

        $log = AuditLog::where('action', 'unit.deleted')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('1', $log->properties['unit_number']);
    }

    public function test_changing_the_payment_gateway_is_recorded(): void
    {
        $this->actingAs($this->admin)->putJson('/api/settings', [
            'name' => $this->complex->name, 'currency' => 'toman', 'charge_due_day' => 10,
            'payment_gateway' => 'fake', 'penalty_type' => 'fixed',
            'penalty_value' => 0, 'penalty_grace_days' => 0,
        ])->assertOk();

        $log = AuditLog::where('action', 'settings.gateway_changed')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('none', $log->properties['from']);
        $this->assertSame('fake', $log->properties['to']);
    }

    public function test_saving_settings_without_changing_the_gateway_logs_nothing(): void
    {
        // وگرنه لاگ با ذخیره‌های بی‌اثر پر می‌شود و خواندنش سخت می‌گردد
        $this->actingAs($this->admin)->putJson('/api/settings', [
            'name' => 'نام تازه', 'currency' => 'toman', 'charge_due_day' => 10,
            'payment_gateway' => 'none', 'penalty_type' => 'fixed',
            'penalty_value' => 0, 'penalty_grace_days' => 0,
        ])->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'settings.gateway_changed']);
    }

    public function test_a_super_admin_can_read_the_audit_log(): void
    {
        $this->actingAs($this->admin)->deleteJson("/api/residents/{$this->resident->id}")->assertOk();

        $this->actingAs($this->superAdmin)->getJson('/api/system/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'resident.deleted')
            ->assertJsonPath('data.0.actionLabel', 'حذف ساکن');
    }

    public function test_a_complex_admin_cannot_read_the_audit_log(): void
    {
        $this->actingAs($this->admin)->getJson('/api/system/audit-logs')->assertStatus(403);
    }

    public function test_the_audit_log_has_no_write_route(): void
    {
        // ثبت‌شده نباید از رابط کاربری قابل حذف باشد
        $this->actingAs($this->superAdmin)->deleteJson('/api/system/audit-logs/1')->assertStatus(404);
        $this->actingAs($this->superAdmin)->postJson('/api/system/audit-logs')->assertStatus(405);
    }

    /* ------------------ ۱۳) درگاه پس از انقضای پرو ------------------ */

    private function useRealGateway(): void
    {
        $this->complex->update([
            'payment_gateway' => 'mellat',
            'gateway_config' => ['terminal_id' => '123', 'username' => 'u', 'password' => 'p'],
        ]);
    }

    private function giveProSubscription(bool $expired): void
    {
        Subscription::create([
            'complex_id' => $this->complex->id, 'user_id' => $this->admin->id,
            'plan' => SubscriptionPlan::Pro, 'status' => 'active', 'method' => 'receipt',
            'amount' => 100, 'months' => 1,
            'starts_at' => now()->subMonths(2),
            'ends_at' => $expired ? now()->subDay() : now()->addMonth(),
        ]);
    }

    public function test_the_real_gateway_stops_working_when_pro_expires(): void
    {
        $this->useRealGateway();
        $this->giveProSubscription(expired: true);

        $this->assertFalse(app(GatewayManager::class)->isOnlineEnabled($this->complex->fresh()));

        $this->expectException(RuntimeException::class);
        app(GatewayManager::class)->for($this->complex->fresh());
    }

    public function test_the_real_gateway_works_while_pro_is_active(): void
    {
        $this->useRealGateway();
        $this->giveProSubscription(expired: false);

        $this->assertTrue(app(GatewayManager::class)->isOnlineEnabled($this->complex->fresh()));
        $this->assertInstanceOf(
            MellatGateway::class,
            app(GatewayManager::class)->for($this->complex->fresh()),
        );
    }

    public function test_residents_see_receipt_only_when_the_subscription_lapsed(): void
    {
        $this->useRealGateway();
        $this->giveProSubscription(expired: true);

        $bill = Bill::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_id' => $this->unit->id, 'period' => '1405-04',
            'total_amount' => 500000, 'paid_amount' => 0,
            'status' => BillStatus::Unpaid, 'issued_at' => now(),
        ]);

        $this->actingAs($this->resident)->getJson("/api/pay/{$bill->id}")
            ->assertOk()
            ->assertJsonPath('onlineEnabled', false);
    }

    public function test_the_settings_page_explains_why_the_gateway_is_off(): void
    {
        $this->useRealGateway();
        $this->giveProSubscription(expired: true);

        $this->actingAs($this->admin)->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('gatewayBlockedByPlan', true);
    }
}
