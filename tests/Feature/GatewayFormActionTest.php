<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\OccupancyStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\UserRole;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * صفحه‌ی انتقال به درگاه باید اجازه‌ی رفتن به بانک را داشته باشد (مرحلهٔ ۵ · فاز ۷).
 *
 * 🔴 نقص: CSPِ همه‌ی صفحه‌ها `form-action 'self'` بود، ولی صفحه‌ی انتقال
 * فرمی دارد که **مرورگر** به `sep.shaparak.ir` یا `bpm.shaparak.ir` می‌فرستد.
 * مرورگر چنین فرمی را بلاک می‌کند؛ یعنی پرداختِ آنلاینِ واقعی هرگز به بانک
 * نمی‌رسید. سفرِ مالیِ سرتاسری این را نمی‌دید چون درگاهِ آزمایشی به همین
 * دامنه برمی‌گردد.
 *
 * ⚠️ این تست قاعده را از روی **خودِ صفحه** می‌سنجد، نه از روی نامِ بانک:
 * مبدأِ هر `<form action>`ی که در HTML هست باید در `form-action` همان پاسخ
 * باشد. درگاهِ سومی که فردا اضافه شود هم زیر همین قاعده است.
 */
class GatewayFormActionTest extends TestCase
{
    use RefreshDatabase;

    private const SAMAN_TOKEN = 'https://sep.shaparak.ir/onlinepg/onlinepg';

    private Complex $complex;

    private User $resident;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::create(['type' => 'residential',
            'name' => 'مجتمع درگاه', 'slug' => 'gw-form', 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'saman',
            'gateway_config' => ['terminal_id' => '12345678'],
        ]);

        $this->resident = User::create([
            'complex_id' => $this->complex->id, 'name' => 'ساکن', 'phone' => '09191110071',
            'role' => UserRole::Owner, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->admin = User::create([
            'complex_id' => $this->complex->id, 'name' => 'مدیر', 'phone' => '09191110072',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        // درگاهِ بانکیِ مجتمع فقط با اشتراکِ پروِ فعال روشن است
        Subscription::create([
            'complex_id' => $this->complex->id, 'user_id' => $this->admin->id,
            'plan' => SubscriptionPlan::Pro, 'status' => 'active', 'method' => 'receipt',
            'amount' => 100, 'months' => 12, 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
        ]);

        Http::fake([self::SAMAN_TOKEN => Http::response(['status' => 1, 'token' => 'tok-abc'])]);
    }

    private function bill(): Bill
    {
        $unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_number' => '1', 'floor' => 1, 'area' => 100,
            'residents_count' => 2, 'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied, 'uses_elevator' => true,
        ]);

        $this->resident->units()->attach($unit->id, [
            'complex_id' => $this->complex->id, 'relation' => 'owner',
            'share_percent' => 100, 'is_current' => true,
        ]);

        return Bill::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_id' => $unit->id, 'period' => '1405-04',
            'total_amount' => 500000, 'paid_amount' => 0, 'status' => BillStatus::Unpaid, 'issued_at' => now(),
        ]);
    }

    /** @return array<int, string> */
    private function formAction(TestResponse $response): array
    {
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression('/(?:^|;\s*)form-action ([^;]+)/', $csp);
        preg_match('/(?:^|;\s*)form-action ([^;]+)/', $csp, $match);

        return preg_split('/\s+/', trim($match[1])) ?: [];
    }

    /** مبدأِ هر فرمِ بیرونیِ صفحه باید در `form-action` همان پاسخ باشد. */
    private function assertEveryFormCanSubmit(TestResponse $response): void
    {
        preg_match_all('/<form\b[^>]*\baction="([^"]+)"/i', (string) $response->getContent(), $forms);

        $external = array_filter($forms[1], fn (string $url) => str_starts_with($url, 'http'));
        $this->assertNotEmpty($external, 'صفحه‌ی انتقال باید فرمی به بانک داشته باشد');

        $allowed = $this->formAction($response);

        foreach ($external as $url) {
            $parts = parse_url(html_entity_decode($url));
            $origin = $parts['scheme'].'://'.$parts['host'];

            $this->assertContains($origin, $allowed, "مرورگر فرمِ رفتن به {$origin} را بلاک می‌کند");
        }
    }

    public function test_the_bill_payment_redirect_can_reach_the_bank(): void
    {
        $response = $this->actingAs($this->resident)->post('/pay/'.$this->bill()->id.'/online')->assertOk();

        $this->assertEveryFormCanSubmit($response);
        $this->assertSame(["'self'", 'https://sep.shaparak.ir'], $this->formAction($response));
    }

    public function test_the_subscription_redirect_can_reach_the_bank(): void
    {
        config([
            'subscription.gateway' => 'saman',
            'subscription.config' => ['terminal_id' => '12345678'],
        ]);

        // اشتراکِ فعالِ setUp پلنِ دیگری است، پس این خرید «تغییرِ پلن» است و تأییدِ صریح می‌خواهد (۱۳/۶، بندِ ۲۲)
        $response = $this->actingAs($this->admin)
            ->post('/subscription/checkout', ['plan' => 'basic', 'months' => 1, 'confirm_change' => '1'])
            ->assertOk();

        $this->assertEveryFormCanSubmit($response);
    }

    /**
     * ⚠️ اجازه فقط برای همان یک پاسخ است: بقیه‌ی صفحه‌ها همچنان فقط به
     * خودمان فرم می‌فرستند، حتی درست پس از یک انتقال به درگاه.
     */
    public function test_every_other_page_still_submits_only_to_itself(): void
    {
        $this->actingAs($this->resident)->post('/pay/'.$this->bill()->id.'/online')->assertOk();

        foreach (['/', '/auth', '/dashboard', '/api/v1/me'] as $path) {
            $this->assertSame(["'self'"], $this->formAction($this->get($path)), $path);
        }
    }

    /** مقصدِ غیرِ https یا خراب هیچ اجازه‌ای نمی‌گیرد، و مسیر هرگز در اجازه نمی‌آید. */
    public function test_only_a_secure_origin_is_ever_allowed(): void
    {
        $request = Request::create('/');

        SecurityHeaders::allowFormTarget($request, 'http://sep.shaparak.ir/x');
        SecurityHeaders::allowFormTarget($request, 'javascript:alert(1)');
        SecurityHeaders::allowFormTarget($request, 'not a url');
        SecurityHeaders::allowFormTarget($request, 'https://bank.example:8443/pay?x=1');
        SecurityHeaders::allowFormTarget($request, 'https://bank.example:8443/other');

        $response = response('ok');
        SecurityHeaders::apply($request, $response);

        $this->assertStringContainsString(
            "form-action 'self' https://bank.example:8443;",
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }
}
