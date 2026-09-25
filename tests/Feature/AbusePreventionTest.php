<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * سقفِ نرخ فراتر از ورود و جلوگیری از سوءاستفاده (مرحلهٔ ۵ · فاز ۹ · بندهای ۷۲ و ۷۳).
 *
 * 🔴 نقص: ۱۵۹ مسیرِ تغییردهنده هیچ سقفی نداشتند و روی `/api` هیچ سقفِ
 * سراسری‌ای نبود — کامنتی از مرحلهٔ چهارم در `AppServiceProvider` خودش
 * همین را می‌گفت. یک ساکنِ واردشده با یک حلقه می‌توانست هزاران پیام برای
 * همه‌ی همسایه‌ها بفرستد.
 *
 * ⚠️ نیمه‌ی اول قاعده را روی **فهرستِ واقعیِ مسیرها** می‌سنجد، پس مسیرِ
 * تازه‌ای که فردا بی‌سقف اضافه شود همین‌جا قرمز می‌شود. نیمه‌ی دوم رفتار
 * را با درخواستِ واقعی.
 */
class AbusePreventionTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['messenger_enabled' => true]);
        $this->manager = User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => UserRole::ComplexAdmin,
            'password' => Hash::make('secret123'),
            'is_active' => true,
            'can_message' => true,
        ]);
    }

    /* ── ساختار: هیچ مسیرِ تغییردهنده‌ای بی‌سقف نیست ─────────────────────── */

    /** @return list<string> */
    private function throttlesOf(\Illuminate\Routing\Route $route): array
    {
        return array_values(array_filter(
            $route->gatherMiddleware(),
            fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'),
        ));
    }

    public function test_every_api_route_carries_the_global_cap(): void
    {
        $api = array_filter(
            iterator_to_array(Route::getRoutes()),
            fn ($route) => str_starts_with($route->uri(), 'api/'),
        );

        // ضدِ پوچی: هر دو پیشوند و صدها مسیر
        $this->assertGreaterThan(300, count($api));

        $missing = array_map(
            fn ($route) => $route->uri(),
            array_filter($api, fn ($route) => ! in_array('throttle:api', $this->throttlesOf($route), true)),
        );

        $this->assertSame([], array_values($missing));
    }

    /**
     * بیرون از `/api` هم: هر مسیرِ تغییردهنده‌ی وب سقفِ ویژه دارد.
     * (شروعِ پرداخت، خریدِ اشتراک و بازگشتِ بانک.)
     */
    public function test_every_state_changing_web_route_is_throttled(): void
    {
        /*
         * ⚠️ تنها استثنا مسیرِ خودِ لاراول برای آدرسِ آپلودِ امضاشده است
         * (`storage.local.upload`): میدل‌وری ندارد و امضای موقت را درونِ خودش
         * می‌سنجد؛ بدونِ امضای معتبر چیزی نمی‌پذیرد.
         */
        $web = array_filter(
            iterator_to_array(Route::getRoutes()),
            fn ($route) => ! str_starts_with($route->uri(), 'api/')
                && $route->getName() !== 'storage.local.upload'
                && array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) !== [],
        );

        $this->assertGreaterThanOrEqual(4, count($web));

        foreach ($web as $route) {
            $this->assertNotSame([], $this->throttlesOf($route), $route->uri().' سقف ندارد');
        }
    }

    public function test_the_abusable_routes_have_their_own_cap(): void
    {
        $expected = [
            'api.messenger.store' => 'throttle:messenger-send',
            'api.service-requests.store' => 'throttle:service-requests',
            'api.service-requests.comment' => 'throttle:service-requests',
            'api.profile.password' => 'throttle:password-change',
            'api.system.sms.test' => 'throttle:sms-test',
            'payments.online' => 'throttle:payment-start',
            'subscription.checkout' => 'throttle:payment-start',
        ];

        foreach ($expected as $name => $throttle) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains($throttle, $this->throttlesOf($route), $name);
        }
    }

    /* ── رفتار ──────────────────────────────────────────────────────────── */

    /** 🔴 همان مثالِ خودِ بندِ ۷۳: هزاران پیام. */
    public function test_a_user_cannot_flood_the_messenger(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($this->manager)->postJson('/api/v1/messenger', ['body' => "پیامِ {$i}"])->assertSuccessful();
        }

        $this->actingAs($this->manager)->postJson('/api/v1/messenger', ['body' => 'یازدهم'])->assertStatus(429);

        $this->assertSame(10, Message::withoutGlobalScopes()->count());
    }

    /** ⚠️ دو پیشوند یک شمارنده دارند؛ عوض‌کردنِ `/api` و `/api/v1` دورش نمی‌زند. */
    public function test_the_global_write_cap_spans_both_prefixes(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $prefix = $i % 2 ? '/api' : '/api/v1';
            $this->actingAs($this->manager)->postJson("{$prefix}/notifications/read-all")->assertSuccessful();
        }

        $this->actingAs($this->manager)->postJson('/api/notifications/read-all')->assertStatus(429);
        $this->actingAs($this->manager)->postJson('/api/v1/notifications/read-all')->assertStatus(429);

        // خواندن سقفِ جدای خودش را دارد و هنوز باز است
        $this->actingAs($this->manager)->getJson('/api/v1/me')->assertOk();
    }

    public function test_one_users_flood_does_not_block_another(): void
    {
        $other = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true,
        ]);

        for ($i = 1; $i <= 61; $i++) {
            $this->actingAs($this->manager)->postJson('/api/v1/notifications/read-all');
        }

        $this->actingAs($this->manager)->postJson('/api/v1/notifications/read-all')->assertStatus(429);
        $this->actingAs($other)->postJson('/api/v1/notifications/read-all')->assertSuccessful();
    }

    /** نشستِ دزدیده‌شده نباید بتواند رمزِ فعلی را حدس بزند. */
    public function test_the_current_password_cannot_be_guessed(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($this->manager)->putJson('/api/v1/profile/password', [
                'current_password' => "wrong-{$i}",
                'password' => 'NewPassw0rd!x',
                'password_confirmation' => 'NewPassw0rd!x',
            ])->assertStatus(422);
        }

        $this->actingAs($this->manager)->putJson('/api/v1/profile/password', [
            'current_password' => 'secret123',
            'password' => 'NewPassw0rd!x',
            'password_confirmation' => 'NewPassw0rd!x',
        ])->assertStatus(429);

        $this->assertTrue(Hash::check('secret123', $this->manager->fresh()->password));
    }

    public function test_starting_payments_in_a_loop_is_capped(): void
    {
        config(['subscription.gateway' => 'sandbox']);

        for ($i = 1; $i <= 6; $i++) {
            $this->actingAs($this->manager)->post('/subscription/checkout', ['plan' => 'pro'])->assertRedirect();
        }

        $this->actingAs($this->manager)->post('/subscription/checkout', ['plan' => 'pro'])->assertStatus(429);
    }
}
