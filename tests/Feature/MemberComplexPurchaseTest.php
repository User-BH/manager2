<?php

namespace Tests\Feature;

use App\Enums\ComplexType;
use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\CheckoutIntent;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\ComplexMembership;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Account\MembershipLedger;
use App\Services\Payment\TrackingCodes;
use App\Services\Subscription\ComplexPurchase;
use App\Services\Units\TenureService;
use App\Services\Wallet\WalletService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * خریدِ مجتمعِ تازه با حسابی که از قبل عضو است (مرحلهٔ ۱۰ · فاز ۵؛ تصمیمِ کارفرما).
 *
 *   A → مالک · B → مستأجر · C → مدیر، پس از خریدِ معتبر
 *
 * پیش از این تصمیم فقط حسابِ اولیه مجتمع می‌خرید (سه نگهبانِ `account.already_member`). هر آزمون یکی از
 * ده الزامِ تصمیم را می‌سنجد: بی حسابِ تازه و بی خروج از عضویت‌ها؛ A و B دست‌نخورده (عضویت، نقش، داده، کیف،
 * تاریخچه)؛ ساختن فقط پس از تأییدِ سرور؛ اتمی و مقاوم در برابرِ بازگشتِ تکراری؛ زمینه‌های جدا؛ مدیر بودن در C
 * هیچ دسترسیِ تازه‌ای در A و B نمی‌دهد؛ و قاعده‌های پلن سرِ جایشان.
 */
class MemberComplexPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private const AUTHORITY = 'A00000000000000000000000000000054321';

    private Complex $a;

    private Complex $b;

    private Unit $unitA;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'subscription.gateway' => 'zarinpal',
            'subscription.config.merchant_id' => 'test-merchant-id',
            'subscription.config.sandbox' => false,
        ]);
        Plan::create([
            'name' => 'پلنِ آزمون', 'slug' => 'res-test', 'complex_type' => ComplexType::Residential,
            'price' => 250000, 'months' => 3, 'is_active' => true,
        ]);
        Plan::create([
            'name' => 'پلنِ تجاری', 'slug' => 'com-test', 'complex_type' => ComplexType::Commercial,
            'price' => 400000, 'months' => 3, 'is_active' => true,
        ]);

        $this->a = Complex::factory()->create(['name' => 'مجتمع الف']);
        $this->b = Complex::factory()->create(['name' => 'مجتمع ب']);
        $this->unitA = Unit::factory()->create(['complex_id' => $this->a->id, 'unit_number' => '3']);

        // مالکِ A (با واحد، دوره و کیف) و مستأجرِ B؛ زمینه‌ی فعال A
        $this->member = User::factory()->create([
            'complex_id' => $this->b->id, 'role' => UserRole::Tenant, 'phone' => '09125550303', 'is_active' => true,
        ]);
        app(MembershipLedger::class)->start($this->member, $this->a, UserRole::Owner, ComplexMembership::SOURCE_DIRECT);
        app(TenureService::class)->open($this->unitA, $this->member->fresh(), ResidentRelation::Owner);
        app(WalletService::class)->credit($this->member->fresh(), $this->unitA, 120000, WalletTransaction::SOURCE_ADJUSTMENT, null, 'اعتبارِ آزمون');
        $this->member->refresh();
    }

    public function test_a_member_of_two_complexes_buys_a_third_and_nothing_else_moves(): void
    {
        $this->fakeZarinpal();
        $before = $this->snapshot();

        // بی حسابِ تازه و بی خروج از عضویت‌ها؛ پیش از پرداخت فقط قصد
        $this->start()->assertRedirect('https://payment.zarinpal.com/pg/StartPay/'.self::AUTHORITY);
        $this->assertSame(2, Complex::count()); // فقط A و B — هنوز هیچ مجتمعی ساخته نشده
        $this->assertSame($before, $this->snapshot());

        $intent = CheckoutIntent::sole();
        $this->bank($intent)->assertRedirect($this->successUrl($intent));

        $c = Complex::where('name', 'مجتمع ج')->sole();
        $this->assertSame([CheckoutIntent::COMPLETED, $c->id], [$intent->fresh()->status, $intent->fresh()->complex_id]);
        $this->assertSame(1, Subscription::where('complex_id', $c->id)->where('status', 'active')->count());

        $managed = ComplexMembership::withoutGlobalScopes()->where('user_id', $this->member->id)->where('complex_id', $c->id)->sole();
        $this->assertSame([UserRole::ComplexAdmin, ComplexMembership::SOURCE_PURCHASE, ComplexMembership::ACTIVE], [
            $managed->role, $managed->source, $managed->status,
        ]);

        // عضویت‌ها، نقش‌ها، واحد، دوره، کیف و زمینه‌ی A و B: بایت‌به‌بایت همان
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], app(MembershipLedger::class)->violations());
    }

    public function test_the_buyer_switches_to_the_new_complex_and_its_manager_role_stays_there(): void
    {
        $this->fakeZarinpal();
        $this->start();
        $this->bank(CheckoutIntent::sole());
        $c = Complex::where('name', 'مجتمع ج')->sole();

        // پس از خرید هنوز در A (مالک): هیچ مسیرِ مدیریتی
        $this->as($this->member)->getJson('/api/v1/residents')->assertForbidden();
        $me = $this->as($this->member)->getJson('/api/v1/me')->json('user');
        $this->assertSame($this->a->id, $me['complex']['id']);
        $this->assertSame(
            [[$this->a->id, 'owner', true], [$this->b->id, 'tenant', false], [$c->id, 'complex_admin', false]],
            collect($me['memberships'])->sortBy('complexId')->map(fn ($m) => [$m['complexId'], $m['role'], $m['current']])->values()->all(),
        );

        // خودش به C می‌رود: مدیر، فقط با داده‌ی C
        $this->as($this->member)->postJson('/api/v1/account/context', ['complex_id' => $c->id])->assertOk();
        $this->as($this->member->fresh())->getJson('/api/v1/residents')->assertOk();
        $units = $this->as($this->member->fresh())->getJson('/api/v1/units')->assertOk()->json('data');
        $this->assertNotContains($this->unitA->id, collect($units)->pluck('id')->all());

        // به B برمی‌گردد: مستأجر — مدیر بودن در C هیچ دسترسی‌ای در B نمی‌دهد
        $this->as($this->member->fresh())->postJson('/api/v1/account/context', ['complex_id' => $this->b->id])->assertOk();
        $this->as($this->member->fresh())->getJson('/api/v1/residents')->assertForbidden();
        $this->as($this->member->fresh())->getJson('/api/v1/join-requests')->assertForbidden();
        $this->as($this->member->fresh())->getJson('/api/v1/join-link')->assertForbidden();
    }

    public function test_repeated_callbacks_build_one_complex_one_subscription_one_membership(): void
    {
        $this->fakeZarinpal();
        $this->start();
        $intent = CheckoutIntent::sole();

        $this->bank($intent)->assertRedirect($this->successUrl($intent));
        $this->bank($intent)->assertRedirect($this->successUrl($intent));
        $this->bank($intent, 'NOK')->assertRedirect($this->successUrl($intent));

        $this->assertSame(1, Complex::where('name', 'مجتمع ج')->count());
        $this->assertSame(1, Subscription::count());
        $this->assertSame(1, ComplexMembership::withoutGlobalScopes()->where('source', ComplexMembership::SOURCE_PURCHASE)->count());
        Http::assertSentCount(2); // یک request و یک verify — بازگشتِ تکراری دوباره از درگاه نمی‌پرسد
    }

    /**
     * دو بازگشتی که هم‌زمان از نگهبانِ کنترلر («قصد هنوز باز است؟») رد شده‌اند، هر دو به سرویس می‌رسند؛
     * فقط بازخوانیِ زیرِ قفل در `complete` جلوی مجتمع و اشتراکِ دوم را می‌گیرد. (خرابکاریِ ۱۰/۵: حذفِ همان
     * بازخوانی از آزمونِ بازگشتِ تکراری زنده ماند، چون آن‌جا کنترلر زودتر برمی‌گرداند.)
     */
    public function test_completing_the_same_intent_twice_in_the_service_builds_once(): void
    {
        $this->fakeZarinpal();
        $this->start();
        $intent = CheckoutIntent::sole();
        $before = $this->snapshot();

        $purchase = app(ComplexPurchase::class);
        $first = TrackingCodes::exclusively('987654321', fn () => $purchase->complete($intent, '987654321'));
        $second = TrackingCodes::exclusively('987654321', fn () => $purchase->complete($intent, '987654321'));

        $this->assertSame([CheckoutIntent::COMPLETED, CheckoutIntent::COMPLETED], [$first->status, $second->status]);
        $this->assertSame($first->complex_id, $second->complex_id);
        $this->assertSame(1, Complex::where('name', 'مجتمع ج')->count());
        $this->assertSame(1, Subscription::count());
        $this->assertSame(1, ComplexMembership::withoutGlobalScopes()->where('source', ComplexMembership::SOURCE_PURCHASE)->count());
        $this->assertSame($before, $this->snapshot());
    }

    public function test_nothing_is_built_without_the_gateways_own_confirmation(): void
    {
        // «Status=OK» از مرورگر هست، ولی تأییدِ درگاه نه
        $this->fakeZarinpal(['data' => ['code' => -51], 'errors' => ['code' => -51]]);
        $before = $this->snapshot();
        $this->start();
        $intent = CheckoutIntent::sole();

        $this->bank($intent)->assertRedirect('/account?checkout=failed');

        $this->assertSame(CheckoutIntent::FAILED, $intent->fresh()->status);
        $this->assertSame(0, Complex::where('name', 'مجتمع ج')->count());
        $this->assertSame(0, Subscription::count());
        $this->assertSame($before, $this->snapshot());
    }

    public function test_the_client_cannot_name_the_complex_role_amount_or_buyer(): void
    {
        $this->fakeZarinpal();

        $this->start([
            'amount' => 1, 'price' => 1, 'months' => 99,
            'complex_id' => $this->a->id, 'role' => 'super_admin', 'user_id' => 1, 'unit_id' => $this->unitA->id,
        ]);
        $intent = CheckoutIntent::sole();
        $this->assertSame([250000, 3, $this->member->id], [$intent->amount, $intent->months, $intent->user_id]);

        $this->bank($intent);
        $c = Complex::where('name', 'مجتمع ج')->sole();
        $this->assertNotSame($this->a->id, $c->id);
        $this->assertSame(UserRole::Owner, ComplexMembership::withoutGlobalScopes()
            ->where('user_id', $this->member->id)->where('complex_id', $this->a->id)->sole()->role);
        $this->assertSame(0, User::where('role', UserRole::SuperAdmin->value)->count());
    }

    public function test_plan_rules_still_apply_to_members(): void
    {
        $this->fakeZarinpal();
        Plan::create([
            'name' => 'خاموش', 'slug' => 'off', 'complex_type' => ComplexType::Residential,
            'price' => 100000, 'months' => 1, 'is_active' => false,
        ]);

        // پلنِ نوعِ دیگر، پلنِ خاموش و پلنِ نامعلوم — هیچ‌کدام قصد نمی‌سازند
        foreach (['com-test', 'off', 'free', 'nope'] as $slug) {
            $this->start(['plan' => $slug])->assertRedirect();
        }
        $this->assertSame(0, CheckoutIntent::count());
    }

    public function test_one_open_intent_per_account_for_members_too(): void
    {
        $this->fakeZarinpal();

        $this->start();
        $this->start(['complex_name' => 'مجتمع د']);

        $this->assertSame(1, CheckoutIntent::where('status', CheckoutIntent::PENDING)->count());
        $this->assertSame(CheckoutIntent::CANCELLED, CheckoutIntent::orderBy('id')->first()->status);
    }

    public function test_renewal_is_still_the_current_complexes_managers_alone(): void
    {
        $this->fakeZarinpal();

        // بی `purpose`: تمدیدِ اشتراکِ A — مالکِ A مجاز نیست، و هیچ قصدِ خریدی ساخته نمی‌شود
        $this->actingAs($this->member)->post('/subscription/checkout', ['plan' => 'res-test'])->assertForbidden();
        $this->assertSame(0, CheckoutIntent::count());
        $this->assertSame(0, Subscription::count());
    }

    public function test_the_platform_account_and_a_deactivated_account_cannot_buy(): void
    {
        $this->fakeZarinpal();
        $super = User::factory()->create(['role' => UserRole::SuperAdmin, 'complex_id' => null, 'is_active' => true]);

        $this->actingAs($super)->post('/subscription/checkout', [
            'plan' => 'res-test', 'purpose' => 'new_complex', 'complex_name' => 'سکو', 'complex_type' => 'residential',
        ])->assertForbidden();

        // حسابِ غیرفعال پیش از کنترلر از نشست بیرون می‌شود (۳۰۲ به ورود) — به درگاه نمی‌رسد
        $this->member->forceFill(['is_active' => false])->save();
        $response = $this->start();
        $this->assertStringNotContainsString('zarinpal', (string) $response->headers->get('Location'));

        $this->assertSame(0, CheckoutIntent::count());
    }

    /**
     * رفعِ ۱۰/۵: تأییدِ مدیرِ B زمینه‌ی متقاضی‌ای را که در A کار می‌کند جابه‌جا نمی‌کند.
     *
     * پیش از این `MembershipLedger::start` همیشه حساب را به مجتمعِ تازه می‌برد — یعنی کارِ مدیرِ B، از دستگاهِ
     * دیگر، تب‌های بازِ متقاضی در A را بی‌خبر به زمینه‌ی B می‌برد و داده‌ی B زیرِ کلیدِ A در کشِ کلاینت می‌نشست.
     */
    public function test_a_managers_approval_does_not_move_a_member_working_elsewhere(): void
    {
        $c = Complex::factory()->create(['name' => 'مجتمع ج']);
        $unitC = Unit::factory()->create(['complex_id' => $c->id]);
        $managerC = User::factory()->create(['complex_id' => $c->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true]);
        $request = ComplexInvitation::create([
            'complex_id' => $c->id, 'user_id' => $this->member->id, 'phone' => $this->member->phone,
            'direction' => ComplexInvitation::REQUEST, 'status' => ComplexInvitation::PENDING, 'role' => UserRole::Owner,
        ]);

        $this->as($managerC)->postJson("/api/v1/join-requests/{$request->id}/approve", [
            'role' => 'tenant', 'unit_id' => $unitC->id,
        ])->assertOk();

        $this->assertSame([$this->a->id, UserRole::Owner], [$this->member->fresh()->complex_id, $this->member->fresh()->role]);
        $this->assertTrue(ComplexMembership::withoutGlobalScopes()->active()->where('user_id', $this->member->id)->where('complex_id', $c->id)->exists());
        $this->assertSame([], app(MembershipLedger::class)->violations());
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    /** @param  array<string, mixed>  $overrides */
    private function start(array $overrides = []): TestResponse
    {
        return $this->as($this->member)->post('/subscription/checkout', [
            'plan' => 'res-test',
            'purpose' => 'new_complex',
            'complex_name' => 'مجتمع ج',
            'complex_type' => 'residential',
            ...$overrides,
        ]);
    }

    private function bank(CheckoutIntent $intent, string $status = 'OK'): TestResponse
    {
        // بازگشت از بانک بی نشست — اعتبار با تأییدِ خودِ درگاه است
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->get("/checkout/callback/{$intent->public_id}?Authority=".self::AUTHORITY."&Status={$status}");
    }

    private function successUrl(CheckoutIntent $intent): string
    {
        return '/dashboard?checkout=success&tracking=987654321&complex='.(int) $intent->fresh()->complex_id;
    }

    /** @param  array<string, mixed>  $verify */
    private function fakeZarinpal(array $verify = ['data' => ['code' => 100, 'ref_id' => 987654321]]): void
    {
        Http::fake([
            // هر درخواست authority ِ خودش را می‌گیرد، مثلِ درگاهِ واقعی؛ اولی همان AUTHORITY
            'https://payment.zarinpal.com/pg/v4/payment/request.json' => Http::sequence()
                ->push(['data' => ['code' => 100, 'authority' => self::AUTHORITY], 'errors' => []])
                ->push(['data' => ['code' => 100, 'authority' => 'A00000000000000000000000000000054322'], 'errors' => []]),
            'https://payment.zarinpal.com/pg/v4/payment/verify.json' => Http::response($verify),
        ]);
    }

    /**
     * هر چه از حساب در A و B هست: عضویت‌ها (همه‌ی ستون‌ها)، زمینه و نقشِ حساب، دوره‌ها، کیف، و ردیفِ خودِ
     * دو مجتمع و اشتراک‌هایشان.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $rows = fn (string $table, string $column, array $ids) => DB::table($table)->whereIn($column, $ids)->orderBy('id')->get()
            ->map(fn ($r) => (array) $r)->all();
        $ab = [$this->a->id, $this->b->id];

        return [
            'account' => (array) DB::table('users')->where('id', $this->member->id)->first(['complex_id', 'role', 'is_active']),
            'memberships' => DB::table('complex_memberships')->where('user_id', $this->member->id)->whereIn('complex_id', $ab)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'tenures' => $rows('unit_user', 'user_id', [$this->member->id]),
            'wallet' => $rows('wallet_transactions', 'user_id', [$this->member->id]),
            'complexes' => $rows('complexes', 'id', $ab),
            'subscriptions' => $rows('subscriptions', 'complex_id', $ab),
            'units' => $rows('units', 'complex_id', $ab),
        ];
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user);
    }
}
