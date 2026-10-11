<?php

namespace Tests\Feature;

use App\Enums\AccountState;
use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\Unit;
use App\Models\UnitTenure;
use App\Models\User;
use App\Services\Account\Membership;
use App\Services\Account\MembershipLedger;
use App\Services\Units\TenureService;
use App\Support\Jalali;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * زنجیره‌ی مجوز و زمینه‌ی فعال — مرحلهٔ ۱۰ · فاز ۳ (بندهای ۱، ۴، ۱۲، ۱۳، ۱۴، ۱۵، ۷۵ و ۹۲
 * ِ فایلِ معماری).
 *
 * علی در دو مجتمع عضوِ فعال است: در «الف» مالکِ واحد و در «ب» مستأجرِ واحد. مینا در الف
 * مدیر و در ب مالک است. آزمون‌ها نشان می‌دهند:
 *   • زمینه‌ی فعال تعیین می‌کند علی **الان** کجاست و نقشش چیست؛ دادهٔ مجتمعِ دیگر دیده نمی‌شود؛
 *   • تعویض فقط به مجتمعی که عضویتِ فعال دارد، و نقش همان نقشِ عضویتِ آن‌جاست؛
 *   • پایانِ یک عضویت بقیه را دست نمی‌زند؛
 *   • هر درخواست باید عضویتِ فعالِ هم‌خوان پشتش باشد — وگرنه رد، نه بازگشت به جای دیگر؛
 *   • مدیرِ هر مجتمع اعضایش را از عضویت می‌بیند، حتی اگر الان در مجتمعِ دیگری باشند.
 */
class AuthorizationChainTest extends TestCase
{
    use RefreshDatabase;

    private Complex $a;

    private Complex $b;

    private Unit $unitA;

    private Unit $unitB;

    private User $managerA;

    private User $managerB;

    private User $ali;

    private Bill $billA;

    private Bill $billB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Complex::factory()->create(['name' => 'مجتمع الف']);
        $this->b = Complex::factory()->create(['name' => 'مجتمع ب']);
        $this->unitA = Unit::factory()->create(['complex_id' => $this->a->id, 'unit_number' => 'الف-۱']);
        $this->unitB = Unit::factory()->create(['complex_id' => $this->b->id, 'unit_number' => 'ب-۷']);
        $this->managerA = User::factory()->create(['complex_id' => $this->a->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09126660101']);
        $this->managerB = User::factory()->create(['complex_id' => $this->b->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09126660102']);

        // علی: مالکِ الف، سپس مستأجرِ ب — زمینه‌ی فعال ب
        $this->ali = User::factory()->create(['complex_id' => $this->a->id, 'role' => UserRole::Owner, 'is_active' => true, 'phone' => '09126660103', 'name' => 'علی دوعضوی']);
        app(TenureService::class)->open($this->unitA, $this->ali, ResidentRelation::Owner, 100, now()->subYear());
        app(MembershipLedger::class)->start($this->ali, $this->b, UserRole::Tenant, ComplexMembership::SOURCE_DIRECT);
        app(TenureService::class)->open($this->unitB, $this->ali, ResidentRelation::Tenant, 100, now()->subMonths(6));

        $period = Jalali::shiftPeriod(Jalali::currentPeriod(), -1);
        $this->billA = Bill::factory()->create(['complex_id' => $this->a->id, 'unit_id' => $this->unitA->id, 'period' => $period]);
        $this->billB = Bill::factory()->create(['complex_id' => $this->b->id, 'unit_id' => $this->unitB->id, 'period' => $period]);
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs(User::withoutGlobalScopes()->findOrFail($user->id));
    }

    private function switchTo(User $user, int $complexId): TestResponse
    {
        return $this->as($user)->postJson('/api/v1/account/context', ['complex_id' => $complexId]);
    }

    /* ── چند عضویت، یک حساب ─────────────────────────────────────────────── */

    /** بندهای ۴ و ۱۳: یک حساب، چند عضویتِ فعال؛ فهرست فقط مالِ خودش. */
    public function test_one_account_is_an_active_member_of_several_complexes(): void
    {
        $response = $this->as($this->ali)->getJson('/api/v1/account/memberships')->assertOk();

        $this->assertSame($this->b->id, $response->json('current'));
        $this->assertEqualsCanonicalizing(
            [[$this->a->id, 'owner', false], [$this->b->id, 'tenant', true]],
            collect($response->json('memberships'))->map(fn ($m) => [$m['complexId'], $m['role'], $m['current']])->all(),
        );
        $this->assertSame(1, User::withoutGlobalScopes()->where('phone', $this->ali->phone)->count());
        $this->assertSame([], app(MembershipLedger::class)->violations());
    }

    /** بندهای ۱۳ و ۱۴: دادهٔ یک مجتمع در زمینه‌ی مجتمعِ دیگر دیده نمی‌شود. */
    public function test_the_active_context_decides_whose_data_is_visible(): void
    {
        // زمینه‌ی ب
        $this->as($this->ali)->getJson("/api/v1/my-bills/{$this->billB->id}")->assertOk();
        $this->as($this->ali)->getJson("/api/v1/my-bills/{$this->billA->id}")->assertNotFound();
        $this->assertSame([$this->billB->id], array_column($this->as($this->ali)->getJson('/api/v1/my-bills')->assertOk()->json('data'), 'id'));

        // زمینه‌ی الف
        $this->switchTo($this->ali, $this->a->id)->assertOk()->assertJsonPath('current', $this->a->id);
        $this->as($this->ali)->getJson("/api/v1/my-bills/{$this->billA->id}")->assertOk();
        $this->as($this->ali)->getJson("/api/v1/my-bills/{$this->billB->id}")->assertNotFound();
        $this->assertSame([$this->billA->id], array_column($this->as($this->ali)->getJson('/api/v1/my-bills')->assertOk()->json('data'), 'id'));
    }

    /** بندِ ۹۲: مدیرِ الف که به ب (مالک) می‌رود دیگر کنترلِ مدیریت ندارد — و برعکس. */
    public function test_switching_context_switches_the_role_with_it(): void
    {
        $mina = User::factory()->create(['complex_id' => $this->a->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09126660104']);
        app(MembershipLedger::class)->start($mina, $this->b, UserRole::Owner, ComplexMembership::SOURCE_DIRECT);

        // زمینه‌ی ب: مالک
        $this->as($mina)->getJson('/api/v1/units')->assertForbidden();
        $this->as($mina)->getJson('/api/v1/residents')->assertForbidden();

        // زمینه‌ی الف: مدیر — و فقط واحدهای الف
        $this->switchTo($mina, $this->a->id)->assertOk();
        $this->assertSame(UserRole::ComplexAdmin, User::withoutGlobalScopes()->find($mina->id)->role);
        $units = $this->as($mina)->getJson('/api/v1/units')->assertOk()->json('data');
        $this->assertSame([$this->unitA->id], array_column($units, 'id'));
        $this->as($mina)->getJson("/api/v1/units/{$this->unitB->id}")->assertNotFound();
    }

    /** بندهای ۱۲ و ۱۵: تعویض فقط به مجتمعی که عضویتِ فعال دارد — بی بازگشت، بی لو دادن. */
    public function test_switching_to_a_complex_without_an_active_membership_is_refused(): void
    {
        $c = Complex::factory()->create();

        $other = $this->switchTo($this->ali, $c->id)->assertStatus(422);
        $missing = $this->switchTo($this->ali, 999999)->assertStatus(422);

        $this->assertSame('membership.not_member', $other->json('code'));
        $this->assertSame($other->json('message'), $missing->json('message'), 'پاسخ وجودِ مجتمع را لو داد.');
        $this->assertSame($this->b->id, User::withoutGlobalScopes()->find($this->ali->id)->complex_id, 'زمینه با تعویضِ ناموفق عوض شد.');

        // شناسه‌ی حسابِ دیگر در بدنه هیچ اثری ندارد
        $this->as($this->ali)->postJson('/api/v1/account/context', ['complex_id' => $this->a->id, 'user_id' => $this->managerB->id])->assertOk();
        $this->assertSame($this->b->id, User::withoutGlobalScopes()->find($this->managerB->id)->complex_id);
    }

    public function test_the_super_admin_has_no_membership_context_to_switch(): void
    {
        $super = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true]);

        $this->switchTo($super, $this->a->id)->assertStatus(422)->assertJsonPath('code', 'membership.platform_account');
    }

    /* ── پایانِ یک عضویت ─────────────────────────────────────────────────── */

    /** بندِ ۱۲: پایانِ عضویتِ ب عضویت و دوره‌ی واحدِ الف را دست نمی‌زند. */
    public function test_ending_one_membership_leaves_the_others_untouched(): void
    {
        $this->switchTo($this->ali, $this->a->id)->assertOk();

        $this->as($this->managerB)->deleteJson("/api/v1/residents/{$this->ali->id}")->assertOk();

        $ali = User::withoutGlobalScopes()->find($this->ali->id);
        $this->assertSame([$this->a->id, UserRole::Owner], [$ali->complex_id, $ali->role]);
        $this->assertSame(1, UnitTenure::where('user_id', $ali->id)->where('unit_id', $this->unitA->id)->current()->count(), 'دوره‌ی واحدِ الف بسته شد.');
        $this->assertSame(0, UnitTenure::where('user_id', $ali->id)->where('unit_id', $this->unitB->id)->current()->count());
        $this->switchTo($this->ali, $this->b->id)->assertStatus(422);
        $this->assertSame([], app(MembershipLedger::class)->violations());
    }

    /** پایانِ عضویتِ زمینه‌ی فعال: حساب به عضویتِ فعالِ دیگرش می‌رود، نه به حالتِ اولیه. */
    public function test_ending_the_active_context_falls_back_to_another_membership(): void
    {
        $this->as($this->managerB)->deleteJson("/api/v1/residents/{$this->ali->id}")->assertOk();

        $ali = User::withoutGlobalScopes()->find($this->ali->id);
        $this->assertSame([$this->a->id, UserRole::Owner, AccountState::Member], [$ali->complex_id, $ali->role, AccountState::of($ali)]);
        $this->as($this->ali)->getJson("/api/v1/my-bills/{$this->billA->id}")->assertOk();

        // و با پایانِ آخری، حالتِ اولیه
        $this->as($this->managerA)->deleteJson("/api/v1/residents/{$this->ali->id}")->assertOk();
        $this->assertSame(AccountState::Initial, AccountState::of(User::withoutGlobalScopes()->find($this->ali->id)));
    }

    /* ── زنجیره روی هر درخواست ───────────────────────────────────────────── */

    /** بندِ ۷۵: عضویتی که پشتِ سرِ حساب پایان یافته هیچ درخواستی را راه نمی‌دهد — بی بازگشت. */
    public function test_a_membership_ended_behind_the_accounts_back_denies_every_request(): void
    {
        DB::table('complex_memberships')->where('user_id', $this->ali->id)->where('complex_id', $this->b->id)
            ->update(['status' => 'ended', 'ended_at' => now(), 'active_key' => null]);

        foreach (['/api/v1/my-bills', '/api/v1/dashboard', "/api/v1/my-bills/{$this->billA->id}", '/api/v1/profile'] as $path) {
            $this->as($this->ali)->getJson($path)->assertForbidden()->assertJsonPath('code', 'membership.inactive');
        }

        // خروج آزاد است
        $this->as($this->ali)->postJson('/api/v1/logout')->assertSuccessful();
    }

    /** نقشِ دست‌کاری‌شده روی حساب بی عضویتِ هم‌خوان مجوز نمی‌دهد. */
    public function test_a_forged_role_on_the_account_is_not_authority(): void
    {
        DB::table('users')->where('id', $this->ali->id)->update(['role' => 'complex_admin']);

        $this->as($this->ali)->getJson('/api/v1/units')->assertForbidden()->assertJsonPath('code', 'membership.inactive');
        $this->as($this->ali)->getJson('/api/v1/residents')->assertForbidden();
    }

    /** سنجشِ عضویت در همان کوئریِ مجتمعِ جاری است: هزینه‌ی هر درخواست یک کوئری بیشتر نشد. */
    public function test_the_chain_reads_the_complex_once_per_request(): void
    {
        $queries = [];
        $this->as($this->ali);
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'from "complexes"') || str_contains($query->sql, 'from `complexes`')) {
                $queries[] = $query->sql;
            }
        });

        $this->getJson('/api/v1/notifications')->assertOk();

        $this->assertCount(1, $queries, 'مجتمعِ جاری بیش از یک بار خوانده شد: '.implode(' | ', $queries));
        $this->assertStringContainsString('complex_memberships', $queries[0], 'تنها خواندنِ مجتمع عضویت را نمی‌سنجد.');
    }

    /* ── خواننده‌ها از عضویت ─────────────────────────────────────────────── */

    /** عضوی که الان در الف است همچنان ساکنِ ب است — با نقشِ عضویتِ ب، نه نقشِ زمینه‌اش. */
    public function test_a_member_working_elsewhere_still_appears_in_the_other_complex(): void
    {
        $this->switchTo($this->ali, $this->a->id)->assertOk();

        $residents = $this->as($this->managerB)->getJson('/api/v1/residents')->assertOk()->json('data');
        $row = collect($residents)->firstWhere('id', $this->ali->id);
        $this->assertNotNull($row, 'عضوِ ب از فهرستِ ساکنانِ ب افتاد چون الان در الف است.');
        $this->assertSame('tenant', $row['role'], 'نقشِ زمینه‌ی الف به جای نقشِ عضویتِ ب نشان داده شد.');

        $groups = collect($this->as($this->managerB)->getJson('/api/search?q='.urlencode('دوعضوی'))->assertOk()->json('groups'));
        $this->assertTrue($groups->contains('id', 'residents'), 'جست‌وجوی مدیرِ ب عضوِ ب را پیدا نکرد.');
        $this->assertStringContainsString('دوعضوی', json_encode($groups->firstWhere('id', 'residents'), JSON_UNESCAPED_UNICODE));

        // و مدیرِ الف که ساکنِ ب نیست او را در ب نمی‌بیند
        $this->assertNull(collect($this->as($this->managerB)->getJson('/api/v1/residents')->json('data'))->firstWhere('id', $this->managerA->id));
    }

    /** تغییرِ نقش در ب، عضویتِ ب را عوض می‌کند — نه زمینه‌ی فعالِ الف را. */
    public function test_a_role_change_applies_to_the_membership_of_that_complex_only(): void
    {
        $this->switchTo($this->ali, $this->a->id)->assertOk();

        $this->as($this->managerB)->putJson("/api/v1/residents/{$this->ali->id}", ['role' => 'owner', 'unit_id' => $this->unitB->id])
            ->assertOk()->assertJsonPath('resident.role', 'owner');

        $roles = ComplexMembership::withoutGlobalScopes()->active()->where('user_id', $this->ali->id)->pluck('role', 'complex_id');
        $this->assertSame([UserRole::Owner, UserRole::Owner], [$roles[$this->a->id], $roles[$this->b->id]]);
        $ali = User::withoutGlobalScopes()->find($this->ali->id);
        $this->assertSame([$this->a->id, UserRole::Owner], [$ali->complex_id, $ali->role]);
        $this->assertSame([], app(MembershipLedger::class)->violations());
    }

    /** مدیرِ ب نقشِ مینا را در ب عوض می‌کند؛ مدیریتِ او در الف (زمینه‌ی فعالش) دست نمی‌خورد. */
    public function test_a_role_change_in_one_complex_never_touches_the_role_in_another(): void
    {
        $mina = User::factory()->create(['complex_id' => $this->a->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09126660106']);
        app(MembershipLedger::class)->start($mina, $this->b, UserRole::Owner, ComplexMembership::SOURCE_DIRECT);
        app(TenureService::class)->open($this->unitB, $mina, ResidentRelation::Owner, 50);
        $this->switchTo($mina, $this->a->id)->assertOk();

        $this->as($this->managerB)->putJson("/api/v1/residents/{$mina->id}", ['role' => 'tenant', 'unit_id' => $this->unitB->id])->assertOk()->assertJsonPath('resident.role', 'tenant');

        $roles = ComplexMembership::withoutGlobalScopes()->active()->where('user_id', $mina->id)->pluck('role', 'complex_id');
        $this->assertSame([UserRole::ComplexAdmin, UserRole::Tenant], [$roles[$this->a->id], $roles[$this->b->id]]);
        $this->assertSame(UserRole::ComplexAdmin, User::withoutGlobalScopes()->find($mina->id)->role, 'نقشِ زمینه‌ی الف عوض شد.');
        $this->as($mina)->getJson('/api/v1/units')->assertOk();
    }

    /** مدیرِ الف در ب مدیر نیست: مدیرِ ب نمی‌تواند او را «مدیر» حساب کند. */
    public function test_being_a_manager_in_one_complex_grants_nothing_in_another(): void
    {
        $mina = User::factory()->create(['complex_id' => $this->a->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09126660105']);
        app(MembershipLedger::class)->start($mina, $this->b, UserRole::Owner, ComplexMembership::SOURCE_DIRECT);
        $this->switchTo($mina, $this->a->id)->assertOk();

        $managersOfB = $this->as($this->managerB)->getJson('/api/v1/managers')->assertOk()->json('data');
        $this->assertSame([$this->managerB->id], array_column($managersOfB, 'id'));
        // با تأییدِ دوباره‌ی رمز، تا ۴۰۳ از خودِ قاعده بیاید نه از `reauth`
        $this->as($this->managerB)->withSession(['auth.password_confirmed_at' => time()])
            ->deleteJson("/api/v1/managers/{$mina->id}")->assertForbidden()->assertJsonPath('code', 'forbidden');

        $managersOfA = $this->as($this->managerA)->getJson('/api/v1/managers')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$this->managerA->id, $mina->id], array_column($managersOfA, 'id'));
    }

    /** نگهبانِ ورود: مسیرهای پیوستن هنوز فقط حسابِ اولیه را راه می‌دهند (تا رابطِ تعویض در ۱۰/۴). */
    public function test_the_joining_flows_still_admit_only_initial_accounts(): void
    {
        // عضوِ الف و ب به مجتمعِ سوم دعوت نمی‌شود — مسیرِ پیوستن هنوز فقط برای حسابِ اولیه
        $c = Complex::factory()->create();
        $unitC = Unit::factory()->create(['complex_id' => $c->id]);

        $this->expectException(DomainException::class);
        app(Membership::class)->invite($c, $this->ali->phone, UserRole::Tenant, $unitC, null);
    }
}
