<?php

namespace Tests\Feature;

use App\Enums\ComplexType;
use App\Enums\SubscriptionPlan;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscription\PlanGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use LogicException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * 🔴 ادمینِ کل اشتراکِ آنلاینِ پرداخت‌نشده را فعال نمی‌کند — از هیچ راهی (مرحلهٔ ۱۲ · فاز ۲؛ بندِ ۲ ِ تصمیمِ قطعی).
 *
 * ۱۲/۰ بازتولید کرد: دکمه‌ی «تأیید» ِ صفِ ادمینِ کل تمدیدِ آنلاینِ رهاشده را با کدِ رهگیریِ ساختگیِ `MAN-…` فعال
 * کرد. حالا سه لایه: مسیرهای تأیید و رد بازنشسته‌اند (۴۱۰)؛ هیچ مسیرِ نوشتنیِ دیگری برای اشتراک جز ابزارِ جدای
 * «اهدای پلن» نیست؛ و خودِ مدل فعال‌سازیِ آنلاین را بیرون از مسیرِ پرداختِ تأییدشده رد می‌کند.
 */
class SubscriptionActivationTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withConfirmedPassword();
        config(['subscription.gateway' => 'sandbox']);

        $this->complex = Complex::factory()->create(['type' => ComplexType::Residential]);
        $this->manager = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09125557001']);
        $this->super = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true, 'phone' => '09125557002']);
    }

    private function unpaidRenewal(): Subscription
    {
        $this->actingAs($this->manager)->post('/subscription/checkout', ['plan' => 'pro'])->assertRedirect();

        return Subscription::where('method', 'online')->where('status', 'pending')->latest('id')->firstOrFail();
    }

    /** 🔴 بازتولیدِ ۱۲/۰، این بار رد: تأیید، رد، و صف فقط دیدنی. */
    public function test_the_super_admin_cannot_activate_an_unpaid_online_renewal(): void
    {
        $pending = $this->unpaidRenewal();

        $queue = $this->actingAs($this->super)->getJson('/api/v1/system/subscriptions')->assertOk();
        $this->assertContains($pending->id, collect($queue->json('pending'))->pluck('id'), 'پایش هنوز دیده می‌شود.');

        foreach (['approve', 'reject'] as $action) {
            $this->actingAs($this->super)->postJson("/api/v1/system/subscriptions/{$pending->id}/{$action}", ['note' => 'x', 'tracking_code' => 'MAN-1', 'status' => 'active'])
                ->assertStatus(410)
                ->assertJsonPath('code', 'subscription.online_only');
        }

        $fresh = $pending->fresh();
        $this->assertSame(['pending', null, null], [$fresh->status, $fresh->tracking_code, $fresh->paid_at]);
        $this->assertNull(app(PlanGate::class)->activeSubscription($this->complex));
    }

    /** «اهدای پلن» ابزارِ جدای سکوست: ردیفِ تازه‌ی `manual` و رایگان می‌سازد و ردیفِ آنلاینِ پرداخت‌نشده را فعال نمی‌کند. */
    public function test_granting_a_plan_never_activates_the_unpaid_online_row(): void
    {
        $pending = $this->unpaidRenewal();
        $plan = Plan::create(['name' => 'هدیه', 'slug' => 'gift', 'price' => 100000, 'months' => 1, 'is_active' => true]);

        $this->actingAs($this->super)->postJson('/api/v1/system/plans/grant', ['complex_id' => $this->complex->id, 'plan_id' => $plan->id])
            ->assertCreated();

        $this->assertSame('pending', $pending->fresh()->status);
        $granted = Subscription::where('method', 'manual')->sole();
        $this->assertSame(['active', 0], [$granted->status, (int) $granted->amount]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'subscription.granted', 'subject_id' => $granted->id]);
    }

    /** 🔴 لایه‌ی آخر: خودِ مدل — تغییرِ وضعیت، کدِ رهگیریِ دستی، عوض‌کردنِ روش، و ساختنِ اشتراکِ آنلاینِ فعال. */
    public function test_the_model_refuses_every_activation_outside_the_verified_path(): void
    {
        $pending = $this->unpaidRenewal();

        $attempts = [
            'وضعیت' => fn () => $pending->fresh()->update(['status' => 'active']),
            'کدِ رهگیریِ دستی' => fn () => $pending->fresh()->update(['status' => 'active', 'tracking_code' => 'MAN-XYZ', 'paid_at' => now(), 'ends_at' => now()->addMonth()]),
            'دستی کردنِ روش' => fn () => $pending->fresh()->update(['method' => 'manual', 'status' => 'active']),
            'ساختنِ آنلاینِ فعال' => fn () => Subscription::create([
                'complex_id' => $this->complex->id, 'plan' => SubscriptionPlan::Pro, 'status' => 'active', 'method' => 'online',
                'amount' => 1, 'months' => 1, 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'tracking_code' => 'X',
            ]),
            'رسیدِ در انتظار ← فعال' => fn () => Subscription::create([
                'complex_id' => $this->complex->id, 'plan' => SubscriptionPlan::Pro, 'status' => 'pending', 'method' => 'receipt', 'amount' => 1, 'months' => 1,
            ])->update(['status' => 'active']),
        ];

        foreach ($attempts as $label => $attempt) {
            try {
                $attempt();
                $this->fail("«{$label}» اشتراک را فعال کرد.");
            } catch (LogicException) {
                // درست
            }
        }

        $this->assertSame(0, Subscription::where('status', 'active')->count());
    }

    /**
     * هیچ مسیرِ نوشتنیِ دیگری برای اشتراک نیست: فهرست از روتر، نه دستی — مسیرِ تازه‌ای که فردا اضافه شود این‌جا
     * قرمز می‌کند تا کسی تصمیم بگیرد با این نگهبان چه کند.
     */
    public function test_every_write_route_that_touches_subscriptions_is_known(): void
    {
        $writes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/') && array_diff($route->methods(), ['GET', 'HEAD']) !== [])
            ->filter(fn ($route) => preg_match('/subscription|plans/', $route->uri()) === 1)
            ->map(fn ($route) => implode('|', array_diff($route->methods(), ['HEAD'])).' '.substr($route->uri(), strlen('api/v1/')))
            ->sort()->values()->all();

        $this->assertSame([
            'DELETE system/plans/{plan}',
            'PATCH system/plans/{plan}/toggle',
            'POST subscription/receipt',                              // ۴۱۰
            'POST subscription/{subscription}/cancel',                // لغوِ اشتراکِ خودِ مجتمع، نه فعال‌سازی
            'POST system/plans',
            'POST system/plans/grant',                                // اهدا: ردیفِ تازه‌ی manual
            'POST system/plans/revoke',                               // غیرفعال‌سازی
            'POST system/subscriptions/{subscription}/approve',       // ۴۱۰
            'POST system/subscriptions/{subscription}/reject',        // ۴۱۰
            'PUT system/plans/{plan}',
        ], $writes);
    }

    /** و هیچ کدی با query builder اشتراکی را فعال نمی‌کند (نگهبانِ مدل آن را نمی‌بیند). */
    public function test_no_code_activates_a_subscription_behind_the_models_back(): void
    {
        $allowed = ['app/Services/Subscription/SubscriptionRenewal.php', 'app/Services/Subscription/ComplexPurchase.php', 'app/Http/Controllers/Api/System/PlanController.php'];
        $offenders = [];

        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $source = $file->getContents();
            $relative = 'app/'.str_replace('\\', '/', $file->getRelativePathname());
            if (! preg_match('/Subscription|subscriptions/', $source) || in_array($relative, $allowed, true)) {
                continue;
            }
            if (preg_match("/'status'\\s*=>\\s*'active'/", $source)) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, 'کدی بیرون از مسیرِ پرداختِ تأییدشده اشتراکی را فعال می‌کند.');

        // و صفحه‌ی ادمینِ کل هیچ فراخوانیِ تأیید یا رد ندارد
        $page = (string) file_get_contents(resource_path('js/features/system/SubscriptionsPage.tsx'));
        $this->assertDoesNotMatchRegularExpression('#/approve|/reject#', $page);

        // و هیچ صفحه‌ای مسیرِ بازنشسته‌ی رسیدِ اشتراک را صدا نمی‌زند (رسیدِ قبض جداست: `pay/{bill}/receipt`)
        $callers = [];
        foreach (Finder::create()->files()->in(resource_path('js'))->name(['*.ts', '*.tsx']) as $file) {
            if (str_contains($file->getContents(), "'/subscription/receipt'")) {
                $callers[] = $file->getRelativePathname();
            }
        }
        $this->assertSame([], $callers);
    }
}
