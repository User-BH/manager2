<?php

namespace Tests\Feature;

use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\Message;
use App\Models\Payment;
use App\Models\TrustedDevice;
use App\Models\Unit;
use App\Models\UnitTenure;
use App\Models\User;
use App\Services\Account\Membership;
use App\Services\Messenger\ConversationDirectory;
use App\Services\Units\TenureService;
use App\Support\Jalali;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * پایانِ عضویت به‌جای حذف (F4)، غیرفعال‌سازیِ ادمینِ کل، و مرزِ ساکنِ تازه —
 * مرحلهٔ ۹ · فاز ۶.
 *
 * تصمیمِ کارفرما: «حذفِ ساکن» حساب، پروفایل، سابقه‌ی واحد و سابقه‌ی مالی را
 * نگه می‌دارد؛ ساکنِ تازه به سابقه و داده‌ی خصوصیِ ساکنِ قبلی دسترسی ندارد؛
 * «حذفِ عضو» در پنلِ ادمینِ کل در پشتِ صحنه غیرفعال‌سازی است.
 */
class MembershipLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private Unit $unit;

    /** ساکنِ قبلیِ واحد، با یک سال سابقه */
    private User $alice;

    private Bill $oldBill;

    private Payment $oldPayment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['name' => 'مجتمع سابقه', 'messenger_enabled' => true]);
        $this->manager = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true,
            'password' => Hash::make('secret123'),
        ]);
        $this->unit = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '12']);

        $this->alice = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::Tenant, 'is_active' => true,
            'phone' => '09126660001', 'password' => Hash::make('secret123'), 'can_message' => true,
        ]);
        app(TenureService::class)->open($this->unit, $this->alice, ResidentRelation::Tenant, 100, now()->subYear());

        $period = Jalali::shiftPeriod(Jalali::currentPeriod(), -3);
        $this->oldBill = Bill::factory()->create([
            'complex_id' => $this->complex->id, 'unit_id' => $this->unit->id, 'period' => $period,
        ]);
        $this->oldPayment = Payment::factory()->successful()->create([
            'complex_id' => $this->complex->id, 'unit_id' => $this->unit->id,
            'bill_id' => $this->oldBill->id, 'user_id' => $this->alice->id,
        ]);
    }

    /* ── F4: پایانِ عضویتِ ساکن ───────────────────────────────────────── */

    public function test_removing_a_resident_keeps_the_account_and_every_record(): void
    {
        $this->as($this->manager)->deleteJson("/api/v1/residents/{$this->alice->id}")->assertOk();

        $alice = User::withoutGlobalScopes()->find($this->alice->id);
        $this->assertNotNull($alice, 'حسابِ ساکن حذف شد.');
        $this->assertNull($alice->complex_id);
        $this->assertSame(UserRole::Owner, $alice->role);
        $this->assertTrue((bool) $alice->is_active);
        $this->assertTrue(Hash::check('secret123', $alice->password));

        $tenure = UnitTenure::withoutGlobalScopes()->where('user_id', $alice->id)->sole();
        $this->assertFalse((bool) $tenure->is_current);
        $this->assertNotNull($tenure->end_date);

        $this->assertSame($alice->id, Payment::withoutGlobalScopes()->find($this->oldPayment->id)->user_id);
        $this->assertNotNull(Bill::withoutGlobalScopes()->find($this->oldBill->id));

        $log = AuditLog::where('action', 'resident.removed')->sole();
        $this->assertSame($this->manager->id, $log->user_id);
    }

    public function test_a_removed_resident_loses_access_to_the_complex(): void
    {
        $this->as($this->manager)->deleteJson("/api/v1/residents/{$this->alice->id}")->assertOk();

        $alice = User::withoutGlobalScopes()->find($this->alice->id);

        // حسابِ اولیه است: قبض و پیامِ مجتمع را نمی‌بیند و نمی‌نویسد
        $bills = $this->as($alice)->getJson('/api/v1/my-bills')->assertOk()->json('data');
        $this->assertSame([], $bills);
        $this->as($alice)->getJson("/api/v1/my-bills/{$this->oldBill->id}")->assertNotFound();
        $this->as($alice)->postJson('/api/v1/messenger', ['body' => 'هنوز اینجام؟'])->assertForbidden();
        $this->as($alice)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.accountState', 'initial');
    }

    /** بازگشتِ همان ساکن به همان مجتمع: دوره‌ی تازه، سابقه‌ی قبلی سرِ جایش (یکتاییِ کهنه ۵۰۰ می‌داد). */
    public function test_a_former_resident_can_be_invited_back(): void
    {
        $this->as($this->manager)->deleteJson("/api/v1/residents/{$this->alice->id}")->assertOk();
        $alice = User::withoutGlobalScopes()->find($this->alice->id);

        foreach ([1, 2] as $round) {
            [$invitation] = app(Membership::class)->invite($this->complex, $alice->phone, UserRole::Tenant, $this->unit, $this->manager);
            app(Membership::class)->accept($invitation, $alice->fresh());
            $this->assertSame($this->complex->id, $alice->fresh()->complex_id, "دورِ {$round}");
            app(Membership::class)->end($alice->fresh());
        }

        $this->assertSame(3, UnitTenure::withoutGlobalScopes()->where('user_id', $alice->id)->count());
        $this->assertSame(2, ComplexInvitation::where('phone', $alice->phone)->where('status', ComplexInvitation::ACCEPTED)->count());
    }

    public function test_a_resident_of_another_complex_cannot_be_removed(): void
    {
        $other = Complex::factory()->create();
        $stranger = User::factory()->create(['complex_id' => $other->id, 'role' => UserRole::Owner, 'is_active' => true]);

        $response = $this->as($this->manager)->deleteJson("/api/v1/residents/{$stranger->id}");
        $this->assertContains($response->status(), [403, 404]);

        $this->assertSame($other->id, $stranger->fresh()->complex_id);
    }

    /**
     * 🔴 مدیر شماره و رمزِ ساکن را عوض نمی‌کند (تصمیمِ کارفرما، مرحلهٔ ۹ · فاز ۶).
     *
     * پیش از این همین دو فیلد یعنی تصاحبِ کاملِ حساب: شماره‌ی تازه کدِ OTP ِ
     * ورود را می‌گرفت و رمز را مدیر می‌دانست — و با F4 آن حساب پس از رفتنِ
     * ساکن هم می‌ماند.
     */
    public function test_a_manager_cannot_take_over_a_residents_account(): void
    {
        foreach ([['phone' => '09126669999'], ['password' => 'takeover123']] as $field) {
            $this->as($this->manager)->putJson("/api/v1/residents/{$this->alice->id}", [
                'role' => 'tenant', 'unit_id' => $this->unit->id, ...$field,
            ])->assertStatus(422)->assertJsonValidationErrors(array_keys($field));
        }

        $alice = $this->alice->fresh();
        $this->assertSame('09126660001', $alice->phone);
        $this->assertTrue(Hash::check('secret123', $alice->password));
    }

    /* ── F4: مدیرِ دوم ────────────────────────────────────────────────── */

    public function test_removing_a_manager_ends_the_role_not_the_account(): void
    {
        $second = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true,
        ]);

        $this->as($this->manager)->withConfirmedPassword()->deleteJson("/api/v1/managers/{$second->id}")->assertOk();

        $fresh = User::withoutGlobalScopes()->find($second->id);
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->complex_id);
        $this->assertSame(UserRole::Owner, $fresh->role);
        $this->assertDatabaseHas('audit_logs', ['action' => 'manager.removed']);

        // قاعده‌های پیشین سرِ جایشان: آخرین مدیر و خودِ مدیر
        $this->as($this->manager)->withConfirmedPassword()->deleteJson("/api/v1/managers/{$this->manager->id}")->assertStatus(422);
        $this->assertSame($this->complex->id, $this->manager->fresh()->complex_id);
    }

    /* ── مرزِ ساکنِ تازه ──────────────────────────────────────────────── */

    public function test_the_next_tenant_does_not_see_the_previous_tenants_bills(): void
    {
        $bob = $this->nextTenant();
        $own = Bill::factory()->create([
            'complex_id' => $this->complex->id, 'unit_id' => $this->unit->id, 'period' => Jalali::currentPeriod(),
        ]);

        $ids = collect($this->as($bob)->getJson('/api/v1/my-bills')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$own->id], $ids);

        // با شناسه هم نه: قبض و پرداخت‌های ساکنِ قبلی
        $this->as($bob)->getJson("/api/v1/my-bills/{$this->oldBill->id}")->assertForbidden();

        $units = $this->as($bob)->getJson('/api/v1/dashboard')->assertOk()->json('units')
            ?? $this->as($bob)->getJson('/api/v1/dashboard')->json('data.units');
        $this->assertNotNull($units);
        $this->assertSame($own->id, $units[0]['latestBill']['id']);
    }

    public function test_the_next_tenant_does_not_read_the_previous_tenants_thread(): void
    {
        $thread = app(ConversationDirectory::class)->unitThread($this->complex, $this->unit);
        $this->travel(-60)->days();
        $old = Message::create([
            'complex_id' => $this->complex->id, 'conversation_id' => $thread->id, 'user_id' => $this->alice->id,
            'body' => 'رازِ ساکنِ قبلی', 'client_id' => (string) Str::uuid(),
        ]);
        $thread->forceFill(['last_message_id' => $old->id, 'last_message_at' => now()])->save();
        $this->travelBack();

        $bob = $this->nextTenant();

        /*
         * ⚠️ متنِ خامِ پاسخ JSON با فرارِ یونیکد است؛ جست‌وجوی رشته‌ی فارسی در آن
         * همیشه «نیست» می‌گفت و تست را پوچ می‌کرد (همین تست اول چنین بود).
         * شناسه‌ها و JSON ِ رمزگشایی‌شده سنجیده می‌شوند.
         */
        $messages = $this->as($bob)->getJson("/api/v1/messenger/conversations/{$thread->id}/messages")->assertOk();
        $this->assertFalse($messages->json('isAdmin'));
        $this->assertNotContains($old->id, collect($messages->json('messages'))->pluck('id')->all());
        $this->assertStringNotContainsString('رازِ ساکنِ قبلی', $this->decoded($messages));

        $list = $this->as($bob)->getJson('/api/v1/messenger/conversations')->assertOk();
        $this->assertStringNotContainsString('رازِ ساکنِ قبلی', $this->decoded($list));
        $row = collect($list->json('data'))->firstWhere('id', $thread->id);
        if ($row !== null) {
            $this->assertSame(0, $row['unreadCount']);
            $this->assertNull($row['lastMessage']);
        }

        $this->as($bob)->getJson("/api/v1/messenger/conversations/{$thread->id}/search?q=".urlencode('رازِ'))
            ->assertOk()->assertJsonCount(0, 'results');

        // واکنش روی شناسه‌ی پیامِ قدیمی متنش را برنمی‌گرداند
        $this->as($bob)->postJson("/api/v1/messenger/conversations/{$thread->id}/messages/{$old->id}/reactions", ['emoji' => '👍'])
            ->assertNotFound();

        // مدیر همه‌ی سابقه را می‌بیند
        $admin = $this->as($this->manager)->getJson("/api/v1/messenger/conversations/{$thread->id}/messages")->assertOk();
        $this->assertContains($old->id, collect($admin->json('messages'))->pluck('id')->all());
        $this->assertStringContainsString('رازِ ساکنِ قبلی', $this->decoded($admin));
    }

    /* ── ادمینِ کل: «حذف» = غیرفعال‌سازی ─────────────────────────────── */

    public function test_the_super_admin_delete_deactivates_and_keeps_everything(): void
    {
        $superAdmin = $this->superAdmin();
        TrustedDevice::create([
            'user_id' => $this->alice->id, 'token_hash' => hash('sha256', Str::random(48)), 'expires_at' => now()->addDays(10),
        ]);

        $this->as($superAdmin)->withConfirmedPassword()->deleteJson("/api/v1/system/members/{$this->alice->id}")->assertOk();

        $alice = User::withoutGlobalScopes()->find($this->alice->id);
        $this->assertNotNull($alice, 'حذفِ سخت.');
        $this->assertFalse((bool) $alice->is_active);
        // نه مجتمع، نه نقش، نه دوره، نه پرداخت عوض نشد
        $this->assertSame($this->complex->id, $alice->complex_id);
        $this->assertSame(UserRole::Tenant, $alice->role);
        $this->assertTrue((bool) UnitTenure::withoutGlobalScopes()->where('user_id', $alice->id)->sole()->is_current);
        $this->assertSame($alice->id, Payment::withoutGlobalScopes()->find($this->oldPayment->id)->user_id);
        $this->assertSame(0, TrustedDevice::where('user_id', $alice->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.deactivated']);
    }

    public function test_a_deactivated_member_is_locked_out_until_reactivated(): void
    {
        $superAdmin = $this->superAdmin();
        $this->as($superAdmin)->withConfirmedPassword()->deleteJson("/api/v1/system/members/{$this->alice->id}")->assertOk();

        // نشستِ باز در درخواستِ بعدی بسته می‌شود
        $this->as($this->alice->fresh())->getJson('/api/v1/my-bills')
            ->assertForbidden()->assertJsonPath('accountDisabled', true);

        $this->flushSession();
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/login', ['phone' => $this->alice->phone, 'password' => 'secret123'])
            ->assertStatus(422);

        // برگشت‌پذیر: همان ویرایشِ عضو
        $this->as($superAdmin)->withConfirmedPassword()->patchJson("/api/v1/system/members/{$this->alice->id}", [
            'role' => UserRole::Tenant->value, 'is_active' => true,
        ])->assertOk();
        $this->assertTrue((bool) $this->alice->fresh()->is_active);
        $this->assertSame($this->complex->id, $this->alice->fresh()->complex_id);
    }

    public function test_only_the_super_admin_can_deactivate_and_never_themselves(): void
    {
        $superAdmin = $this->superAdmin();

        $this->as($superAdmin)->withConfirmedPassword()->deleteJson("/api/v1/system/members/{$superAdmin->id}")->assertStatus(422);
        $this->assertTrue((bool) $superAdmin->fresh()->is_active);

        $this->as($this->manager)->deleteJson("/api/v1/system/members/{$this->alice->id}")->assertForbidden();
        $this->assertTrue((bool) $this->alice->fresh()->is_active);
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    /** آلیس می‌رود؛ باب با دعوتِ امن همان واحد را می‌گیرد. */
    private function nextTenant(): User
    {
        $this->as($this->manager)->deleteJson("/api/v1/residents/{$this->alice->id}")->assertOk();

        $bob = User::factory()->create([
            'complex_id' => null, 'role' => UserRole::Owner, 'is_active' => true,
            'phone' => '09126660002', 'can_message' => true,
        ]);
        [$invitation] = app(Membership::class)->invite($this->complex, $bob->phone, UserRole::Tenant, $this->unit, $this->manager);
        app(Membership::class)->accept($invitation, $bob);

        return $bob->fresh();
    }

    private function decoded(TestResponse $response): string
    {
        return (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true]);
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        // نشستِ درخواستِ قبلی (مثلاً مدیر) نباید به کاربرِ بعدی برسد
        $this->flushSession();

        return $this->actingAs($user);
    }
}
