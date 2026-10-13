<?php

namespace Tests\Feature;

use App\Enums\AccountState;
use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\ComplexMembership;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\MembershipLedger;
use App\Services\Account\PublicJoinLink;
use App\Services\Units\TenureService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * تعلیقِ عضویت در یک مجتمع — مرحلهٔ ۱۰ · فاز ۶ (بندهای ۱۱، ۱۲، ۲۷ و ۷۴ ِ فایلِ معماری).
 *
 * ─── یافته ──────────────────────────────────────────────────────────────────
 * «غیرفعال کردن» در صفحه‌ی ساکنین `users.is_active` را می‌نوشت. مدیرِ مجتمعِ الف با یک کلیک ورودِ همان شخص به
 * مجتمعِ ب را هم می‌بست، جایی که عضوِ سالم است — درست خلافِ بندِ ۱۲ («A → access، B → deny» جدا از هم) و ۷۴
 * («حساب ممکن است در مجتمعِ دیگری فعال باشد»). حالا تعلیق روی عضویتِ همان مجتمع است؛ مسدودسازیِ حساب فقط کارِ
 * ادمینِ کل می‌ماند.
 */
class MembershipSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private Complex $a;

    private Complex $b;

    private User $managerA;

    private User $managerB;

    private Unit $unitA;

    private User $person;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Complex::factory()->create(['name' => 'مجتمع الف']);
        $this->b = Complex::factory()->create(['name' => 'مجتمع ب']);
        $this->managerA = User::factory()->create(['complex_id' => $this->a->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09126660001']);
        $this->managerB = User::factory()->create(['complex_id' => $this->b->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true]);
        $this->unitA = Unit::factory()->create(['complex_id' => $this->a->id, 'unit_number' => '7']);

        // مستأجرِ ب و مالکِ الف؛ زمینه‌ی فعال الف
        $this->person = User::factory()->create([
            'complex_id' => $this->b->id, 'role' => UserRole::Tenant, 'phone' => '09126660070', 'is_active' => true,
        ]);
        app(MembershipLedger::class)->start($this->person, $this->a, UserRole::Owner, ComplexMembership::SOURCE_DIRECT);
        app(TenureService::class)->open($this->unitA, $this->person->fresh(), ResidentRelation::Owner, 100, now()->subYear());
        $this->person->refresh();
    }

    public function test_suspending_in_one_complex_leaves_the_account_and_the_other_complex_untouched(): void
    {
        $this->toggle($this->managerA)->assertOk()->assertJsonPath('resident.isActive', false);

        $person = $this->person->fresh();
        // حساب باز است؛ زمینه به ب رفت
        $this->assertTrue($person->is_active);
        $this->assertSame([$this->b->id, UserRole::Tenant], [$person->complex_id, $person->role]);
        $this->assertSame(ComplexMembership::SUSPENDED, $this->membership($this->a)->status);
        $this->assertSame(ComplexMembership::ACTIVE, $this->membership($this->b)->status);

        // ب: دسترسی؛ الف: نه — حتی با تعویض
        $this->as($person)->getJson('/api/v1/my-bills')->assertOk();
        $this->as($person)->postJson('/api/v1/account/context', ['complex_id' => $this->a->id])
            ->assertUnprocessable()->assertJsonPath('code', 'membership.not_member');

        // مدیرِ ب او را فعال می‌بیند
        $this->assertTrue(collect($this->as($this->managerB)->getJson('/api/v1/residents')->json('data'))
            ->firstWhere('id', $this->person->id)['isActive']);
        $this->assertSame([], app(MembershipLedger::class)->violations());
    }

    public function test_an_open_session_loses_the_suspended_complex_on_its_very_next_request(): void
    {
        // فقط عضوِ الف (عضویتِ ب پایان یافت) و در همان زمینه وارد شده
        app(MembershipLedger::class)->end($this->person, ComplexMembership::END_REMOVED, null, $this->b->id);
        $person = $this->person->fresh();
        $this->assertSame($this->a->id, $person->complex_id);
        $this->as($person)->getJson('/api/v1/my-bills')->assertOk();

        $this->toggle($this->managerA)->assertOk();

        // همان نشست: دیگر هیچ دادهٔ الف — حساب به حالتِ اولیه رفت و می‌داند کجا معلق است
        $me = $this->actingAs($person->fresh())->getJson('/api/v1/me')->json('user');
        $this->assertSame(AccountState::Initial->value, $me['accountState']);
        $this->assertSame([[$this->a->id, 'suspended', false]], collect($me['memberships'])->map(fn ($m) => [$m['complexId'], $m['status'], $m['current']])->all());
        $this->as($person->fresh())->getJson('/api/v1/units')->assertForbidden();
    }

    public function test_suspension_keeps_the_account_unit_bills_and_history(): void
    {
        $bill = Bill::factory()->create(['complex_id' => $this->a->id, 'unit_id' => $this->unitA->id]);
        $started = $this->membership($this->a)->started_at;

        $this->toggle($this->managerA)->assertOk();

        $membership = $this->membership($this->a);
        $this->assertSame([$this->managerA->id, ComplexMembership::SOURCE_DIRECT], [$membership->suspended_by, $membership->source]);
        $this->assertNotNull($membership->suspended_at);
        $this->assertEquals($started, $membership->started_at);
        $this->assertTrue($this->person->units()->withoutGlobalScopes()->whereKey($this->unitA->id)->exists());
        $this->assertTrue(Bill::withoutGlobalScopes()->whereKey($bill->id)->exists());
        $this->assertDatabaseHas('audit_logs', ['action' => 'membership.suspended', 'subject_id' => $this->person->id]);
    }

    public function test_resuming_restores_the_same_membership(): void
    {
        $this->toggle($this->managerA)->assertOk();
        $id = $this->membership($this->a)->id;

        $this->toggle($this->managerA)->assertOk()->assertJsonPath('resident.isActive', true);

        $membership = $this->membership($this->a);
        $this->assertSame([$id, ComplexMembership::ACTIVE, null], [$membership->id, $membership->status, $membership->suspended_at]);
        // در ب کار می‌کرد و همان‌جا می‌ماند؛ خودش به الف برمی‌گردد
        $this->assertSame($this->b->id, $this->person->fresh()->complex_id);
        $this->as($this->person->fresh())->postJson('/api/v1/account/context', ['complex_id' => $this->a->id])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'membership.resumed', 'subject_id' => $this->person->id]);
    }

    public function test_an_account_with_no_other_membership_returns_to_the_complex_on_resume(): void
    {
        app(MembershipLedger::class)->end($this->person, ComplexMembership::END_REMOVED, null, $this->b->id);
        $this->toggle($this->managerA)->assertOk();
        $this->assertNull($this->person->fresh()->complex_id);

        $this->toggle($this->managerA)->assertOk();

        $this->assertSame([$this->a->id, UserRole::Owner], [$this->person->fresh()->complex_id, $this->person->fresh()->role]);
        $this->assertSame([], app(MembershipLedger::class)->violations());
    }

    public function test_a_suspended_member_cannot_slip_back_in_by_invitation_request_or_qr(): void
    {
        $this->toggle($this->managerA)->assertOk();

        // دعوتِ تازه
        $this->as($this->managerA)->postJson('/api/v1/residents', [
            'phone' => $this->person->phone, 'role' => 'tenant', 'unit_id' => $this->unitA->id,
        ])->assertUnprocessable();

        // درخواست با QR و با شماره‌ی مدیر
        $code = app(PublicJoinLink::class)->rotate($this->a);
        $this->as($this->person->fresh())->postJson('/api/v1/join-requests', ['code' => $code])
            ->assertUnprocessable()->assertJsonPath('code', 'account.already_member');
        $this->as($this->person->fresh())->postJson('/api/v1/join-requests', ['phone' => $this->managerA->phone])
            ->assertUnprocessable()->assertJsonPath('code', 'account.already_member');

        // دعوتی که پیش از تعلیق ساخته شده بود هم پذیرفته نمی‌شود
        $this->assertSame(0, ComplexInvitation::where('status', ComplexInvitation::PENDING)->count());
        $this->assertSame(ComplexMembership::SUSPENDED, $this->membership($this->a)->status);
    }

    public function test_the_manager_can_still_end_a_suspended_membership(): void
    {
        $this->toggle($this->managerA)->assertOk();

        $this->as($this->managerA)->deleteJson("/api/v1/residents/{$this->person->id}")->assertOk();

        $ended = ComplexMembership::withoutGlobalScopes()->where('user_id', $this->person->id)->where('complex_id', $this->a->id)->sole();
        $this->assertSame([ComplexMembership::ENDED, null], [$ended->status, $ended->active_key]);
        $this->assertNotNull($ended->suspended_at, 'زمانِ تعلیق به‌عنوانِ سابقه می‌ماند');
        $this->assertSame(ComplexMembership::ACTIVE, $this->membership($this->b)->status);
    }

    public function test_only_this_complexes_manager_can_suspend_here(): void
    {
        // مدیرِ ب عضویتِ «ب» را معلق می‌کند، نه «الف»
        $this->toggle($this->managerB)->assertOk();
        $this->assertSame(ComplexMembership::SUSPENDED, $this->membership($this->b)->status);
        $this->assertSame(ComplexMembership::ACTIVE, $this->membership($this->a)->status);

        // مدیرِ مجتمعِ سوم که او عضوش نیست
        $c = Complex::factory()->create();
        $managerC = User::factory()->create(['complex_id' => $c->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true]);
        $this->toggle($managerC)->assertForbidden();

        // ساکنِ همان مجتمع هم نه
        $neighbour = User::factory()->create(['complex_id' => $this->a->id, 'role' => UserRole::Owner, 'is_active' => true]);
        $this->toggle($neighbour)->assertForbidden();
    }

    public function test_suspended_residents_stay_listed_as_inactive_for_their_manager(): void
    {
        $this->toggle($this->managerA)->assertOk();

        $row = collect($this->as($this->managerA)->getJson('/api/v1/residents')->assertOk()->json('data'))
            ->firstWhere('id', $this->person->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['isActive']);
    }

    public function test_blocking_the_whole_account_is_still_the_super_admins_alone(): void
    {
        $this->toggle($this->managerA)->assertOk();
        $this->assertTrue($this->person->fresh()->is_active, 'مدیرِ مجتمع حساب را در کلِ سکو نبست.');

        $super = User::factory()->create(['role' => UserRole::SuperAdmin, 'complex_id' => null, 'is_active' => true]);
        $this->as($super)->withSession(['auth.password_confirmed_at' => time()])
            ->patchJson("/api/v1/system/members/{$this->person->id}", ['role' => 'owner', 'is_active' => false])->assertOk();
        $this->assertFalse($this->person->fresh()->is_active);
    }

    public function test_the_migration_turns_a_managers_old_deactivation_into_a_suspension_only(): void
    {
        $migration = require base_path('database/migrations/2026_10_13_000001_add_suspension_to_complex_memberships_table.php');
        $migration->down();

        // دو حسابِ غیرفعال: یکی را مدیر بسته بود، دیگری را ادمینِ کل
        $byManager = $this->person;
        $bySuper = User::factory()->create(['complex_id' => $this->a->id, 'role' => UserRole::Tenant, 'is_active' => true]);
        foreach ([[$byManager, 'resident.deactivated', $this->managerA->id], [$bySuper, 'member.deactivated', null]] as [$user, $action, $actor]) {
            DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
            DB::table('audit_logs')->insert([
                'complex_id' => $this->a->id, 'user_id' => $actor, 'action' => $action, 'subject_type' => User::class,
                'subject_id' => $user->id, 'description' => 'x', 'created_at' => now()->subDay(),
            ]);
        }

        $migration->up();

        // حسابِ اولی باز شد و فقط عضویتِ الف (زمینه‌اش) معلق است؛ ب سرِ جایش
        $this->assertTrue((bool) DB::table('users')->where('id', $byManager->id)->value('is_active'));
        $this->assertSame(ComplexMembership::SUSPENDED, $this->membership($this->a)->status);
        $this->assertSame($this->managerA->id, $this->membership($this->a)->suspended_by);
        $this->assertSame(ComplexMembership::ACTIVE, $this->membership($this->b)->status);
        $this->assertSame($this->b->id, (int) DB::table('users')->where('id', $byManager->id)->value('complex_id'));

        // حسابی که ادمینِ کل بسته بود دست نخورد
        $this->assertFalse((bool) DB::table('users')->where('id', $bySuper->id)->value('is_active'));
        $this->assertSame(ComplexMembership::ACTIVE, ComplexMembership::withoutGlobalScopes()->where('user_id', $bySuper->id)->sole()->status);
        $this->assertSame([], app(MembershipLedger::class)->violations());
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    private function toggle(User $actor): TestResponse
    {
        return $this->as($actor)->patchJson("/api/v1/residents/{$this->person->id}/toggle-active");
    }

    private function membership(Complex $complex): ComplexMembership
    {
        return ComplexMembership::withoutGlobalScopes()->live()
            ->where('user_id', $this->person->id)->where('complex_id', $complex->id)->sole();
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user);
    }
}
