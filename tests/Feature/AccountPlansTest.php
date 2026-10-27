<?php

namespace Tests\Feature;

use App\Enums\ComplexType;
use App\Enums\PlanTier;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CheckoutIntent;
use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Account\MembershipLedger;
use App\Services\Subscription\SubscriptionRenewal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * «تنظیماتِ حساب، پلن‌ها، تمدید، پرداخت و تاریخچه» — مرحلهٔ ۱۳ · فاز ۶ (ایرادات ۱۰، بخشِ اول، بندهای ۱ تا ۲۹ و ۱۰۵).
 *
 * هر آزمون یک الزامِ همان بخش، از سمتِ سرور: چه کسی این بخش را دارد، کارت‌ها از کجا می‌آیند، قیمتِ هر مدت در لحظه،
 * تمدید در برابرِ تغییر، تأییدِ صریح، تاریخچه‌ی صفحه‌بندی‌شده، و مدیریتِ پلن با audit. درگاه `sandbox` است.
 */
class AccountPlansTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private Plan $basic;

    private Plan $pro;

    private Plan $advanced;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withConfirmedPassword();
        config(['subscription.gateway' => 'sandbox']);

        // سه پلنِ پیش‌فرضِ مهاجرت کنار می‌روند تا کارت‌ها دقیقاً همین سه باشند
        Plan::query()->update(['is_active' => false]);
        $this->basic = $this->plan('res-basic', PlanTier::Basic, ComplexType::Residential, [1 => 100_000, 3 => 280_000], unitLimit: 25);
        $this->pro = $this->plan('res-pro', PlanTier::Professional, ComplexType::Residential, [1 => 200_000, 3 => 550_000, 6 => 1_000_000]);
        $this->advanced = $this->plan('res-adv', PlanTier::Advanced, ComplexType::Residential, [1 => 400_000, 12 => 4_000_000], unitLimit: null, everything: true);

        $this->complex = Complex::factory()->create(['type' => ComplexType::Residential, 'name' => 'مجتمع آفتاب']);
        $this->manager = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09125558001']);
    }

    /* ── بندهای ۲ و ۵: چه کسی «تنظیماتِ حساب» و خرید دارد ─────────────── */

    public function test_only_the_manager_and_the_initial_account_have_account_settings(): void
    {
        $owner = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::Owner, 'is_active' => true]);
        $super = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true]);
        $initial = User::factory()->create(['complex_id' => null, 'role' => UserRole::Owner, 'is_active' => true]);

        foreach (['/api/v1/subscription', '/api/v1/subscription/history'] as $path) {
            $this->actingAs($owner)->getJson($path)->assertForbidden();
            $this->actingAs($super)->getJson($path)->assertForbidden();
            $this->actingAs($this->manager)->getJson($path)->assertOk();
            $this->actingAs($initial)->getJson($path)->assertOk();
        }

        $this->assertSame('manager', $this->actingAs($this->manager)->getJson('/api/v1/subscription')->json('account'));
        $this->assertSame('initial', $this->actingAs($initial)->getJson('/api/v1/subscription')->json('account'));
    }

    /** بندِ ۵ و ۱۰۵: سوپر ادمین — حتی با مجتمعِ برگزیده — هیچ پرداختی نمی‌سازد؛ واحد هم نه */
    public function test_the_super_admin_and_a_unit_member_have_no_purchase_path(): void
    {
        $super = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::SuperAdmin, 'is_active' => true]);
        $owner = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::Owner, 'is_active' => true]);

        $this->actingAs($super)->post('/subscription/checkout', ['plan' => 'res-pro', 'months' => 1])->assertForbidden();
        $this->actingAs($super)->post('/subscription/checkout', [
            'plan' => 'res-pro', 'months' => 1, 'purpose' => 'new_complex', 'complex_name' => 'x', 'complex_type' => 'residential',
        ])->assertForbidden();
        $this->actingAs($owner)->post('/subscription/checkout', ['plan' => 'res-pro', 'months' => 1])->assertForbidden();

        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, CheckoutIntent::count());
    }

    /* ── بندهای ۹ تا ۱۶: کارت‌ها ───────────────────────────────────────── */

    public function test_the_manager_sees_three_cards_of_their_own_type_with_only_priced_durations(): void
    {
        $data = $this->actingAs($this->manager)->getJson('/api/v1/subscription')->assertOk()->json();

        $this->assertSame(['residential'], array_keys($data['catalog']), 'مدیر کارتِ نوعِ دیگر دید.');
        $cards = $data['catalog']['residential'];
        $this->assertSame(['basic', 'professional', 'advanced'], array_column($cards, 'tier'));
        $this->assertSame(['پایه', 'حرفه‌ای', 'پیشرفته'], array_column($cards, 'tierLabel'));
        $this->assertSame([1, 3, 6, 12, 18, 24], $data['durations']);

        // فقط مدت‌هایی که قیمت دارند؛ قیمت از جدول
        $this->assertSame([1, 3, 6], array_column($cards[1]['prices'], 'months'));
        $this->assertSame([200_000, 550_000, 1_000_000], array_column($cards[1]['prices'], 'price'));

        // «همه‌ی امکانات بدون محدودیت» فقط وقتی واقعاً بی‌سقف (بندِ ۱۵)
        $this->assertSame([false, false, true], array_column($cards, 'includesEverything'));
        $this->assertContains('تا ۲۵ واحد', $cards[0]['capabilities']);
        $this->assertContains('واحدِ نامحدود', $cards[2]['capabilities']);
        $this->assertNotEmpty($data['baseFeatures']);
    }

    /** حسابِ اولیه هر دو نوع را می‌بیند (بندِ ۹) و «تمدیدِ پلنِ فعلی» ندارد (بندِ ۳) */
    public function test_the_initial_account_chooses_the_type_and_has_no_renewal(): void
    {
        $this->plan('com-basic', PlanTier::Basic, ComplexType::Commercial, [1 => 150_000]);
        $initial = User::factory()->create(['complex_id' => null, 'role' => UserRole::Owner, 'is_active' => true]);

        $data = $this->actingAs($initial)->getJson('/api/v1/subscription')->assertOk()->json();

        $this->assertSame(['residential', 'commercial'], array_keys($data['catalog']));
        $this->assertSame(['com-basic'], array_column($data['catalog']['commercial'], 'slug'));
        $this->assertNull($data['current']);
        $this->assertNull($data['renewal']);
    }

    /**
     * 🔴 پلنِ مخصوصِ یک نوع جای پلنِ «هر دو نوع» ِ همان سطح را می‌گیرد — و پلنِ سایه‌خورده با slug ِ مستقیم هم خریدنی
     * نیست؛ وگرنه مدیرِ تجاری تعرفه‌ی ارزان‌ترِ مشترک را دور می‌زد (R67).
     */
    public function test_a_typed_plan_shadows_the_shared_one_and_the_shadowed_slug_is_not_purchasable(): void
    {
        $shared = $this->plan('shared-pro', PlanTier::Professional, null, [1 => 50_000]);
        $commercial = Complex::factory()->create(['type' => ComplexType::Commercial]);
        $manager = User::factory()->create(['complex_id' => $commercial->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true]);

        $cards = $this->actingAs($manager)->getJson('/api/v1/subscription')->json('catalog.commercial');
        $this->assertSame(['shared-pro'], array_column($cards, 'slug'), 'تا پلنِ تجاری نیست، همان مشترک');

        $this->plan('com-pro', PlanTier::Professional, ComplexType::Commercial, [1 => 300_000]);
        $cards = $this->actingAs($manager)->getJson('/api/v1/subscription')->json('catalog.commercial');
        $this->assertSame(['com-pro'], array_column($cards, 'slug'));

        $this->actingAs($manager)->post('/subscription/checkout', ['plan' => $shared->slug, 'months' => 1])
            ->assertRedirectContains('checkout=error');
        $this->assertSame(0, Subscription::count());
    }

    /* ── بندهای ۷، ۸ و ۲۵: قیمت از سرور و در همان لحظه ──────────────────── */

    public function test_a_new_super_admin_price_is_used_at_the_very_next_renewal_and_the_client_amount_is_ignored(): void
    {
        $this->activate($this->pro, 3);
        $super = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true]);

        $this->actingAs($super)->putJson("/api/v1/system/plans/{$this->pro->id}", $this->payload($this->pro, [1 => 200_000, 3 => 777_000, 6 => 1_000_000]))
            ->assertOk();
        $this->assertSame(777_000, $this->card('res-pro')['prices'][1]['price'], 'کارت قیمتِ کهنه را نشان داد.');

        $pending = $this->checkout(['plan' => 'res-pro', 'months' => 3, 'amount' => 1, 'price' => 1]);
        $this->assertSame(777_000, (int) $pending->amount);

        // و تغییرِ قیمت در audit با قبل و بعد
        $log = AuditLog::where('action', 'plan.updated')->sole();
        $this->assertSame([550_000, true], $log->properties['before']['prices'][3]);
        $this->assertSame([777_000, true], $log->properties['after']['prices'][3]);
    }

    /** مدتِ بی‌قیمت یا بیرون از شش مدت خریدنی نیست — قیمت حدس زده نمی‌شود */
    public function test_an_unpriced_or_unknown_duration_is_refused_without_creating_anything(): void
    {
        $this->actingAs($this->manager)->post('/subscription/checkout', ['plan' => 'res-pro', 'months' => 12])
            ->assertRedirectContains('checkout=error');
        $this->actingAs($this->manager)->post('/subscription/checkout', ['plan' => 'res-pro', 'months' => 2])
            ->assertSessionHasErrors('months');
        PlanPrice::where('plan_id', $this->pro->id)->where('months', 6)->update(['is_active' => false]);
        $this->actingAs($this->manager)->post('/subscription/checkout', ['plan' => 'res-pro', 'months' => 6])
            ->assertRedirectContains('checkout=error');

        $this->assertSame(0, Subscription::count());
    }

    /* ── بندهای ۲۰ و ۲۱: تمدیدِ همان پلن ───────────────────────────────── */

    public function test_renewing_the_same_plan_adds_the_new_period_to_what_is_left(): void
    {
        $current = $this->activate($this->pro, 3);
        $this->travel(45)->days();
        // ۴۵ روز بعد نشستِ قبلی منقضی است (`SessionLifecycleTest`)؛ مدیر دوباره وارد می‌شود
        $this->flushSession();
        $this->withConfirmedPassword();
        $leftUntil = $current->fresh()->ends_at;

        $data = $this->actingAs($this->manager)->getJson('/api/v1/subscription')->assertOk()->json();
        $this->assertSame(['plan' => 'res-pro'], $data['renewal'], '«تمدیدِ پلنِ فعلی» پیشنهاد نشد.');
        $this->assertSame('حرفه‌ای', $data['current']['tierLabel']);

        // بی تأییدِ تغییر — چون تغییر نیست — و با مدتِ دیگرِ همان پلن
        $renewal = $this->checkout(['plan' => 'res-pro', 'months' => 6]);
        $this->assertSame(SubscriptionRenewal::RENEWAL, $renewal->purchase_kind);
        $this->pay($renewal);

        $renewal->refresh();
        $this->assertSame('active', $current->fresh()->status, 'پلنِ فعلی با تمدید باطل شد.');
        $this->assertEquals($leftUntil, $renewal->starts_at, 'دوره‌ی تازه پس از باقی‌مانده شروع نشد.');
        $this->assertEquals($leftUntil->copy()->addMonths(6), $renewal->ends_at);
        $this->assertGreaterThan(45 + 170, $this->actingAs($this->manager)->getJson('/api/v1/subscription')->json('current.daysLeftTotal'));
    }

    /**
     * ایرادات ۱۰، بندِ ۱۰۵ («مجتمعِ دیگر»؛ آشتیِ ماتریس در ۱۳/۹): مدیرِ دو مجتمع که در الف کار می‌کند با نامِ ب در درخواست
     * تمدیدِ ب را نمی‌خرد — تمدید مالِ مجتمعِ فعالِ سرور است، مبلغ از سرور، و ب هیچ اشتراکی نمی‌گیرد.
     */
    public function test_a_manager_of_two_complexes_cannot_renew_the_other_one_by_naming_it(): void
    {
        $other = Complex::factory()->create(['type' => ComplexType::Residential, 'name' => 'مجتمع نفیس']);
        app(MembershipLedger::class)->start($this->manager, $other, UserRole::ComplexAdmin, ComplexMembership::SOURCE_DIRECT, activate: false);
        $this->activate($this->pro, 3);

        $renewal = $this->checkout([
            'plan' => 'res-pro', 'months' => 3,
            'complex_id' => $other->id, 'complex' => $other->id, 'community_id' => $other->id, 'amount' => 1,
        ]);

        $this->assertSame($this->complex->id, $renewal->complex_id, 'تمدید به مجتمعِ نام‌برده در درخواست رفت.');
        $this->assertEquals(550_000, (float) $renewal->amount, 'مبلغِ درخواست جای قیمتِ سرور نشست.');
        $this->pay($renewal);
        $this->assertSame(0, Subscription::withoutGlobalScopes()->where('complex_id', $other->id)->count());
    }

    /* ── بندهای ۲۲ تا ۲۴: تغییرِ پلن ───────────────────────────────────── */

    public function test_changing_the_plan_needs_an_explicit_confirmation_and_creates_nothing_without_it(): void
    {
        $this->activate($this->pro, 3);

        $this->actingAs($this->manager)->post('/subscription/checkout', ['plan' => 'res-adv', 'months' => 1])
            ->assertRedirectContains('checkout=error');
        $this->actingAs($this->manager)->post('/subscription/checkout', ['plan' => 'res-adv', 'months' => 1, 'confirm_change' => '0'])
            ->assertRedirectContains('checkout=error');

        $this->assertSame(1, Subscription::count(), 'بی تأیید ردیفی ثبت شد.');
        $this->assertSame('active', Subscription::sole()->status);
    }

    /** پرداختِ ناموفق پلنِ قبلی را دست نمی‌زند؛ موفق: پلنِ قبلی و تمدیدِ صف‌شده‌اش خاتمه، تازه از همین لحظه، بی انتقالِ باقی‌مانده */
    public function test_a_confirmed_change_ends_the_old_plan_only_when_the_payment_is_verified(): void
    {
        $current = $this->activate($this->pro, 3);
        $queued = $this->activate($this->pro, 3);
        $this->assertTrue($queued->starts_at->isFuture());

        $failing = $this->checkout(['plan' => 'res-adv', 'months' => 1, 'confirm_change' => '1']);
        $this->assertSame(SubscriptionRenewal::CHANGE, $failing->purchase_kind);
        $this->get("/subscription/callback/{$failing->id}?ref=NOT-THE-REF")->assertRedirectContains('checkout=failed');
        $this->assertSame(['active', 'active'], [$current->fresh()->status, $queued->fresh()->status], 'پرداختِ ناموفق پلنِ قبلی را خاتمه داد.');

        $change = $this->checkout(['plan' => 'res-adv', 'months' => 1, 'confirm_change' => '1']);
        $this->pay($change);

        $this->assertSame(['replaced', 'replaced'], [$current->fresh()->status, $queued->fresh()->status]);
        $this->assertTrue($current->fresh()->ends_at->lte(now()), 'دوره‌ی جاری با تغییر بسته نشد.');
        $change->refresh();
        $this->assertSame('active', $change->status);
        $this->assertTrue($change->starts_at->lte(now()->addSecond()), 'پلنِ تازه از همین لحظه شروع نشد.');
        $this->assertEquals($change->starts_at->copy()->addMonth(), $change->ends_at, 'باقی‌مانده به پلنِ تازه منتقل شد.');
        $this->assertSame('res-adv', $this->actingAs($this->manager)->getJson('/api/v1/subscription')->json('currentPlan'));
        $this->assertSame(1, AuditLog::where('action', 'subscription.changed')->count());
    }

    /* ── بندهای ۲۶ تا ۲۸: تاریخچه‌ی پرداخت‌ها ─────────────────────────── */

    public function test_the_payment_history_is_paginated_and_holds_only_this_community(): void
    {
        for ($i = 0; $i < 17; $i++) {
            Subscription::create(['complex_id' => $this->complex->id, 'user_id' => $this->manager->id, 'plan' => 'pro', 'plan_id' => $this->basic->id,
                'status' => 'failed', 'method' => 'online', 'amount' => 100_000, 'months' => 1]);
        }
        $other = Complex::factory()->create(['type' => ComplexType::Residential]);
        Subscription::create(['complex_id' => $other->id, 'user_id' => null, 'plan' => 'pro', 'plan_id' => $this->basic->id,
            'status' => 'failed', 'method' => 'online', 'amount' => 999_999, 'months' => 1]);

        $first = $this->actingAs($this->manager)->getJson('/api/v1/subscription/history')->assertOk();
        $this->assertCount(15, $first->json('data'));
        $this->assertSame(['currentPage' => 1, 'lastPage' => 2, 'total' => 17], $first->json('meta'));
        $row = $first->json('data.0');
        foreach (['planLabel', 'tierLabel', 'complexTypeLabel', 'months', 'amountLabel', 'date', 'time', 'statusLabel'] as $field) {
            $this->assertArrayHasKey($field, $row);
        }
        $this->assertSame('مسکونی', $row['complexTypeLabel']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $row['time']);

        $second = $this->actingAs($this->manager)->getJson('/api/v1/subscription/history?page=2')->assertOk();
        $this->assertCount(2, $second->json('data'));
        $amounts = collect([...$first->json('data'), ...$second->json('data')])->pluck('amountLabel')->unique()->all();
        $this->assertCount(1, $amounts, 'پرداختِ مجتمعِ دیگری در تاریخچه آمد.');

        // `complex_id` ِ دستکاری‌شده بی‌اثر است: مجتمع از زمینه‌ی سرور
        $this->assertSame(17, $this->actingAs($this->manager)->getJson('/api/v1/subscription/history?complex_id='.$other->id)->json('meta.total'));
    }

    /** حسابِ اولیه: فقط خریدهای خودش برای ساختِ مجتمع */
    public function test_the_initial_account_history_holds_only_its_own_purchases(): void
    {
        $initial = User::factory()->create(['complex_id' => null, 'role' => UserRole::Owner, 'is_active' => true]);
        $stranger = User::factory()->create(['complex_id' => null, 'role' => UserRole::Owner, 'is_active' => true]);
        foreach ([$initial, $initial, $stranger] as $buyer) {
            CheckoutIntent::create(['user_id' => $buyer->id, 'plan_id' => $this->pro->id, 'plan_name' => 'حرفه‌ای', 'amount' => 550_000, 'months' => 3,
                'complex_name' => 'مجتمع '.$buyer->id, 'complex_type' => ComplexType::Residential]);
        }

        $rows = $this->actingAs($initial)->getJson('/api/v1/subscription/history')->assertOk()->json('data');

        $this->assertCount(2, $rows);
        $this->assertSame(['مجتمع '.$initial->id], array_values(array_unique(array_column($rows, 'complexName'))));
        $this->assertSame('در انتظار پرداخت', $rows[0]['statusLabel']);
    }

    /* ── بندهای ۶ و ۷: مدیریتِ پلن‌ها ──────────────────────────────────── */

    public function test_the_super_admin_manages_tier_and_duration_prices_with_validation(): void
    {
        $super = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true]);
        $base = ['name' => 'پایه‌ی تجاری', 'slug' => 'com-basic-2', 'complex_type' => 'commercial', 'is_active' => true];

        // سطحِ نیامده، مدتِ بیرون از فهرست، مدتِ تکراری، قیمتِ منفی — همه رد
        $this->actingAs($super)->postJson('/api/v1/system/plans', [...$base, 'prices' => [['months' => 1, 'price' => 1]]])->assertJsonValidationErrors('tier');
        foreach ([[['months' => 2, 'price' => 1]], [['months' => 1, 'price' => 1], ['months' => 1, 'price' => 2]], [['months' => 1, 'price' => -1]]] as $prices) {
            $this->actingAs($super)->postJson('/api/v1/system/plans', [...$base, 'tier' => 'basic', 'prices' => $prices])->assertStatus(422);
        }

        $created = $this->actingAs($super)->postJson('/api/v1/system/plans', [...$base, 'tier' => 'basic', 'description' => 'برای مجتمع‌های تجاریِ کوچک',
            'prices' => [['months' => 1, 'price' => 120_000], ['months' => 12, 'price' => 1_200_000]]])->assertCreated();
        $this->assertSame([1, 12], array_column($created->json('plan.prices'), 'months'));
        $this->assertSame(1, AuditLog::where('action', 'plan.created')->count());

        // پلنِ فعالِ دوم برای همان سطح و نوع رد: کارتِ دوم بی‌صدا پنهان می‌ماند
        $this->actingAs($super)->postJson('/api/v1/system/plans', [...$base, 'slug' => 'com-basic-3', 'tier' => 'basic', 'prices' => [['months' => 1, 'price' => 1]]])
            ->assertJsonValidationErrors('tier');

        // مدیرِ مجتمع به مدیریتِ پلن نمی‌رسد
        $this->actingAs($this->manager)->putJson("/api/v1/system/plans/{$this->pro->id}", $this->payload($this->pro, [1 => 1]))->assertForbidden();
        $this->assertSame(200_000, $this->pro->fresh()->priceFor(1));
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    /** @param  array<int, int>  $prices */
    private function plan(string $slug, PlanTier $tier, ?ComplexType $type, array $prices, ?int $unitLimit = 120, bool $everything = false): Plan
    {
        // ستون‌های تک‌قیمتیِ پیشین عمداً کهنه‌اند: هیچ مسیرِ خریدی نباید از آن‌ها بخواند (`Plan::priceFor`)
        $plan = Plan::create([
            'name' => $tier->label(), 'slug' => $slug, 'tier' => $tier, 'complex_type' => $type, 'is_active' => true,
            'price' => 1, 'months' => 1,
            'unit_limit' => $unitLimit, 'real_gateway' => $everything || $tier !== PlanTier::Basic, 'excel_export' => $everything,
            'features' => ['متنِ کارت'],
        ]);
        foreach ($prices as $months => $price) {
            PlanPrice::create(['plan_id' => $plan->id, 'months' => $months, 'price' => $price, 'is_active' => true]);
        }

        return $plan->fresh(['prices']);
    }

    /** اشتراکِ پرداخت‌شده‌ی همین پلن از مسیرِ واقعی — درگاهِ sandbox */
    private function activate(Plan $plan, int $months): Subscription
    {
        $pending = $this->checkout(['plan' => $plan->slug, 'months' => $months]);
        $this->pay($pending);

        return $pending->fresh();
    }

    /** @param  array<string, mixed>  $input */
    private function checkout(array $input): Subscription
    {
        $before = Subscription::max('id') ?? 0;
        $this->actingAs($this->manager)->post('/subscription/checkout', $input)->assertRedirect();

        return Subscription::where('id', '>', $before)->sole();
    }

    private function pay(Subscription $pending): TestResponse
    {
        return $this->actingAs($this->manager)->get("/subscription/callback/{$pending->id}?ref={$pending->fresh()->ref_id}")
            ->assertRedirectContains('checkout=success');
    }

    /** @return array<string, mixed> */
    private function card(string $slug): array
    {
        return collect($this->actingAs($this->manager)->getJson('/api/v1/subscription')->json('catalog.residential'))->firstWhere('slug', $slug);
    }

    /**
     * @param  array<int, int>  $prices
     * @return array<string, mixed>
     */
    private function payload(Plan $plan, array $prices): array
    {
        return [
            'name' => $plan->name, 'slug' => $plan->slug, 'tier' => $plan->tier?->value, 'complex_type' => $plan->complex_type?->value,
            'unit_limit' => $plan->unit_limit, 'real_gateway' => $plan->real_gateway, 'excel_export' => $plan->excel_export,
            'features' => $plan->features, 'is_active' => true,
            'prices' => collect($prices)->map(fn (int $price, int $months) => ['months' => $months, 'price' => $price, 'is_active' => true])->values()->all(),
        ];
    }
}
