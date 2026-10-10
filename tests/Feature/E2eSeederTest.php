<?php

namespace Tests\Feature;

use App\Enums\AccountState;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Database\Seeders\DemoSeeder;
use Database\Seeders\E2eSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * پیش‌شرطِ سفرِ «پرداخت از کیف» در مرورگر (مرحلهٔ ۵ · فاز ۸ · بندِ ۶۴).
 *
 * 🔴 این seeder پول در کیف می‌گذارد؛ پس دو چیزش حیاتی است: روی محصول اجرا
 * نشود، و اجرای دوباره اعتبار را دوبرابر نکند.
 */
class E2eSeederTest extends TestCase
{
    use RefreshDatabase;

    private function balanceOf(string $phone): float
    {
        $owner = User::withoutGlobalScopes()->where('phone', $phone)->firstOrFail();

        return app(WalletService::class)->balance($owner);
    }

    public function test_every_engine_owner_gets_a_wallet_that_covers_a_bill(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(E2eSeeder::class);

        foreach (E2eSeeder::OWNERS as $phone) {
            $this->assertEquals(E2eSeeder::CREDIT, $this->balanceOf($phone), "کیفِ {$phone} پر نشد.");
        }
    }

    public function test_running_it_again_does_not_double_the_credit(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(E2eSeeder::class);
        $this->seed(E2eSeeder::class);

        foreach (E2eSeeder::OWNERS as $phone) {
            $this->assertEquals(E2eSeeder::CREDIT, $this->balanceOf($phone));
        }
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->seed(DemoSeeder::class);
        $this->app['env'] = 'production';

        // ⚠️ مستقیم، نه `seed()`: فرمانِ db:seed روی محصول تأییدِ تعاملی می‌خواهد
        try {
            $this->app->call([new E2eSeeder, 'run']);
            $this->fail('E2eSeeder روی محصول اجرا شد.');
        } catch (RuntimeException) {
            // انتظار همین است
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertEquals(0, $this->balanceOf(E2eSeeder::OWNERS[0]));
    }

    /**
     * ⚠️ فهرستِ مالک‌ها باید با آزمونِ مرورگر یکی بماند، وگرنه کیفِ اشتباه پر می‌شود.
     *
     * 🔴 خرابکاری نشان داد «شماره در فایل هست» کافی نیست: شماره‌ی پشتوانه‌ی
     * `?? '09121111105'` هم در همان فایل است. پس نگاشتِ موتور ← شماره خوانده
     * می‌شود.
     */
    public function test_the_owners_match_the_browser_journey(): void
    {
        $spec = (string) file_get_contents(base_path('tests/e2e/financial-journey.spec.ts'));

        $this->assertSame(1, preg_match('/const OWNERS[^{]*\{([^}]*)\}/', $spec, $block), 'نگاشتِ OWNERS در آزمونِ مرورگر پیدا نشد.');
        preg_match_all("/(chromium|firefox|webkit):\\s*'(\\d+)'/", $block[1], $pairs);

        $this->assertSame(['chromium', 'firefox', 'webkit'], $pairs[1]);
        $this->assertSame(E2eSeeder::OWNERS, $pairs[2]);
    }

    /**
     * ثبت‌نام‌کننده‌های سفرِ مرورگر (مرحلهٔ ۸ · فاز ۱): بی‌مجتمع ساخته می‌شوند، و
     * اجرای دوباره — پس از آن‌که سفر عضوشان کرد — دوباره بی‌مجتمع و بی‌دعوتشان می‌کند.
     */
    public function test_registrants_start_and_restart_without_a_complex(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(E2eSeeder::class);

        $phone = E2eSeeder::REGISTRANTS[0];
        $user = User::withoutGlobalScopes()->where('phone', $phone)->firstOrFail();
        $this->assertSame(AccountState::Initial, AccountState::of($user));

        // همان چیزی که سفر انجام می‌دهد: دعوت، پذیرش، واحد
        $complex = Complex::query()->firstOrFail();
        $unit = $complex->units()->withoutGlobalScopes()->firstOrFail();
        ComplexInvitation::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'user_id' => $user->id, 'unit_id' => $unit->id,
            'role' => 'tenant', 'status' => ComplexInvitation::ACCEPTED,
        ]);
        $user->update(['complex_id' => $complex->id]);
        $user->units()->withoutGlobalScopes()->attach($unit->id, ['relation' => 'tenant', 'complex_id' => $complex->id]);

        $this->seed(E2eSeeder::class);

        $user->refresh();
        $this->assertSame(AccountState::Initial, AccountState::of($user));
        $this->assertSame(0, $user->units()->withoutGlobalScopes()->count());
        $this->assertSame(0, ComplexInvitation::withoutGlobalScopes()->where('user_id', $user->id)->count());
    }

    /** نگاشتِ موتور ← شماره در سفرِ ثبت‌نام‌کننده همان `REGISTRANTS` است */
    public function test_the_registrants_match_the_browser_journey(): void
    {
        $spec = (string) file_get_contents(base_path('tests/e2e/registrant-journey.spec.ts'));

        $this->assertSame(1, preg_match('/const REGISTRANTS[^{]*\{([^}]*)\}/', $spec, $block), 'نگاشتِ REGISTRANTS در آزمونِ مرورگر پیدا نشد.');
        preg_match_all("/(chromium|firefox|webkit):\\s*'(\\d+)'/", $block[1], $pairs);

        $this->assertSame(['chromium', 'firefox', 'webkit'], $pairs[1]);
        $this->assertSame(E2eSeeder::REGISTRANTS, $pairs[2]);
    }
}
