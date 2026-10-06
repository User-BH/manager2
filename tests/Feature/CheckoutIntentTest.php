<?php

namespace Tests\Feature;

use App\Enums\ComplexType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CheckoutIntent;
use App\Models\Complex;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscription\ComplexPurchase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * خریدِ آنلاینِ اشتراک و ساختِ مجتمع توسطِ حسابِ اولیه (مرحلهٔ ۹ · فاز ۴).
 *
 * تصمیمِ قطعیِ کارفرما: فقط پرداختِ آنلاینِ تأییدشده در سرور، و پس از آن در یک
 * تراکنش مجتمع + اشتراک + مدیر. لغو، شکست، timeout، callback ِ نامعتبر و
 * مغایرتِ مبلغ کاربر را در حالتِ اولیه نگه می‌دارند؛ تکرار هیچ چیزی را دوبار
 * نمی‌سازد. درگاه زرین‌پال است و با `Http::fake` شبیه‌سازی می‌شود.
 */
class CheckoutIntentTest extends TestCase
{
    use RefreshDatabase;

    private const AUTHORITY = 'A00000000000000000000000000000012345';

    private const REQUEST_URL = 'https://payment.zarinpal.com/pg/v4/payment/request.json';

    private const VERIFY_URL = 'https://payment.zarinpal.com/pg/v4/payment/verify.json';

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'subscription.gateway' => 'zarinpal',
            'subscription.config.merchant_id' => 'test-merchant-id',
            'subscription.config.sandbox' => false,
        ]);

        $this->plan = Plan::create([
            'name' => 'پلنِ آزمون', 'slug' => 'res-test', 'complex_type' => ComplexType::Residential,
            'price' => 250000, 'months' => 3, 'is_active' => true,
        ]);
    }

    private function initial(string $phone = '09125550101'): User
    {
        return User::factory()->create([
            'first_name' => 'مریم', 'last_name' => 'حسینی', 'phone' => $phone,
            'role' => UserRole::Owner, 'complex_id' => null, 'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $verify */
    private function fakeZarinpal(array $verify = ['data' => ['code' => 100, 'ref_id' => 987654321]], int $verifyStatus = 200): void
    {
        Http::fake([
            self::REQUEST_URL => Http::response(['data' => ['code' => 100, 'authority' => self::AUTHORITY], 'errors' => []]),
            self::VERIFY_URL => Http::response($verify, $verifyStatus),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function start(User $user, array $overrides = []): TestResponse
    {
        return $this->actingAs($user)->post('/subscription/checkout', [
            'plan' => 'res-test',
            'complex_name' => 'مجتمع آفتاب',
            'complex_type' => 'residential',
            ...$overrides,
        ]);
    }

    private function returnFromGateway(CheckoutIntent $intent, string $status = 'OK', ?string $authority = null): TestResponse
    {
        return $this->get("/checkout/callback/{$intent->public_id}?Authority=".($authority ?? self::AUTHORITY)."&Status={$status}");
    }

    /* ───────────────────── شروع: فقط قصد، نه مجتمع ───────────────────── */

    public function test_starting_creates_only_an_intent_with_the_server_amount(): void
    {
        $this->fakeZarinpal();
        $user = $this->initial();

        $this->start($user, ['amount' => 1, 'price' => 1, 'months' => 99])
            ->assertRedirect('https://payment.zarinpal.com/pg/StartPay/'.self::AUTHORITY);

        $intent = CheckoutIntent::sole();
        $this->assertSame([250000, 3, 'pending', 'zarinpal', self::AUTHORITY], [
            $intent->amount, $intent->months, $intent->status, $intent->gateway, $intent->authority,
        ]);
        // پیش از پرداخت هیچ مجتمع، اشتراک یا نقشی
        $this->assertSame(0, Complex::count());
        $this->assertSame(0, Subscription::count());
        $this->assertNull($user->fresh()->complex_id);
        $this->assertSame(UserRole::Owner, $user->fresh()->role);

        Http::assertSent(fn (Request $r) => $r->url() === self::REQUEST_URL
            && $r['amount'] === 2_500_000 // تومان × ۱۰ = ریال، از پلن
            && $r['merchant_id'] === 'test-merchant-id'
            && $r['callback_url'] === route('checkout.callback', $intent));
    }

    /* ───────────────────── موفق: همه با هم ───────────────────── */

    public function test_a_verified_payment_creates_complex_subscription_and_manager_atomically(): void
    {
        $this->fakeZarinpal();
        $user = $this->initial();
        $this->start($user);
        $intent = CheckoutIntent::sole();

        $this->returnFromGateway($intent)->assertRedirect('/dashboard?checkout=success&tracking=987654321');

        $user->refresh();
        $complex = Complex::sole();
        $subscription = Subscription::sole();
        $intent->refresh();

        $this->assertSame(UserRole::ComplexAdmin, $user->role);
        $this->assertSame($complex->id, $user->complex_id);
        $this->assertSame(['مجتمع آفتاب', ComplexType::Residential], [$complex->name, $complex->type]);
        $this->assertSame([$complex->id, 'active', 'online', '987654321', 3], [
            $subscription->complex_id, $subscription->status, $subscription->method, $subscription->tracking_code, $subscription->months,
        ]);
        $this->assertTrue($subscription->ends_at->isSameDay(now()->addMonths(3)));
        $this->assertSame([CheckoutIntent::COMPLETED, $complex->id, $subscription->id], [$intent->status, $intent->complex_id, $intent->subscription_id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'checkout.completed']);

        // مبلغِ تأیید از سفارشِ خودمان، نه از مرورگر
        Http::assertSent(fn (Request $r) => $r->url() === self::VERIFY_URL
            && $r['amount'] === 2_500_000 && $r['authority'] === self::AUTHORITY);
    }

    /** refresh، بازگشتِ دوباره، callback ِ تکراری: هیچ چیزِ دوم */
    public function test_repeated_callbacks_create_nothing_twice_and_never_charge_again(): void
    {
        $this->fakeZarinpal();
        $user = $this->initial();
        $this->start($user);
        $intent = CheckoutIntent::sole();

        $this->returnFromGateway($intent)->assertRedirect('/dashboard?checkout=success&tracking=987654321');
        $this->returnFromGateway($intent)->assertRedirect('/dashboard?checkout=success&tracking=987654321');
        $this->returnFromGateway($intent, 'NOK')->assertRedirect('/dashboard?checkout=success&tracking=987654321');

        $this->assertSame([1, 1, 1], [Complex::count(), Subscription::count(), User::where('role', UserRole::ComplexAdmin)->count()]);
        // تکلیفِ روشن‌شده دوباره از درگاه پرسیده نمی‌شود
        Http::assertSentCount(2);
    }

    /** دو تکمیلِ هم‌زمان (هر دو پس از تأییدِ درگاه): فقط یکی می‌سازد */
    public function test_completing_twice_is_idempotent(): void
    {
        $user = $this->initial();
        $intent = app(ComplexPurchase::class)->begin($user, $this->plan, 'مجتمع آفتاب', ComplexType::Residential, null);

        $first = app(ComplexPurchase::class)->complete($intent, 'TRK-1');
        $second = app(ComplexPurchase::class)->complete($intent, 'TRK-1');

        $this->assertSame(CheckoutIntent::COMPLETED, $first->status);
        $this->assertSame($first->subscription_id, $second->subscription_id);
        $this->assertSame([1, 1], [Complex::count(), Subscription::count()]);
    }

    /* ───────────────────── ناموفق: کاربر اولیه می‌ماند ───────────────────── */

    public function test_a_cancelled_payment_leaves_the_user_initial_and_free_to_retry(): void
    {
        $this->fakeZarinpal();
        $user = $this->initial();
        $this->start($user);
        $intent = CheckoutIntent::sole();

        $this->returnFromGateway($intent, 'NOK')->assertRedirect('/account?checkout=failed');

        $this->assertSame(CheckoutIntent::FAILED, $intent->fresh()->status);
        $this->assertNull($user->fresh()->complex_id);
        $this->assertSame(0, Complex::count());
        Http::assertNotSent(fn (Request $r) => $r->url() === self::VERIFY_URL);

        // دوباره می‌تواند بخرد
        $this->start($user)->assertRedirect();
        $this->assertSame(2, CheckoutIntent::count());
    }

    public function test_a_refused_verification_fails_without_a_complex(): void
    {
        $this->fakeZarinpal(['data' => [], 'errors' => ['code' => -51, 'message' => 'not paid']]);
        $user = $this->initial();
        $this->start($user);

        $this->returnFromGateway(CheckoutIntent::sole())->assertRedirect('/account?checkout=failed');

        $this->assertSame(CheckoutIntent::FAILED, CheckoutIntent::sole()->status);
        $this->assertSame(0, Complex::count());
        $this->assertSame(UserRole::Owner, $user->fresh()->role);
    }

    /**
     * 🔴 هیچ پارامترِ «موفق» ِ کلاینت خوانده نمی‌شود (تصمیمِ ۴ ِ کارفرما).
     *
     * خرابکاریِ فاز ۴ این را خواست: کنترلری که با `?success=1` به «موفق»
     * هدایت می‌کرد هیچ تستی را قرمز نمی‌کرد.
     */
    public function test_client_supplied_success_parameters_are_ignored(): void
    {
        $this->fakeZarinpal(['data' => [], 'errors' => ['code' => -51]]);
        $user = $this->initial();
        $this->start($user);
        $intent = CheckoutIntent::sole();

        $this->get("/checkout/callback/{$intent->public_id}?Authority=".self::AUTHORITY
            .'&Status=OK&success=1&status=completed&paid=true&amount=1&ref_id=123')
            ->assertRedirect('/account?checkout=failed');

        $this->assertSame(CheckoutIntent::FAILED, $intent->fresh()->status);
        $this->assertSame([0, 0], [Complex::count(), Subscription::count()]);
        $this->assertNull($user->fresh()->complex_id);
    }

    /** مرجعِ بیگانه در بازگشت: پیش از تأیید رد می‌شود */
    public function test_a_foreign_authority_is_rejected_before_verification(): void
    {
        $this->fakeZarinpal();
        $user = $this->initial();
        $this->start($user);

        $this->returnFromGateway(CheckoutIntent::sole(), 'OK', 'A99999999999999999999999999999999999')
            ->assertRedirect('/account?checkout=failed');

        Http::assertNotSent(fn (Request $r) => $r->url() === self::VERIFY_URL);
        $this->assertSame(0, Complex::count());
    }

    /** مغایرتِ مبلغ و نرسیدن به درگاه «نامعلوم»اند، نه ناموفق — pending + audit */
    public function test_amount_mismatch_and_timeouts_are_indeterminate_not_failed(): void
    {
        $user = $this->initial();

        $this->fakeZarinpal(['data' => [], 'errors' => ['code' => -50, 'message' => 'amount mismatch']]);
        $this->start($user);
        $this->returnFromGateway(CheckoutIntent::sole())->assertRedirect('/account?checkout=unknown');
        $this->assertSame(CheckoutIntent::PENDING, CheckoutIntent::sole()->status);

        Http::fake([
            self::REQUEST_URL => Http::response(['data' => ['code' => 100, 'authority' => self::AUTHORITY]]),
            self::VERIFY_URL => fn () => throw new ConnectionException('timeout'),
        ]);
        $this->returnFromGateway(CheckoutIntent::sole())->assertRedirect('/account?checkout=unknown');

        $this->assertSame(CheckoutIntent::PENDING, CheckoutIntent::sole()->status);
        $this->assertSame(2, AuditLog::where('action', 'checkout.indeterminate')->count());
        $this->assertSame(0, Complex::count());
        $this->assertNull($user->fresh()->complex_id);
    }

    public function test_a_gateway_server_error_is_indeterminate(): void
    {
        $this->fakeZarinpal(['data' => []], 502);
        $this->start($this->initial());

        $this->returnFromGateway(CheckoutIntent::sole())->assertRedirect('/account?checkout=unknown');
        $this->assertSame(CheckoutIntent::PENDING, CheckoutIntent::sole()->status);
    }

    /* ───────────────────── امنیت ───────────────────── */

    /** یک کدِ رهگیری، یک پرداخت — حتی میانِ قبض، اشتراک و قصد */
    public function test_a_tracking_code_already_used_elsewhere_creates_nothing(): void
    {
        $other = Complex::factory()->create();
        Subscription::create([
            'complex_id' => $other->id, 'plan' => 'pro', 'status' => 'active', 'method' => 'online',
            'amount' => 1000, 'months' => 1, 'tracking_code' => '987654321',
        ]);
        $this->fakeZarinpal();
        $user = $this->initial();
        $this->start($user);

        $this->returnFromGateway(CheckoutIntent::sole())->assertRedirect('/account?checkout=failed');

        $this->assertSame(1, Complex::count()); // فقط همان دیگری
        $this->assertNull($user->fresh()->complex_id);
    }

    /** پول گرفته شد ولی حساب در این فاصله عضوِ مجتمعی شد: نه مدیرِ دوم، نه پولِ گم‌شده */
    public function test_a_payment_for_a_user_who_is_no_longer_initial_is_kept_for_review(): void
    {
        $this->fakeZarinpal();
        $user = $this->initial();
        $this->start($user);
        $joined = Complex::factory()->create();
        $user->forceFill(['complex_id' => $joined->id, 'role' => UserRole::Tenant])->save();

        $this->returnFromGateway(CheckoutIntent::sole())->assertRedirect('/account?checkout=review');

        $intent = CheckoutIntent::sole();
        $this->assertSame([CheckoutIntent::PAID, '987654321'], [$intent->status, $intent->tracking_code]);
        $this->assertSame([$joined->id, UserRole::Tenant], [$user->fresh()->complex_id, $user->fresh()->role]);
        $this->assertSame(0, Subscription::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'checkout.paid_unfulfilled']);
    }

    public function test_only_the_buyer_or_the_bank_can_return_to_an_intent(): void
    {
        $this->fakeZarinpal();
        $buyer = $this->initial();
        $this->start($buyer);
        $intent = CheckoutIntent::sole();

        $this->actingAs($this->initial('09125550202'));
        $this->returnFromGateway($intent)->assertForbidden();
        $this->assertSame(CheckoutIntent::PENDING, $intent->fresh()->status);

        // بی نشست (بازگشت پس از انقضای نشست) هم کار می‌کند — اعتبار با تأییدِ درگاه است
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->returnFromGateway($intent)->assertRedirect('/dashboard?checkout=success&tracking=987654321');
        $this->assertSame(UserRole::ComplexAdmin, $buyer->fresh()->role);
    }

    /** نشانیِ بازگشت شمارنده‌ی ردیف نیست */
    public function test_the_callback_is_not_addressable_by_a_sequential_id(): void
    {
        $this->fakeZarinpal();
        $this->start($this->initial());
        $intent = CheckoutIntent::sole();

        $this->get("/checkout/callback/{$intent->id}?Authority=".self::AUTHORITY.'&Status=OK')->assertNotFound();
        $this->assertSame(26, strlen($intent->public_id));
    }

    public function test_plans_are_checked_on_the_server(): void
    {
        $this->fakeZarinpal();
        $user = $this->initial();
        Plan::create(['name' => 'تجاری', 'slug' => 'com', 'complex_type' => ComplexType::Commercial, 'price' => 1, 'months' => 1, 'is_active' => true]);
        Plan::create(['name' => 'رایگان', 'slug' => 'free', 'complex_type' => ComplexType::Residential, 'price' => 0, 'months' => 1, 'is_active' => true]);
        Plan::create(['name' => 'خاموش', 'slug' => 'off', 'complex_type' => ComplexType::Residential, 'price' => 1000, 'months' => 1, 'is_active' => false]);

        foreach (['com', 'free', 'off', 'no-such'] as $slug) {
            $this->start($user, ['plan' => $slug])
                ->assertRedirect('/account?checkout=error&message='.urlencode('پکیجِ انتخاب‌شده برای این نوع مجتمع قابلِ خرید نیست.'));
        }
        // نوع اجباری است؛ «مسکونی» پیش‌فرض نمی‌شود
        $this->start($user, ['complex_type' => null])->assertSessionHasErrors('complex_type');
        $this->start($user, ['complex_type' => 'mixed'])->assertSessionHasErrors('complex_type');

        $this->assertSame(0, CheckoutIntent::count());
        Http::assertNothingSent();
    }

    public function test_a_disabled_gateway_is_said_before_any_intent(): void
    {
        config(['subscription.config.merchant_id' => null]);
        Http::fake();

        $this->start($this->initial())->assertRedirect();

        $this->assertSame(0, CheckoutIntent::count());
        Http::assertNothingSent();
    }

    public function test_a_new_start_supersedes_the_previous_pending_intent(): void
    {
        // هر درخواست مرجعِ تازه‌ی خودش را می‌گیرد — مثلِ درگاهِ واقعی
        Http::fake([self::REQUEST_URL => Http::sequence()
            ->push(['data' => ['code' => 100, 'authority' => 'A0000000000000000000000000000000000001']])
            ->push(['data' => ['code' => 100, 'authority' => 'A0000000000000000000000000000000000002']])]);
        $user = $this->initial();
        $this->start($user);
        $this->start($user);

        $this->assertSame([CheckoutIntent::CANCELLED, CheckoutIntent::PENDING], CheckoutIntent::orderBy('id')->pluck('status')->all());
    }

    /** قیدِ پایگاه‌داده: یک مرجعِ درگاه، یک قصد — حتی اگر درگاه مرجعِ تکراری بدهد */
    public function test_one_gateway_authority_belongs_to_one_intent(): void
    {
        $this->fakeZarinpal();
        $user = $this->initial();
        $this->start($user);
        $this->start($user)->assertRedirect('/account?checkout=error&message='.urlencode('اتصال به درگاه زرین‌پال ناموفق بود.'));

        $this->assertSame([CheckoutIntent::CANCELLED, CheckoutIntent::FAILED], CheckoutIntent::orderBy('id')->pluck('status')->all());
        $this->assertSame(1, CheckoutIntent::where('authority', self::AUTHORITY)->count());
    }

    public function test_status_only_moves_along_the_state_machine(): void
    {
        $intent = app(ComplexPurchase::class)->begin($this->initial(), $this->plan, 'مجتمع آفتاب', ComplexType::Residential, null);
        $intent = app(ComplexPurchase::class)->complete($intent, 'TRK-2');

        $this->expectException(\DomainException::class);
        $intent->transitionTo(CheckoutIntent::FAILED);
    }

    /** وضعیت و پیوندها از راهِ پرکردنِ انبوه نوشته نمی‌شوند */
    public function test_status_and_links_are_not_mass_assignable(): void
    {
        $intent = CheckoutIntent::create([
            'user_id' => $this->initial()->id, 'plan_name' => 'x', 'amount' => 1, 'months' => 1,
            'complex_name' => 'x', 'complex_type' => ComplexType::Residential,
            'status' => CheckoutIntent::COMPLETED, 'tracking_code' => 'X', 'complex_id' => 1, 'subscription_id' => 1,
        ]);

        $this->assertSame([CheckoutIntent::PENDING, null, null, null], [
            $intent->status, $intent->tracking_code, $intent->complex_id, $intent->subscription_id,
        ]);
    }

    /** پولی که شاید گرفته شده هرگز بی‌صدا گم نمی‌شود: `payments:stale` نشانش می‌دهد و شکست می‌خورد */
    public function test_indeterminate_and_unfulfilled_purchases_surface_for_manual_review(): void
    {
        $this->fakeZarinpal(['data' => [], 'errors' => ['code' => -50]]);
        $this->start($this->initial());
        $this->returnFromGateway(CheckoutIntent::sole())->assertRedirect('/account?checkout=unknown');

        $this->artisan('payments:stale', ['--minutes' => 1])->assertSuccessful();

        $this->travel(5)->minutes();
        $this->artisan('payments:stale', ['--minutes' => 1])
            ->expectsOutputToContain('خریدِ مجتمع بررسیِ دستی می‌خواهد')
            ->assertFailed();
    }

    /**
     * صفحه‌ی خریدِ حسابِ اولیه (مرحلهٔ ۹ · فاز ۵) همان پلن‌هایی را می‌بیند که
     * سرور هنگامِ خرید می‌پذیرد — نه پلنِ رایگان، نه خاموش، نه نوعِ دیگر.
     */
    public function test_the_purchase_page_offers_exactly_what_the_server_accepts(): void
    {
        Plan::create(['name' => 'تجاری', 'slug' => 'com', 'complex_type' => ComplexType::Commercial, 'price' => 1000, 'months' => 1, 'is_active' => true]);
        Plan::create(['name' => 'رایگان', 'slug' => 'free', 'complex_type' => ComplexType::Residential, 'price' => 0, 'months' => 1, 'is_active' => true]);
        Plan::create(['name' => 'خاموش', 'slug' => 'off', 'complex_type' => ComplexType::Residential, 'price' => 1000, 'months' => 1, 'is_active' => false]);
        Plan::create(['name' => 'هر دو', 'slug' => 'both', 'complex_type' => null, 'price' => 5000, 'months' => 12, 'is_active' => true]);

        $offered = $this->actingAs($this->initial())->getJson('/api/v1/subscription')->assertOk()->json('newComplexPlans');

        // پلن‌های پیش‌فرضِ مهاجرت (basic، plus، business) هم هستند؛ قاعده سنجیده می‌شود، نه فهرستِ دقیق
        $residential = array_column($offered['residential'], 'value');
        $commercial = array_column($offered['commercial'], 'value');
        $this->assertContains('res-test', $residential);
        $this->assertContains('both', $residential);
        $this->assertContains('both', $commercial);
        $this->assertContains('com', $commercial);
        foreach (['free', 'off', 'com'] as $never) {
            $this->assertNotContains($never, $residential, "«{$never}» نباید برای مسکونی عرضه شود");
        }
        $this->assertNotContains('res-test', $commercial);
        $this->assertSame(250000, collect($offered['residential'])->firstWhere('value', 'res-test')['price']);

        // و هر پلنِ عرضه‌شده واقعاً پذیرفته می‌شود — یک قاعده
        $purchase = app(ComplexPurchase::class);
        foreach (['residential', 'commercial'] as $type) {
            foreach ($offered[$type] as $plan) {
                $this->assertSame($plan['value'], $purchase->plan($plan['value'], ComplexType::from($type))->slug);
            }
        }

        // مدیرِ مجتمع این فهرست را نمی‌گیرد؛ صفحه‌ی خودش بی‌تغییر است
        $manager = User::factory()->create(['role' => UserRole::ComplexAdmin, 'complex_id' => Complex::factory()->create()->id, 'is_active' => true]);
        $this->actingAs($manager)->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('newComplexPlans', null);
    }

    /* ───────────────────── سابقه‌ی مالی ───────────────────── */

    public function test_deleting_the_buyer_or_the_complex_never_erases_payment_history(): void
    {
        $user = $this->initial();
        $intent = app(ComplexPurchase::class)->begin($user, $this->plan, 'مجتمع آفتاب', ComplexType::Residential, null);
        $intent = app(ComplexPurchase::class)->complete($intent, 'TRK-3');

        try {
            Complex::whereKey($intent->complex_id)->delete();
            $this->fail('مجتمعِ دارای اشتراک حذف شد.');
        } catch (QueryException) {
            // restrictOnDelete
        }

        $user->forceFill(['complex_id' => null])->save();
        $user->delete();

        $this->assertNull($intent->fresh()->user_id);
        $this->assertNull(Subscription::sole()->user_id);
        $this->assertSame(1, CheckoutIntent::count());
    }
}
