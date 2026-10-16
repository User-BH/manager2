<?php

namespace Tests\Feature;

use App\Enums\ComplexType;
use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Http\Middleware\SetCurrentComplex;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\Plan;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\Membership;
use App\Services\Account\MembershipLedger;
use App\Services\Subscription\ComplexPurchase;
use App\Services\Units\TenureService;
use App\Support\Jalali;
use App\Support\SessionContext;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * زمینه‌ی مجتمع مالِ نشست است، نقش مالِ عضویت — مرحلهٔ ۱۲ · فاز ۱ (بندهای ۱ تا ۵، ۱۶ تا ۱۸ و ۳۴ ِ سندِ هسته).
 *
 * ۱۲/۰ نشان داد زمینه فقط روی ردیفِ حساب بود: تعویضِ مجتمع روی یک دستگاه دستگاهِ دیگر را هم جابه‌جا می‌کرد و
 * «نقش» شکلِ ویژگیِ دائمیِ حساب داشت. حالا هر نشست مجتمعِ خودش را دارد (`SessionContext`)، نقشِ هر درخواست از
 * عضویتِ فعالِ همان مجتمع است، و ستون‌های حساب فقط پیش‌فرضِ نشستِ تازه‌اند.
 *
 * «دو دستگاه» این‌جا دو نشست است: داده‌ی نشست هر بار صریح گذاشته می‌شود و حساب هر بار تازه از پایگاه‌داده خوانده
 * می‌شود — مثلِ درخواستِ واقعی.
 */
class SessionContextTest extends TestCase
{
    use RefreshDatabase;

    private Complex $a;

    private Complex $b;

    private Unit $unitB;

    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Complex::factory()->create(['name' => 'مجتمع الف']);
        $this->b = Complex::factory()->create(['name' => 'مجتمع ب']);
        $this->unitB = Unit::factory()->create(['complex_id' => $this->b->id, 'unit_number' => 'ب-۳']);

        // سارا: مدیرِ الف و مالکِ ب؛ پیش‌فرضِ حساب الف
        $this->sara = User::factory()->create(['complex_id' => $this->a->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09127770101']);
        app(MembershipLedger::class)->start($this->sara, $this->b, UserRole::Owner, ComplexMembership::SOURCE_DIRECT, activate: false);
        app(TenureService::class)->open($this->unitB, $this->sara, ResidentRelation::Owner, 100, now()->subYear());
    }

    /** 🔴 بندهای ۴ و ۱۸: تعویض روی یک دستگاه دستگاهِ دیگرِ همان حساب را جابه‌جا نمی‌کند. */
    public function test_switching_on_one_device_leaves_the_other_device_where_it_was(): void
    {
        $phone = $this->device($this->sara, null)->getJson('/api/v1/me')->assertOk();
        $this->assertSame($this->a->id, $phone->json('user.complex.id'));
        $phoneSession = $this->pinOf($phone);
        $this->assertSame(['user' => $this->sara->id, 'complex' => $this->a->id], $phoneSession, 'مرورگرِ تازه به پیش‌فرض سنجاق می‌شود.');

        // لپ‌تاپ به «ب» می‌رود
        $laptop = $this->device($this->sara, null)->postJson('/api/v1/account/context', ['complex_id' => $this->b->id])->assertOk();
        $this->assertSame($this->b->id, $this->pinOf($laptop)['complex']);
        $this->assertSame($this->b->id, User::find($this->sara->id)->complex_id, 'پیش‌فرضِ حساب همان انتخابِ آخر است.');

        // گوشی هنوز در «الف» و هنوز مدیر
        $again = $this->device($this->sara, $phoneSession)->getJson('/api/v1/me')->assertOk();
        $this->assertSame([$this->a->id, 'complex_admin', 'manager'], [$again->json('user.complex.id'), $again->json('user.role'), $again->json('user.dashboard')]);
        $again->assertHeader(SetCurrentComplex::CONTEXT_HEADER, $this->a->id.':complex_admin');
        $this->device($this->sara, $phoneSession)->getJson('/api/v1/residents')->assertOk();
    }

    /** 🔴 بندِ ۱۸ و قراردادِ ۶: نقش از عضویتِ مجتمعِ همین نشست است، نه از حساب. */
    public function test_the_role_comes_from_the_membership_of_the_sessions_community(): void
    {
        $inB = ['user' => $this->sara->id, 'complex' => $this->b->id];

        $me = $this->device($this->sara, $inB)->getJson('/api/v1/me')->assertOk();
        $this->assertSame(['owner', 'unit'], [$me->json('user.role'), $me->json('user.dashboard')]);
        $me->assertHeader(SetCurrentComplex::CONTEXT_HEADER, $this->b->id.':owner');

        // مدیرِ الف در «ب» مدیر نیست
        $this->device($this->sara, $inB)->getJson('/api/v1/residents')->assertForbidden();
        $this->device($this->sara, $inB)->getJson('/api/v1/units')->assertForbidden();

        // و پیش‌فرضِ حساب (الف، مدیر) با این درخواست‌ها عوض نشد
        $this->assertSame([$this->a->id, UserRole::ComplexAdmin], [User::find($this->sara->id)->complex_id, User::find($this->sara->id)->role]);
    }

    /** پایانِ عضویتِ مجتمعِ نشست: نشست به پیش‌فرض برمی‌گردد و رابط از سربرگ می‌فهمد. */
    public function test_a_session_whose_membership_ended_falls_back_to_the_default(): void
    {
        $inB = ['user' => $this->sara->id, 'complex' => $this->b->id];
        $managerB = User::factory()->create(['complex_id' => $this->b->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09127770102']);

        $this->device($managerB, null)->deleteJson("/api/v1/residents/{$this->sara->id}")->assertOk();

        $response = $this->device($this->sara, $inB)->getJson('/api/v1/me')->assertOk();
        $this->assertSame($this->a->id, $response->json('user.complex.id'));
        $response->assertHeader(SetCurrentComplex::CONTEXT_HEADER, $this->a->id.':complex_admin');
        $this->assertSame($this->a->id, $this->pinOf($response)['complex'], 'مرورگر دوباره به پیش‌فرض سنجاق شد.');

        // و هیچ داده‌ی «ب» به او نمی‌رسد
        $bill = Bill::factory()->create(['complex_id' => $this->b->id, 'unit_id' => $this->unitB->id, 'period' => Jalali::currentPeriod()]);
        $this->assertContains($this->device($this->sara, $inB)->getJson("/api/v1/my-bills/{$bill->id}")->status(), [403, 404]);
    }

    /** 🔴 سنجاقِ دست‌کاری‌شده یا شناسه‌ی مجتمع در درخواست هیچ اختیاری نمی‌سازد. */
    public function test_a_forged_pin_or_community_parameter_grants_nothing(): void
    {
        $stranger = Complex::factory()->create(['name' => 'مجتمعِ بیگانه']);
        $strangerUnit = Unit::factory()->create(['complex_id' => $stranger->id, 'unit_number' => 'بیگانه-۱']);

        // سنجاق به مجتمعی که عضوش نیست ← پیش‌فرض
        $forged = $this->device($this->sara, ['user' => $this->sara->id, 'complex' => $stranger->id])->getJson('/api/v1/me')->assertOk();
        $this->assertSame($this->a->id, $forged->json('user.complex.id'));

        // سنجاقِ نشستِ حسابِ دیگر ← نادیده
        $otherAccount = $this->device($this->sara, ['user' => $this->sara->id + 999, 'complex' => $this->b->id])->getJson('/api/v1/me')->assertOk();
        $this->assertSame($this->a->id, $otherAccount->json('user.complex.id'));

        // شناسه‌ی مجتمع در پارامتر و سربرگ
        $units = $this->device($this->sara, null)
            ->withHeaders(['X-Complex-Id' => (string) $stranger->id])
            ->getJson("/api/v1/units?complex_id={$stranger->id}")
            ->assertOk();
        $this->assertNotContains($strangerUnit->id, collect($units->json('data'))->pluck('id'));
        $this->assertContains($this->device($this->sara, null)->getJson("/api/v1/units/{$strangerUnit->id}")->status(), [403, 404]);

        // تعویض به مجتمعِ بیگانه
        $this->device($this->sara, null)->postJson('/api/v1/account/context', ['complex_id' => $stranger->id])->assertStatus(422);
    }

    /** 🔴 بندِ ۱۷: یک حساب در ده مجتمع با نقش‌های گوناگون — هر زمینه فقط مالِ خودش. */
    public function test_one_account_in_ten_communities_with_different_roles(): void
    {
        $roles = [UserRole::ComplexAdmin, UserRole::ComplexAdmin, UserRole::Owner, UserRole::Owner, UserRole::Tenant, UserRole::ComplexAdmin, UserRole::Owner, UserRole::Tenant, UserRole::ComplexAdmin, UserRole::Tenant];
        $person = User::factory()->create(['complex_id' => null, 'role' => UserRole::Owner, 'is_active' => true, 'phone' => '09127770199']);
        $communities = [];

        foreach ($roles as $i => $role) {
            $complex = Complex::factory()->create(['name' => 'مجتمعِ '.($i + 1)]);
            app(MembershipLedger::class)->start($person, $complex, $role, ComplexMembership::SOURCE_DIRECT, activate: false);
            $unit = Unit::factory()->create(['complex_id' => $complex->id, 'unit_number' => 'U'.($i + 1)]);
            if ($role !== UserRole::ComplexAdmin) {
                app(TenureService::class)->open($unit, $person, $role === UserRole::Owner ? ResidentRelation::Owner : ResidentRelation::Tenant, 100, now()->subMonth());
            }
            $communities[] = [$complex, $role, $unit];
        }

        $this->assertSame(10, ComplexMembership::withoutGlobalScopes()->active()->where('user_id', $person->id)->count());
        $this->assertSame(1, User::where('phone', '09127770199')->count(), 'یک شخص، یک حساب.');

        foreach ($communities as $i => [$complex, $role, $unit]) {
            $session = ['user' => $person->id, 'complex' => $complex->id];
            $me = $this->device($person, $session)->getJson('/api/v1/me')->assertOk();

            $this->assertSame([$complex->id, $role->value], [$me->json('user.complex.id'), $me->json('user.role')], "مجتمعِ {$i}");
            $this->assertSame($role === UserRole::ComplexAdmin ? 'manager' : 'unit', $me->json('user.dashboard'));
            $this->assertCount(10, $me->json('user.memberships'));

            // مدیر فقط مدیرِ همان‌جا: فهرستِ واحدها فقط واحدِ همین مجتمع
            if ($role === UserRole::ComplexAdmin) {
                $ids = collect($this->device($person, $session)->getJson('/api/v1/units')->assertOk()->json('data'))->pluck('id')->all();
                $this->assertSame([$unit->id], $ids);
            } else {
                $this->device($person, $session)->getJson('/api/v1/units')->assertForbidden();
            }

            // واحدِ مجتمعِ بعدی از این زمینه دیده نمی‌شود
            $next = $communities[($i + 1) % 10][2];
            $this->assertContains($this->device($person, $session)->getJson("/api/v1/units/{$next->id}")->status(), [403, 404]);
        }
    }

    /** 🔴 بندهای ۱ تا ۳ و ۳۶: اولیه ← واحد و اولیه ← مدیر همان حساب‌اند و هویت دست نمی‌خورد. */
    public function test_the_same_account_and_profile_survive_both_transitions(): void
    {
        $person = User::factory()->create([
            'complex_id' => null, 'role' => UserRole::Owner, 'is_active' => true, 'phone' => '09127770150',
            'first_name' => 'مریم', 'last_name' => 'کاظمی', 'national_id' => '0012345679', 'bio' => 'ساکنِ تازه',
        ]);
        $identity = fn () => User::withoutGlobalScopes()->findOrFail($person->id)->only(['id', 'phone', 'first_name', 'last_name', 'national_id', 'bio', 'name']);
        $before = $identity();
        $this->device($person, null)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.dashboard', 'initial');

        // اولیه ← واحد: دعوتِ مدیر، پذیرشِ خودش
        $managerB = User::factory()->create(['complex_id' => $this->b->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09127770151']);
        $freeUnit = Unit::factory()->create(['complex_id' => $this->b->id, 'unit_number' => 'ب-۹']);
        [$invitation] = app(Membership::class)->invite($this->b, $person->phone, UserRole::Tenant, $freeUnit, $managerB);
        app(Membership::class)->accept($invitation, User::find($person->id));
        $this->assertSame($before, $identity());
        $this->device($person, null)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.dashboard', 'unit');

        // و همان حساب ← مدیرِ مجتمعِ تازه با خرید
        $plan = Plan::create(['name' => 'پایه', 'slug' => 'basic-test', 'price' => 100_000, 'months' => 1, 'is_active' => true, 'complex_type' => ComplexType::Residential->value]);
        $purchase = app(ComplexPurchase::class);
        $intent = $purchase->begin(User::find($person->id), $plan, 'مجتمعِ مریم', ComplexType::Residential, null);
        $done = $purchase->complete($intent, 'TRK-12-1');
        $this->assertSame('completed', $done->status);

        $this->assertSame($before, $identity(), 'هویت پس از مدیر شدن همان است.');
        $this->assertSame(1, User::where('phone', $person->phone)->count());
        $this->assertSame(2, ComplexMembership::withoutGlobalScopes()->active()->where('user_id', $person->id)->count());

        $manager = $this->device($person, ['user' => $person->id, 'complex' => $done->complex_id])->getJson('/api/v1/me')->assertOk();
        $this->assertSame(['manager', $done->complex_id], [$manager->json('user.dashboard'), $manager->json('user.complex.id')]);
    }

    /** بندِ ۳۴: سرور داشبورد را می‌گوید — اولیه، مدیر، واحد، سکو. */
    public function test_the_server_declares_the_dashboard(): void
    {
        $initial = User::factory()->create(['complex_id' => null, 'role' => UserRole::Owner, 'is_active' => true]);
        $super = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true]);

        $this->device($initial, null)->getJson('/api/v1/me')->assertJsonPath('user.dashboard', 'initial')->assertHeaderMissing(SetCurrentComplex::CONTEXT_HEADER);
        $this->device($super, null)->getJson('/api/v1/me')->assertJsonPath('user.dashboard', 'platform')->assertHeaderMissing(SetCurrentComplex::CONTEXT_HEADER);
        $this->device($this->sara, null)->getJson('/api/v1/me')->assertJsonPath('user.dashboard', 'manager');
    }

    /**
     * «دستگاه»: مرورگری با کوکیِ سنجاقِ خودش (رمزنگاری‌شده، مثلِ مرورگرِ واقعی)؛ حساب هر بار تازه — مثلِ درخواستِ
     * واقعی. کوکی‌های پیش‌فرضِ آزمون بینِ درخواست‌ها پاک می‌شوند تا دو دستگاه قاطی نشوند.
     *
     * @param  array{user: int, complex: int}|null  $pin
     */
    private function device(User $user, ?array $pin): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();
        $this->defaultCookies = [];
        // درخواستِ JSON ِ آزمون بی این کوکی نمی‌فرستد — مرورگرِ واقعی همیشه می‌فرستد (`credentials: include`)
        $this->withCredentials();
        if ($pin !== null) {
            $this->withCookie(SessionContext::COOKIE, $pin['user'].':'.$pin['complex']);
        }

        return $this->actingAs(User::withoutGlobalScopes()->findOrFail($user->id));
    }

    /** @return array{user: int, complex: int}|null سنجاقی که همین پاسخ گذاشت */
    private function pinOf(TestResponse $response): ?array
    {
        $value = $response->getCookie(SessionContext::COOKIE)?->getValue();

        return is_string($value) && preg_match('/^(\d+):(\d+)$/', $value, $m) === 1 ? ['user' => (int) $m[1], 'complex' => (int) $m[2]] : null;
    }

    /** 🔴 درخواستی که سنجاق را عوض نمی‌کند کوکیِ سنجاق نمی‌فرستد — پاسخِ دیرِ درخواستِ قدیمی سنجاقِ تازه را نمی‌پوشاند. */
    public function test_a_request_that_does_not_change_the_pin_sends_no_pin_cookie(): void
    {
        $pinned = ['user' => $this->sara->id, 'complex' => $this->a->id];

        $this->device($this->sara, $pinned)->getJson('/api/v1/me')->assertOk()->assertCookieMissing(SessionContext::COOKIE);
        $this->device($this->sara, $pinned)->getJson('/api/v1/notifications')->assertOk()->assertCookieMissing(SessionContext::COOKIE);

        // و تعویض همان لحظه سنجاقِ تازه را می‌فرستد
        $switch = $this->device($this->sara, $pinned)->postJson('/api/v1/account/context', ['complex_id' => $this->b->id])->assertOk();
        $this->assertSame($this->b->id, $this->pinOf($switch)['complex']);
    }
}
