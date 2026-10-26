<?php

namespace Tests\Feature;

use App\Enums\ComplexType;
use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Http\Middleware\SetCurrentComplex;
use App\Models\Bill;
use App\Models\CheckoutIntent;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\ComplexMembership;
use App\Models\Plan;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\MembershipLedger;
use App\Services\Units\TenureService;
use App\Support\Jalali;
use App\Support\SessionContext;
use App\Support\SystemSettings;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * هسته‌ی چندمجتمعی — مرحلهٔ ۱۳ · فاز ۳ (ایرادات ۱۰، بندهای ۳۰ تا ۵۱ و ۱۰۴؛ ایرادات ۱۱، بندهای ۵.۳ تا ۵.۱۲ و ۵.۱۹).
 *
 * دو زمینه‌ی بی‌مجتمعِ تازه روی سنجاقِ همین مرورگر (`SessionContext`)، هر دو بی هیچ داده‌ی مجتمعی:
 *
 *   - **انتخاب** (`choose`): ورودِ حسابِ چندمجتمعی — هیچ مجتمعی خودکار برگزیده نمی‌شود، هر بار؛ تا گزینش جز گزینش و
 *     خروج هیچ نوشتنی پذیرفته نیست.
 *   - **اولیه** (`initial`): «ایجادِ حسابِ جدید» از منوی آدمک — همان داشبوردِ اولیه با همان Account؛ پیش‌فرضِ حساب و
 *     عضویت‌ها دست نمی‌خورند و بازگشت به هر مجتمع بی خروج است.
 *
 * «دستگاه» همان قراردادِ `SessionContextTest` است: کوکیِ سنجاقِ خودش، حسابِ تازه از پایگاه‌داده در هر درخواست.
 */
class MultiCommunityContextTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret-pass-13';

    private Complex $a;

    private Complex $b;

    private Unit $unitA;

    private Unit $unitB;

    /** مدیرِ الف و مالکِ ب؛ پیش‌فرضِ حساب الف */
    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Complex::factory()->create(['name' => 'مجتمع مسکونی آفتاب', 'type' => ComplexType::Residential]);
        $this->b = Complex::factory()->create(['name' => 'مجتمع تجاری نفیس', 'type' => ComplexType::Commercial]);
        $this->unitA = Unit::factory()->create(['complex_id' => $this->a->id, 'unit_number' => 'الف-۱']);
        $this->unitB = Unit::factory()->create(['complex_id' => $this->b->id, 'unit_number' => 'ب-۳']);

        $this->sara = User::factory()->create([
            'complex_id' => $this->a->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true,
            'phone' => '09127771301', 'password' => self::PASSWORD, 'first_name' => 'سارا', 'last_name' => 'دوعضوی',
        ]);
        app(MembershipLedger::class)->start($this->sara, $this->b, UserRole::Owner, ComplexMembership::SOURCE_DIRECT, activate: false);
        app(TenureService::class)->open($this->unitB, $this->sara, ResidentRelation::Owner, 100, now()->subYear());

        SystemSettings::set('otp_disabled', true);
    }

    /* ── ورود و انتخاب (بندهای ۳۲ تا ۳۵؛ ۵.۵؛ آزمون‌های ۱ تا ۷ ِ بندِ ۵.۱۹) ───────────────────────── */

    public function test_a_single_community_account_goes_straight_into_its_community(): void
    {
        $solo = User::factory()->create(['complex_id' => $this->b->id, 'role' => UserRole::Tenant, 'is_active' => true, 'phone' => '09127771302', 'password' => self::PASSWORD]);

        $login = $this->signIn($solo)->assertOk();

        $this->assertSame($solo->id.':'.$this->b->id, $this->pinOf($login));
        $this->assertSame(['unit', $this->b->id], [$login->json('user.dashboard'), $login->json('user.complex.id')]);
    }

    public function test_a_multi_community_account_lands_on_the_selection_page_every_time(): void
    {
        $login = $this->signIn($this->sara)->assertOk();

        $this->assertSame($this->sara->id.':choose', $this->pinOf($login));
        $this->assertSame(['select', null, 'initial', false], [
            $login->json('user.dashboard'), $login->json('user.complex'), $login->json('user.accountState'), $login->json('user.canWrite'),
        ]);
        // فهرستِ درست: هر دو، با نام، نوع و نقش؛ هیچ‌کدام «فعلی» نیست
        $this->assertSame(
            [[$this->a->id, 'مجتمع مسکونی آفتاب', 'مسکونی', 'complex_admin', false], [$this->b->id, 'مجتمع تجاری نفیس', 'تجاری', 'owner', false]],
            collect($login->json('user.memberships'))->map(fn ($m) => [$m['complexId'], $m['complexName'], $m['complexTypeLabel'], $m['role'], $m['current']])->all(),
        );

        // انتخابِ «ب» ← مالک در «ب»
        $chosen = $this->device($this->sara, $this->pinOf($login))->postJson('/api/v1/account/context', ['complex_id' => $this->b->id])->assertOk();
        $this->assertSame($this->sara->id.':'.$this->b->id, $this->pinOf($chosen));
        $this->device($this->sara, $this->pinOf($chosen))->getJson('/api/v1/me')
            ->assertJsonPath('user.dashboard', 'unit')->assertJsonPath('user.complex.name', 'مجتمع تجاری نفیس');

        // ورودِ بعدی دوباره صفحه‌ی انتخاب است، نه «ب» ِ دفعه‌ی پیش
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $again = $this->signIn($this->sara)->assertOk();
        $this->assertSame($this->sara->id.':choose', $this->pinOf($again));
        $this->assertSame('select', $again->json('user.dashboard'));
    }

    /** 🔴 بندِ ۳۵: صفحه را می‌بندد و برمی‌گردد — هیچ مجتمعی خودکار انتخاب نشده و هیچ داده‌ای از هیچ‌کدام نمی‌رسد. */
    public function test_leaving_the_selection_page_selects_nothing_and_shows_no_community_data(): void
    {
        $choosing = $this->sara->id.':choose';
        $billA = Bill::factory()->create(['complex_id' => $this->a->id, 'unit_id' => $this->unitA->id, 'period' => Jalali::currentPeriod()]);
        $billB = Bill::factory()->create(['complex_id' => $this->b->id, 'unit_id' => $this->unitB->id, 'period' => Jalali::currentPeriod()]);

        $me = $this->device($this->sara, $choosing)->getJson('/api/v1/me')->assertOk();
        $me->assertJsonPath('user.dashboard', 'select')->assertJsonPath('user.complex', null)
            ->assertHeader(SetCurrentComplex::CONTEXT_HEADER, '0:choose')
            ->assertCookieMissing(SessionContext::COOKIE);

        // نه مدیرِ الف است و نه مالکِ ب
        $this->device($this->sara, $choosing)->getJson('/api/v1/units')->assertForbidden();
        $this->device($this->sara, $choosing)->getJson('/api/v1/residents')->assertForbidden();
        $this->assertSame([], $this->device($this->sara, $choosing)->getJson('/api/v1/my-bills')->json('data') ?? []);
        foreach ([$billA, $billB] as $bill) {
            $this->assertContains($this->device($this->sara, $choosing)->getJson("/api/v1/my-bills/{$bill->id}")->status(), [403, 404]);
        }
        $this->assertContains($this->device($this->sara, $choosing)->getJson("/api/v1/units/{$this->unitA->id}")->status(), [403, 404]);

        // پیش‌فرضِ حساب همان است که بود
        $this->assertSame([$this->a->id, UserRole::ComplexAdmin], [$this->sara->fresh()->complex_id, $this->sara->fresh()->role]);
    }

    /** 🔴 بندِ ۵.۵ و ۳۵: تا مجتمعی برگزیده نشده، فقط گزینش و خروج — نه درخواستِ عضویت، نه «ایجادِ حسابِ جدید». */
    public function test_the_selection_page_accepts_only_choosing_and_signing_out(): void
    {
        $choosing = $this->sara->id.':choose';
        $managerC = $this->otherManager();

        foreach ([
            ['post', '/api/v1/account/context/initial', []],
            ['post', '/api/v1/join-requests', ['phone' => $managerC->phone]],
            ['put', '/api/v1/profile', ['first_name' => 'دیگر']],
            // خریدِ مجتمع از مسیرِ وب هم — همان قفل روی همان گروهِ میان‌افزار
            ['post', '/subscription/checkout', ['purpose' => 'new_complex', 'plan' => 'x', 'complex_name' => 'ج', 'complex_type' => 'residential']],
        ] as [$method, $url, $body]) {
            $this->device($this->sara, $choosing)->{$method.'Json'}($url, $body)
                ->assertForbidden()->assertJsonPath('code', 'account.choose_community');
        }
        $this->assertSame(0, ComplexInvitation::withoutGlobalScopes()->where('user_id', $this->sara->id)->count());
        $this->assertSame(0, CheckoutIntent::count());
        $this->assertSame('سارا', $this->sara->fresh()->first_name);

        // گزینشِ مجتمعِ بیگانه رد؛ گزینشِ مجتمعِ خودش پذیرفته
        $stranger = Complex::factory()->create();
        $this->device($this->sara, $choosing)->postJson('/api/v1/account/context', ['complex_id' => $stranger->id])
            ->assertStatus(422)->assertJsonPath('code', 'membership.not_member');
        $this->device($this->sara, $choosing)->postJson('/api/v1/account/context', ['complex_id' => $this->a->id])
            ->assertOk()->assertJsonPath('current', $this->a->id)->assertJsonPath('mode', null);

        // خروج همیشه باز است
        $this->device($this->sara, $choosing)->postJson('/api/v1/logout')->assertOk();
    }

    /** بندِ ۳۲: وقتی از دو عضویتِ معتبر یکی ماند (تعلیقِ دیگری)، نه ورود و نه سنجاقِ کهنه صفحه‌ی یک‌کارتی نمی‌سازد. */
    public function test_with_only_one_valid_community_there_is_nothing_to_choose(): void
    {
        app(MembershipLedger::class)->suspend($this->sara, $this->b->id);

        $this->device($this->sara, $this->sara->id.':choose')->getJson('/api/v1/me')
            ->assertJsonPath('user.dashboard', 'manager')->assertJsonPath('user.complex.id', $this->a->id)
            ->assertHeader(SetCurrentComplex::CONTEXT_HEADER, $this->a->id.':complex_admin');

        $login = $this->signIn($this->sara)->assertOk();
        $this->assertSame($this->sara->id.':'.$this->a->id, $this->pinOf($login));
        $this->assertSame('manager', $login->json('user.dashboard'));
    }

    /* ── «ایجادِ حسابِ جدید» و داشبوردِ اولیه (بندهای ۵.۶ تا ۵.۹؛ آزمون‌های ۸ تا ۱۴) ────────────────── */

    public function test_create_new_account_opens_the_initial_dashboard_with_the_same_account(): void
    {
        $identity = fn () => User::withoutGlobalScopes()->findOrFail($this->sara->id)->only(['id', 'phone', 'first_name', 'last_name', 'name', 'complex_id', 'role']);
        $before = $identity();
        $usersBefore = User::count();

        $enter = $this->device($this->sara, $this->sara->id.':'.$this->a->id)->postJson('/api/v1/account/context/initial')->assertOk();
        $enter->assertJsonPath('current', null)->assertJsonPath('mode', 'initial')->assertJsonCount(2, 'memberships');
        $pin = $this->pinOf($enter);
        $this->assertSame($this->sara->id.':initial', $pin);

        $me = $this->device($this->sara, $pin)->getJson('/api/v1/me')->assertOk();
        $this->assertSame(['initial', 'initial', null, $this->sara->id, '09127771301', 'سارا دوعضوی'], [
            $me->json('user.dashboard'), $me->json('user.accountState'), $me->json('user.complex'),
            $me->json('user.id'), $me->json('user.phone'), $me->json('user.name'),
        ]);
        $this->assertSame([false, false], collect($me->json('user.memberships'))->pluck('current')->all(), 'مجتمع‌های قبلی همه برای بازگشت');
        $me->assertHeader(SetCurrentComplex::CONTEXT_HEADER, '0:initial');

        // همان حساب، همان پیش‌فرض، هیچ حسابِ تازه — و هیچ داده‌ی الف یا ب در این زمینه
        $this->assertSame($before, $identity());
        $this->assertSame($usersBefore, User::count());
        $this->device($this->sara, $pin)->getJson('/api/v1/units')->assertForbidden();
        $this->device($this->sara, $pin)->getJson('/api/v1/residents')->assertForbidden();
        $this->assertContains($this->device($this->sara, $pin)->getJson("/api/v1/units/{$this->unitA->id}")->status(), [403, 404]);
    }

    /** 🔴 بندِ ۵.۸: بازگشت از داشبوردِ اولیه به هر مجتمعِ قبلی بی خروج — و نقشِ همان مجتمع. */
    public function test_the_way_back_from_the_initial_dashboard_needs_no_sign_out(): void
    {
        $initial = $this->sara->id.':initial';

        $back = $this->device($this->sara, $initial)->postJson('/api/v1/account/context', ['complex_id' => $this->b->id])->assertOk();
        $this->assertSame($this->sara->id.':'.$this->b->id, $this->pinOf($back));
        $this->device($this->sara, $this->pinOf($back))->getJson('/api/v1/me')
            ->assertJsonPath('user.dashboard', 'unit')->assertJsonPath('user.role', 'owner');

        $this->device($this->sara, $initial)->postJson('/api/v1/account/context', ['complex_id' => $this->a->id])->assertOk();
        $this->device($this->sara, $this->sara->id.':'.$this->a->id)->getJson('/api/v1/residents')->assertOk();

        // و «ایجادِ حسابِ جدید» بعد از یک بار استفاده هم سرِ جایش است
        $this->device($this->sara, $this->sara->id.':'.$this->a->id)->postJson('/api/v1/account/context/initial')->assertOk();
    }

    /** 🔴 بندهای ۵.۱۲ و ۵.۱۴: درخواستِ عضویت از داشبوردِ اولیه — تا تأییدِ مدیر هیچ دسترسی، پس از آن مجتمعِ تازه در فهرست. */
    public function test_a_join_request_from_the_initial_dashboard_grants_nothing_until_approved(): void
    {
        $initial = $this->sara->id.':initial';
        $managerC = $this->otherManager();
        $c = $managerC->complex;
        $unitC = Unit::factory()->create(['complex_id' => $c->id, 'unit_number' => 'ج-۷']);

        $this->device($this->sara, $initial)->postJson('/api/v1/join-requests', ['phone' => $managerC->phone])->assertSuccessful();
        $request = ComplexInvitation::withoutGlobalScopes()->where('user_id', $this->sara->id)->sole();
        $this->assertSame([ComplexInvitation::REQUEST, ComplexInvitation::PENDING, $c->id], [$request->direction, $request->status, $request->complex_id]);

        // پیش از تأیید: نه عضویت، نه تعویض، نه داده
        $this->assertFalse(ComplexMembership::withoutGlobalScopes()->active()->where('user_id', $this->sara->id)->where('complex_id', $c->id)->exists());
        $this->device($this->sara, $initial)->postJson('/api/v1/account/context', ['complex_id' => $c->id])->assertStatus(422);
        $this->assertContains($this->device($this->sara, $initial)->getJson("/api/v1/units/{$unitC->id}")->status(), [403, 404]);

        // مدیرِ ج تأیید می‌کند: سارا در داشبوردِ اولیه می‌ماند (کسِ دیگری زمینه‌اش را جابه‌جا نمی‌کند) و «ج» در فهرست است
        $this->device($managerC, null)->postJson("/api/v1/join-requests/{$request->id}/approve", ['role' => 'owner', 'unit_id' => $unitC->id])->assertOk();
        $me = $this->device($this->sara, $initial)->getJson('/api/v1/me')->assertJsonPath('user.dashboard', 'initial');
        $this->assertContains($c->id, collect($me->json('user.memberships'))->pluck('complexId')->all());
        $this->assertSame($this->a->id, $this->sara->fresh()->complex_id, 'پیش‌فرضِ حساب جابه‌جا نشد.');

        $this->device($this->sara, $initial)->postJson('/api/v1/account/context', ['complex_id' => $c->id])->assertOk();
        $this->device($this->sara, $this->sara->id.':'.$c->id)->getJson('/api/v1/me')->assertJsonPath('user.complex.id', $c->id);
    }

    /** 🔴 بندِ ۵.۱۱: خریدِ مجتمع از داشبوردِ اولیه — شکست هیچ مجتمعی نمی‌سازد؛ موفقیت پس از تأییدِ درگاه، و بازگشت به آن. */
    public function test_a_purchase_from_the_initial_dashboard_builds_a_community_only_after_payment(): void
    {
        config(['subscription.gateway' => 'zarinpal', 'subscription.config.merchant_id' => 'test-merchant-id', 'subscription.config.sandbox' => false]);
        Plan::create(['name' => 'پایه', 'slug' => 'res-13-3', 'complex_type' => ComplexType::Residential, 'price' => 250000, 'months' => 3, 'is_active' => true]);
        [$failing, $paying] = ['A00000000000000000000000000000013301', 'A00000000000000000000000000000013302'];
        Http::fake([
            // هر درخواست authority ِ خودش را می‌گیرد، مثلِ درگاهِ واقعی
            'https://payment.zarinpal.com/pg/v4/payment/request.json' => Http::sequence()
                ->push(['data' => ['code' => 100, 'authority' => $failing], 'errors' => []])
                ->push(['data' => ['code' => 100, 'authority' => $paying], 'errors' => []]),
            // بازگشتِ `NOK` اصلاً به تأیید نمی‌رسد؛ تنها تأیید مالِ پرداختِ دوم است
            'https://payment.zarinpal.com/pg/v4/payment/verify.json' => Http::response(['data' => ['code' => 100, 'ref_id' => 13303]]),
        ]);
        $initial = $this->sara->id.':initial';
        $buy = fn () => $this->device($this->sara, $initial)->post('/subscription/checkout', [
            'plan' => 'res-13-3', 'purpose' => 'new_complex', 'complex_name' => 'مجتمع تازه‌ی سارا', 'complex_type' => 'residential',
        ]);

        // شکستِ پرداخت ← هیچ مجتمع، هیچ عضویت
        $buy()->assertRedirect('https://payment.zarinpal.com/pg/StartPay/'.$failing);
        $this->bank(CheckoutIntent::sole(), $failing, 'NOK');
        $this->assertSame(0, Complex::where('name', 'مجتمع تازه‌ی سارا')->count());
        $this->assertSame(2, ComplexMembership::withoutGlobalScopes()->active()->where('user_id', $this->sara->id)->count());

        // پرداختِ موفق ← مجتمع و مدیریتِ آن؛ مجتمع‌های قبلی و پیش‌فرض دست‌نخورده
        $buy()->assertRedirect('https://payment.zarinpal.com/pg/StartPay/'.$paying);
        $intent = CheckoutIntent::where('status', CheckoutIntent::PENDING)->sole();
        $this->bank($intent, $paying)->assertRedirect('/dashboard?checkout=success&tracking=13303&complex='.(int) $intent->fresh()->complex_id);
        $new = Complex::where('name', 'مجتمع تازه‌ی سارا')->sole();
        $this->assertSame(3, ComplexMembership::withoutGlobalScopes()->active()->where('user_id', $this->sara->id)->count());
        $this->assertSame($this->a->id, $this->sara->fresh()->complex_id);

        // از همان داشبوردِ اولیه به مجتمعِ تازه: مدیرِ آن، و «الف» و «ب» در فهرستِ دیگران
        $this->device($this->sara, $initial)->postJson('/api/v1/account/context', ['complex_id' => $new->id])->assertOk();
        $me = $this->device($this->sara, $this->sara->id.':'.$new->id)->getJson('/api/v1/me')->assertJsonPath('user.dashboard', 'manager');
        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id, $new->id], collect($me->json('user.memberships'))->pluck('complexId')->all());
    }

    public function test_the_super_admin_has_no_initial_dashboard_to_enter(): void
    {
        $super = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true]);

        $this->device($super, null)->postJson('/api/v1/account/context/initial')
            ->assertStatus(422)->assertJsonPath('code', 'membership.platform_account');
        $this->device($super, $super->id.':choose')->getJson('/api/v1/me')->assertJsonPath('user.dashboard', 'platform');
    }

    public function test_a_real_initial_account_is_unchanged(): void
    {
        $fresh = User::factory()->create(['complex_id' => null, 'role' => UserRole::Owner, 'is_active' => true, 'phone' => '09127771309', 'password' => self::PASSWORD]);

        $login = $this->signIn($fresh)->assertOk();
        $this->assertNull($this->pinOf($login));
        $this->device($fresh, null)->getJson('/api/v1/me')->assertJsonPath('user.dashboard', 'initial')
            ->assertHeaderMissing(SetCurrentComplex::CONTEXT_HEADER);
        // سنجاقِ «انتخاب» برای حسابِ بی‌عضویت معنایی ندارد
        $this->device($fresh, $fresh->id.':choose')->getJson('/api/v1/me')->assertJsonPath('user.dashboard', 'initial');
        $this->device($fresh, null)->postJson('/api/v1/account/context/initial')->assertForbidden();
    }

    /* ── نفوذ (بندِ ۱۰۴: شناسه، URL، query، body، API) ─────────────────────────────────────────── */

    public function test_forged_or_foreign_detached_pins_grant_nothing(): void
    {
        $solo = User::factory()->create(['complex_id' => $this->b->id, 'role' => UserRole::Tenant, 'is_active' => true, 'phone' => '09127771303']);

        // سنجاقِ حسابِ دیگر، و شکل‌های نامعتبر ← پیش‌فرض
        foreach ([$solo->id.':initial', $solo->id.':choose', $this->sara->id.':initial2', $this->sara->id.':CHOOSE', $this->sara->id.':0'] as $pin) {
            $this->device($this->sara, $pin)->getJson('/api/v1/me')
                ->assertJsonPath('user.complex.id', $this->a->id)->assertJsonPath('user.dashboard', 'manager');
        }

        // شناسه‌ی مجتمع در query، سربرگ یا body در زمینه‌ی بی‌مجتمع هیچ دری باز نمی‌کند
        foreach ([$this->sara->id.':initial', $this->sara->id.':choose'] as $pin) {
            $this->device($this->sara, $pin)->withHeaders(['X-Complex-Id' => (string) $this->a->id])
                ->getJson("/api/v1/units?complex_id={$this->a->id}")->assertForbidden();
            $this->assertContains($this->device($this->sara, $pin)->getJson("/api/v1/units/{$this->unitA->id}?complex_id={$this->a->id}")->status(), [403, 404]);
            $this->device($this->sara, $pin)->postJson('/api/v1/account/context', ['complex_id' => 'initial'])->assertStatus(422);
        }

        // حالتِ بی‌مجتمع فقط از دو مسیرِ خودِ کاربر — ورود و «ایجادِ حسابِ جدید» — نه از body ِ تعویض
        $this->device($this->sara, null)->postJson('/api/v1/account/context', ['complex_id' => $this->a->id, 'mode' => 'initial'])->assertOk();
        $this->device($this->sara, $this->sara->id.':'.$this->a->id)->getJson('/api/v1/me')->assertJsonPath('user.dashboard', 'manager');
    }

    /** 🔴 همان قاعده‌ی CI ِ `69a7d21`: هیچ درخواستِ عادی‌ای در دو زمینه‌ی بی‌مجتمع سنجاق نمی‌نویسد. */
    public function test_ordinary_requests_in_detached_contexts_never_write_the_pin(): void
    {
        foreach ([$this->sara->id.':initial', $this->sara->id.':choose'] as $pin) {
            foreach (['/api/v1/me', '/api/v1/notifications', '/api/v1/wallet', '/api/v1/account/memberships'] as $url) {
                $this->device($this->sara, $pin)->getJson($url)->assertOk()->assertCookieMissing(SessionContext::COOKIE);
            }
        }
    }

    /* ── ابزار ──────────────────────────────────────────────────────────────────────────────────── */

    private function signIn(User $user): TestResponse
    {
        app(TenantContext::class)->set(null);
        $this->defaultCookies = [];

        return $this->postJson('/api/v1/login', ['phone' => $user->phone, 'password' => self::PASSWORD]);
    }

    /** مرورگری با کوکیِ سنجاقِ خودش (همان قراردادِ `SessionContextTest::device`)، با سنجاقِ خام */
    private function device(User $user, ?string $pin): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();
        $this->defaultCookies = [];
        $this->withCredentials();
        if ($pin !== null) {
            $this->withCookie(SessionContext::COOKIE, $pin);
        }

        return $this->actingAs(User::withoutGlobalScopes()->findOrFail($user->id));
    }

    private function pinOf(TestResponse $response): ?string
    {
        $value = $response->getCookie(SessionContext::COOKIE)?->getValue();

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function otherManager(): User
    {
        $c = Complex::factory()->create(['name' => 'مجتمع جدید جم']);

        return User::factory()->create(['complex_id' => $c->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09127771390']);
    }

    private function bank(CheckoutIntent $intent, string $authority, string $status = 'OK'): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->get("/checkout/callback/{$intent->public_id}?Authority={$authority}&Status={$status}");
    }
}
