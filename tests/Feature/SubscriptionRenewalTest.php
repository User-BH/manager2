<?php

namespace Tests\Feature;

use App\Enums\ComplexType;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscription\SubscriptionRenewal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * تمدیدِ اشتراکِ مجتمعِ موجود — فقط آنلاین (مرحلهٔ ۱۲ · فاز ۲؛ تصمیمِ قطعیِ کارفرما، بندِ ۴).
 *
 * همان محافظ‌های خریدِ اولیه برای تمدید: مبلغ و پلن از سرور، تأییدِ مستقلِ درگاه با همان مبلغ، idempotency، ضدِ
 * replay و هم‌زمانیِ امن. درگاه زرین‌پال است و با `Http::fake` شبیه‌سازی می‌شود.
 */
class SubscriptionRenewalTest extends TestCase
{
    use RefreshDatabase;

    private const AUTHORITY = 'A00000000000000000000000000000077777';

    private const REQUEST_URL = 'https://payment.zarinpal.com/pg/v4/payment/request.json';

    private const VERIFY_URL = 'https://payment.zarinpal.com/pg/v4/payment/verify.json';

    private Complex $complex;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'subscription.gateway' => 'zarinpal',
            'subscription.config.merchant_id' => 'test-merchant-id',
            'subscription.config.sandbox' => false,
        ]);

        $this->complex = Complex::factory()->create(['type' => ComplexType::Residential]);
        $this->manager = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09125556001']);
        Plan::create(['name' => 'تمدیدِ سه‌ماهه', 'slug' => 'renew-3', 'complex_type' => ComplexType::Residential, 'price' => 300000, 'months' => 3, 'is_active' => true]);
    }

    /** @param  array<string, mixed>  $verify */
    private function fakeZarinpal(array $verify = ['data' => ['code' => 100, 'ref_id' => 55500011]], int $status = 200): void
    {
        Http::fake([
            self::REQUEST_URL => Http::response(['data' => ['code' => 100, 'authority' => self::AUTHORITY], 'errors' => []]),
            self::VERIFY_URL => Http::response($verify, $status),
        ]);
    }

    private function start(array $extra = []): Subscription
    {
        $this->actingAs($this->manager)->post('/subscription/checkout', ['plan' => 'renew-3', ...$extra])
            ->assertRedirect('https://payment.zarinpal.com/pg/StartPay/'.self::AUTHORITY);

        return Subscription::where('method', 'online')->latest('id')->firstOrFail();
    }

    private function back(Subscription $subscription, string $status = 'OK', ?string $authority = null): TestResponse
    {
        return $this->get("/subscription/callback/{$subscription->id}?Authority=".($authority ?? self::AUTHORITY)."&Status={$status}");
    }

    /** مبلغ و مدت از پلنِ سرور؛ هر عددِ کلاینت نادیده — و همان مبلغ به درگاه برای تأیید. */
    public function test_the_amount_and_period_come_from_the_server_plan(): void
    {
        $this->fakeZarinpal();
        $pending = $this->start(['amount' => 1, 'price' => 1, 'months' => 99]);

        $this->assertSame([300000, 3, 'pending', 'online'], [(int) $pending->amount, $pending->months, $pending->status, $pending->method]);

        $this->back($pending)->assertRedirectContains('checkout=success');
        Http::assertSent(fn ($request) => $request->url() === self::VERIFY_URL && $request['amount'] === 3_000_000);
        $this->assertSame('active', $pending->fresh()->status);
    }

    /** 🔴 replay و refresh: بازگشتِ دوباره نه دوره را دوباره حساب می‌کند نه چیزی را عوض. */
    public function test_a_repeated_callback_changes_nothing(): void
    {
        $this->fakeZarinpal();
        $pending = $this->start();
        $this->back($pending)->assertRedirectContains('checkout=success');
        $first = $pending->fresh()->only(['status', 'tracking_code', 'paid_at', 'starts_at', 'ends_at']);

        $this->travel(5)->minutes();
        $this->back($pending)->assertRedirectContains('checkout=success');
        $this->back($pending, 'NOK')->assertRedirectContains('checkout=success');

        $this->assertEquals($first, $pending->fresh()->only(['status', 'tracking_code', 'paid_at', 'starts_at', 'ends_at']));
        Http::assertSentCount(2); // یک درخواست، یک تأیید — بازگشتِ دوم دیگر از درگاه نمی‌پرسد
    }

    /** 🔴 هم‌زمانی و idempotency: دو تکمیلِ هم‌زمانِ یک ردیف یک فعال‌سازی‌اند؛ رهگیریِ مصرف‌شده هیچ. */
    public function test_completion_is_idempotent_and_one_tracking_code_is_one_payment(): void
    {
        $this->fakeZarinpal();
        $pending = $this->start();
        $renewal = app(SubscriptionRenewal::class);

        $a = $renewal->complete($pending, '55500011');
        $b = $renewal->complete($pending, '55500011');
        $this->assertSame(['active', 'active'], [$a->status, $b->status]);
        $this->assertSame(1, Subscription::where('status', 'active')->count());
        $this->assertEquals($a->ends_at, $b->fresh()->ends_at);

        // همان رهگیری برای ردیفِ دیگر ← ناموفق، نه فعال
        $other = $this->start();
        $this->assertSame('failed', $renewal->complete($other, '55500011')->status);
        $this->assertSame(1, Subscription::where('status', 'active')->count());
    }

    /** پرداختِ ناموفق، لغو، مرجعِ جعلی و پارامترِ «موفق» ِ کلاینت هیچ‌کدام فعال نمی‌کنند. */
    public function test_failures_and_forgeries_activate_nothing(): void
    {
        $this->fakeZarinpal();
        $cancelled = $this->start();
        $this->back($cancelled, 'NOK')->assertRedirectContains('checkout=failed');
        $this->assertSame('failed', $cancelled->fresh()->status);

        $forged = $this->start();
        $this->get("/subscription/callback/{$forged->id}?Authority=A999&Status=OK&status=active&success=1&tracking=1")
            ->assertRedirectContains('checkout=failed');
        $this->assertSame('failed', $forged->fresh()->status);

        $this->assertSame(0, Subscription::where('status', 'active')->count());
    }

    /** درگاه واقعاً پاسخ داد و پاسخش «نه» است ← ناموفق، بی فعال‌سازی */
    public function test_a_refused_verification_fails_without_activation(): void
    {
        // `Http::fake` ِ دوم stub ِ اول را برای همان نشانی عوض نمی‌کند؛ پس آزمونِ جدا
        $this->fakeZarinpal(['data' => [], 'errors' => ['code' => -51]]);
        $refused = $this->start();
        $this->back($refused)->assertRedirectContains('checkout=failed');

        $this->assertSame('failed', $refused->fresh()->status);
        $this->assertSame(0, Subscription::where('status', 'active')->count());
    }

    /** مبلغِ ناهم‌خوان و درگاهِ بی‌پاسخ «نامعلوم»اند — نه موفق، نه ناموفق؛ برای بازبینیِ بعدی `pending` می‌مانند. */
    public function test_an_amount_mismatch_stays_undecided(): void
    {
        $this->fakeZarinpal(['data' => [], 'errors' => ['code' => -50]]);
        $mismatch = $this->start();
        $this->back($mismatch)->assertRedirectContains('checkout=unknown');
        $this->assertSame('pending', $mismatch->fresh()->status);

    }

    public function test_a_gateway_server_error_stays_undecided(): void
    {
        $this->fakeZarinpal([], 502);
        $timeout = $this->start();
        $this->back($timeout)->assertRedirectContains('checkout=unknown');
        $this->assertSame('pending', $timeout->fresh()->status);
    }

    /** فقط خودِ خریدار یا بانک (بی‌نشست) به بازگشت می‌رسد؛ مدیرِ دیگر نه. */
    public function test_only_the_buyer_or_the_bank_returns_to_a_renewal(): void
    {
        $this->fakeZarinpal();
        $pending = $this->start();
        $stranger = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09125556002']);

        $this->actingAs($stranger)->get("/subscription/callback/{$pending->id}?Authority=".self::AUTHORITY.'&Status=OK')->assertForbidden();
        $this->assertSame('pending', $pending->fresh()->status);
    }

    /** درگاهِ خاموش پیش از ساختنِ هر ردیفی گفته می‌شود؛ راهِ دیگری (رسید) نیست. */
    public function test_a_disabled_gateway_creates_nothing(): void
    {
        config(['subscription.gateway' => null]);

        $this->actingAs($this->manager)->post('/subscription/checkout', ['plan' => 'renew-3'])
            ->assertRedirectContains('checkout=error');
        $this->assertSame(0, Subscription::count());
    }
}
