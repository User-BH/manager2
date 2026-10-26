<?php

namespace Tests\Feature;

use App\Enums\AccountState;
use App\Enums\ComplexType;
use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Models\CheckoutIntent;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\ComplexMembership;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\Membership;
use App\Services\Account\MembershipLedger;
use App\Services\Subscription\ComplexPurchase;
use App\Services\Units\TenureService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\TestPlans;
use Tests\TestCase;

/**
 * عضویت به‌عنوانِ موجودیتِ جدا — مرحلهٔ ۱۰ · فاز ۲ (بندهای ۳، ۴، ۱۱ و ۱۳ ِ فایلِ معماری).
 *
 * هر مسیرِ واقعیِ برقراری و پایانِ عضویت یک ردیفِ `complex_memberships` با منبع،
 * شروع، پایان، پایان‌دهنده و دلیل می‌سازد؛ یک حساب در طولِ زمان چند عضویت دارد؛ و
 * زمینه‌ی فعالِ حساب (`users.complex_id` / `role`) هرگز از عضویت جدا نمی‌شود —
 * نه از کنترلر، نه از فرمان، نه از آزمون.
 */
class MembershipLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['name' => 'مجتمع عضویت']);
        $this->manager = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true]);
        $this->unit = Unit::factory()->create(['complex_id' => $this->complex->id]);
    }

    private function registrant(string $phone = '09124440001'): User
    {
        return User::factory()->create(['complex_id' => null, 'role' => UserRole::Owner, 'is_active' => true, 'phone' => $phone]);
    }

    private function ledger(): MembershipLedger
    {
        return app(MembershipLedger::class);
    }

    /** @return Collection<int, ComplexMembership> */
    private function membershipsOf(User $user)
    {
        return ComplexMembership::withoutGlobalScopes()->where('user_id', $user->id)->orderBy('id')->get();
    }

    /* ── سه مسیرِ برقراری ───────────────────────────────────────────────── */

    public function test_accepting_an_invitation_records_an_active_membership_from_that_invitation(): void
    {
        $user = $this->registrant();
        [$invitation] = app(Membership::class)->invite($this->complex, $user->phone, UserRole::Tenant, $this->unit, $this->manager);

        app(Membership::class)->accept($invitation, $user);

        $membership = $this->membershipsOf($user)->sole();
        $this->assertSame(
            [$this->complex->id, UserRole::Tenant, ComplexMembership::ACTIVE, ComplexMembership::SOURCE_INVITATION, $invitation->id],
            [$membership->complex_id, $membership->role, $membership->status, $membership->source, $membership->invitation_id],
        );
        $this->assertNotNull($membership->started_at);
        $this->assertSame([$this->complex->id, UserRole::Tenant], [$user->fresh()->complex_id, $user->fresh()->role]);
    }

    public function test_an_approved_join_request_records_its_own_source(): void
    {
        $user = $this->registrant();
        $request = ComplexInvitation::create([
            'complex_id' => $this->complex->id, 'user_id' => $user->id, 'role' => UserRole::Owner,
            'direction' => ComplexInvitation::REQUEST, 'invited_by' => $user->id, 'status' => ComplexInvitation::PENDING,
        ]);

        $this->actingAs($this->manager)->postJson("/api/v1/join-requests/{$request->id}/approve", ['role' => 'owner', 'unit_id' => $this->unit->id])->assertOk();

        $membership = $this->membershipsOf($user)->sole();
        $this->assertSame([ComplexMembership::SOURCE_JOIN_REQUEST, $request->id, UserRole::Owner], [$membership->source, $membership->invitation_id, $membership->role]);
    }

    public function test_a_verified_purchase_records_the_manager_membership(): void
    {
        $user = $this->registrant();
        $plan = TestPlans::create(['name' => 'پلن', 'slug' => 'p', 'complex_type' => ComplexType::Residential, 'price' => 1000, 'months' => 1, 'is_active' => true]);
        $intent = app(ComplexPurchase::class)->begin($user, $plan, 'مجتمع خریدار', ComplexType::Residential, null);

        $intent = app(ComplexPurchase::class)->complete($intent, 'TRK-M1');

        $this->assertSame(CheckoutIntent::COMPLETED, $intent->status);
        $membership = $this->membershipsOf($user)->sole();
        $this->assertSame([$intent->complex_id, UserRole::ComplexAdmin, ComplexMembership::SOURCE_PURCHASE], [$membership->complex_id, $membership->role, $membership->source]);
    }

    /* ── پایان و تاریخچه ─────────────────────────────────────────────────── */

    /** بندِ ۷۴: پایانِ عضویت حذفِ حساب نیست؛ ردیف با زمان، پایان‌دهنده و دلیل می‌ماند. */
    public function test_ending_closes_the_row_with_who_when_and_why_and_keeps_it(): void
    {
        $resident = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::Owner, 'is_active' => true]);
        app(TenureService::class)->open($this->unit, $resident, ResidentRelation::Owner);

        $this->actingAs($this->manager)->deleteJson("/api/v1/residents/{$resident->id}")->assertOk();

        $membership = $this->membershipsOf($resident)->sole();
        $this->assertSame([ComplexMembership::ENDED, ComplexMembership::END_REMOVED, $this->manager->id, null], [
            $membership->status, $membership->end_reason, $membership->ended_by, $membership->active_key,
        ]);
        $this->assertNotNull($membership->ended_at);
        $this->assertSame(AccountState::Initial, AccountState::of($resident->fresh()));
    }

    /** بندِ ۴: یک حساب در طولِ زمان چند عضویت دارد — رفتن و برگشتن، و مجتمعِ دیگر. */
    public function test_one_account_holds_its_whole_membership_history(): void
    {
        $user = $this->registrant();
        $other = Complex::factory()->create();
        $ledger = $this->ledger();

        $ledger->start($user, $this->complex, UserRole::Tenant, ComplexMembership::SOURCE_DIRECT);
        $ledger->end($user, ComplexMembership::END_REMOVED, $this->manager);
        $ledger->start($user, $other, UserRole::Owner, ComplexMembership::SOURCE_DIRECT);
        $ledger->end($user, ComplexMembership::END_REMOVED);
        $ledger->start($user, $this->complex, UserRole::Owner, ComplexMembership::SOURCE_DIRECT);

        $history = $this->membershipsOf($user);
        $this->assertSame(
            [[$this->complex->id, 'ended'], [$other->id, 'ended'], [$this->complex->id, 'active']],
            $history->map(fn (ComplexMembership $m) => [$m->complex_id, $m->status])->all(),
        );
        $this->assertSame(1, User::withoutGlobalScopes()->where('phone', $user->phone)->count(), 'برای هر عضویت حسابِ تازه‌ای ساخته شد.');
        $this->assertSame([], $ledger->violations());
    }

    /* ── نقش ─────────────────────────────────────────────────────────────── */

    public function test_a_role_change_by_the_manager_changes_the_same_membership(): void
    {
        $resident = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::Owner, 'is_active' => true, 'phone' => '09124440002']);
        app(TenureService::class)->open($this->unit, $resident, ResidentRelation::Owner);
        $before = $this->membershipsOf($resident)->sole();

        $this->actingAs($this->manager)->putJson("/api/v1/residents/{$resident->id}", ['role' => 'tenant', 'unit_id' => $this->unit->id])->assertOk();

        $after = $this->membershipsOf($resident)->sole();
        $this->assertSame([$before->id, UserRole::Tenant, ComplexMembership::ACTIVE], [$after->id, $after->role, $after->status]);
        $this->assertSame(UserRole::Tenant, $resident->fresh()->role);
        $this->assertSame([], $this->ledger()->violations());
    }

    /** ادمینِ کل نقشِ سکوست، نه عضوِ مجتمع: ارتقا عضویت را با دلیلِ «promoted» می‌بندد. */
    public function test_promotion_to_super_admin_ends_the_membership(): void
    {
        $super = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true]);
        $resident = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::Owner, 'is_active' => true]);

        $this->actingAs($super)->withSession(['auth.password_confirmed_at' => time()])
            ->patchJson("/api/v1/system/members/{$resident->id}", ['role' => 'super_admin'])->assertOk();

        $membership = $this->membershipsOf($resident)->sole();
        $this->assertSame([ComplexMembership::ENDED, ComplexMembership::END_PROMOTED, $super->id], [$membership->status, $membership->end_reason, $membership->ended_by]);
        $this->assertSame([null, UserRole::SuperAdmin], [$resident->fresh()->complex_id, $resident->fresh()->role]);
        $this->assertSame([], $this->ledger()->violations());
    }

    /* ── یک نویسنده ──────────────────────────────────────────────────────── */

    /** 🔴 بیرون از دفترِ عضویت، مجتمع و نقشِ حساب عوض نمی‌شوند — نه بی‌صدا، نه با خطای دیرهنگام. */
    public function test_the_account_context_cannot_be_changed_outside_the_ledger(): void
    {
        $resident = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::Owner, 'is_active' => true]);
        $other = Complex::factory()->create();

        foreach ([['complex_id' => $other->id], ['complex_id' => null], ['role' => UserRole::ComplexAdmin]] as $change) {
            try {
                $resident->fresh()->forceFill($change)->save();
                $this->fail('تغییرِ مستقیمِ '.json_encode(array_keys($change)).' پذیرفته شد.');
            } catch (LogicException) {
                // درست
            }
        }

        $this->assertSame([$this->complex->id, UserRole::Owner], [$resident->fresh()->complex_id, $resident->fresh()->role]);
        // بقیه‌ی ستون‌ها آزادند
        $resident->fresh()->forceFill(['bio' => 'تغییرِ عادی'])->save();
        $this->assertSame('تغییرِ عادی', $resident->fresh()->bio);
    }

    /** حسابی که از ابتدا درونِ مجتمع ساخته می‌شود (دادهٔ نمونه) عضوِ همان است؛ ثبت‌نام و ادمینِ کل نه. */
    public function test_an_account_created_inside_a_complex_is_its_member_and_others_are_not(): void
    {
        $member = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::Tenant]);
        $registrant = $this->registrant('09124440003');
        $super = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin]);

        $this->assertSame([ComplexMembership::SOURCE_DIRECT, ComplexMembership::ACTIVE], [
            $this->membershipsOf($member)->sole()->source, $this->membershipsOf($member)->sole()->status,
        ]);
        $this->assertCount(0, $this->membershipsOf($registrant));
        $this->assertCount(0, $this->membershipsOf($super));
        $this->assertSame([], $this->ledger()->violations());
    }

    /* ── قاعده‌ها در سرویس و در پایگاه‌داده ──────────────────────────────── */

    /**
     * از ۱۰/۳ (زنجیره‌ی مجوز) یک حساب در چند مجتمع عضوِ فعال است — یکی برای هر مجتمع.
     * عضویتِ دوم در **همان** مجتمع همچنان رد می‌شود. (۱۰/۲ عضویتِ دوم را در هر مجتمعی رد
     * می‌کرد، چون مجوز هنوز از عضویت خوانده نمی‌شد.) چند عضویت در عمل: `AuthorizationChainTest`.
     */
    public function test_a_second_active_membership_in_the_same_complex_is_refused(): void
    {
        $user = $this->registrant();
        $this->ledger()->start($user, $this->complex, UserRole::Owner, ComplexMembership::SOURCE_DIRECT);
        $this->ledger()->start($user, Complex::factory()->create(), UserRole::Owner, ComplexMembership::SOURCE_DIRECT);

        $this->expectException(DomainException::class);
        $this->ledger()->start($user, $this->complex, UserRole::Tenant, ComplexMembership::SOURCE_DIRECT);
    }

    /** دو عضویتِ فعال برای همان (حساب، مجتمع) را خودِ پایگاه‌داده هم رد می‌کند. */
    public function test_the_database_refuses_a_second_active_membership_in_the_same_complex(): void
    {
        $user = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::Owner]);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('complex_memberships')->insert([
            'user_id' => $user->id, 'complex_id' => $this->complex->id, 'role' => 'owner', 'status' => 'active',
            'source' => 'direct', 'started_at' => now(), 'active_key' => ComplexMembership::activeKey($user->id, $this->complex->id),
        ]);
    }

    /** بندِ ۷۹: تاریخچه‌ی عضویت با حذفِ حساب یا مجتمع پاک نمی‌شود — حذف رد می‌شود. */
    public function test_deleting_the_account_or_the_complex_cannot_erase_membership_history(): void
    {
        $user = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::Owner]);
        $this->ledger()->end($user, ComplexMembership::END_REMOVED);

        foreach ([fn () => DB::table('users')->where('id', $user->id)->delete(), fn () => DB::table('complexes')->where('id', $this->complex->id)->delete()] as $delete) {
            try {
                $delete();
                $this->fail('حذفِ والد تاریخچه‌ی عضویت را پاک کرد.');
            } catch (QueryException) {
                // restrictOnDelete
            }
        }
        $this->assertCount(1, $this->membershipsOf($user));
    }

    /** مدیر فقط عضویت‌های مجتمعِ خودش را می‌خواند (دامنه‌ی مستأجر). */
    public function test_memberships_are_scoped_to_the_active_complex(): void
    {
        $other = Complex::factory()->create();
        User::factory()->create(['complex_id' => $other->id, 'role' => UserRole::Owner]);

        app(TenantContext::class)->set($this->complex->id);

        $this->assertSame([$this->complex->id], ComplexMembership::query()->pluck('complex_id')->unique()->values()->all());
    }

    /* ── سنجشِ هم‌خوانی و مهاجرت ─────────────────────────────────────────── */

    public function test_the_check_command_reports_any_drift_between_account_and_membership(): void
    {
        $this->artisan('memberships:check')->assertSuccessful();

        $resident = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::Owner]);
        // ناهم‌خوانیِ عمدی از راهی که رویدادِ مدل را دور می‌زند (همان راهِ phpMyAdmin)
        DB::table('users')->where('id', $resident->id)->update(['role' => 'tenant']);

        $this->artisan('memberships:check')->assertFailed();
        $this->assertNotEmpty($this->ledger()->violations());
    }

    /**
     * مهاجرت هر حسابِ عضوِ موجود را یک عضویتِ فعال می‌دهد — با شروع از قدیمی‌ترین دوره‌ی
     * واحدش — و ادمینِ کل و حسابِ اولیه را نه. عضویتِ پایان‌یافته حدس زده نمی‌شود.
     */
    public function test_the_migration_backfills_every_existing_member_and_nobody_else(): void
    {
        $migration = require database_path('migrations/2026_10_11_000001_create_complex_memberships_table.php');
        $migration->down();

        // حساب‌های پیش از ۱۰/۲: بی رویدادِ مدل، مثلِ داده‌ی واقعیِ موجود
        $now = now();
        $ids = [];
        foreach ([['owner', $this->complex->id], ['complex_admin', $this->complex->id], ['owner', null], ['super_admin', null]] as $i => [$role, $complexId]) {
            $ids[] = DB::table('users')->insertGetId([
                'name' => "حسابِ قدیمی {$i}", 'phone' => '0912555000'.$i, 'password' => 'x', 'role' => $role,
                'complex_id' => $complexId, 'is_active' => true, 'created_at' => $now->copy()->subYears(2), 'updated_at' => $now,
            ]);
        }
        DB::table('unit_user')->insert([
            'unit_id' => $this->unit->id, 'user_id' => $ids[0], 'complex_id' => $this->complex->id, 'relation' => 'owner',
            'start_date' => '2025-03-01', 'is_current' => true,
        ]);

        $migration->up();

        $rows = DB::table('complex_memberships')->whereIn('user_id', $ids)->orderBy('user_id')->get();
        $this->assertSame([$ids[0], $ids[1]], $rows->pluck('user_id')->all(), 'حسابِ بی‌مجتمع یا ادمینِ کل عضویت گرفت.');
        $this->assertSame(['active', 'active'], $rows->pluck('status')->all());
        $this->assertSame(['backfill', 'backfill'], $rows->pluck('source')->all());
        $this->assertStringStartsWith('2025-03-01', (string) $rows[0]->started_at, 'شروع از دوره‌ی واحد خوانده نشد.');
        $this->assertSame([], $this->ledger()->violations());
    }
}
