<?php

namespace Tests\Feature;

use App\Enums\ComplexType;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\SessionContext;
use App\Support\TenantContext;
use Database\Seeders\PreviewSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * دادهٔ نمونه‌ی Preview و جداییِ آن از فهرست و آمارِ عملیاتی (مرحلهٔ ۱۳ · فاز ۱؛ «داشبورد های آزمایشی»).
 *
 * ساختار از متنِ همان سند: سه حساب + سوپر ادمینِ آزمایشی؛ مدیر با پنج عضویتِ مستقل (الف تا ث)؛ واحدِ مسکونی با پلنِ
 * پیشرفته؛ شش ترکیبِ نوع × پلن. نقشِ هر زمینه از عضویتِ همان مجتمع، نه از حساب.
 */
class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PreviewSeeder::class);
    }

    /* ── بندِ ۲: سه حساب و پنج زمینه ───────────────────────────────────── */

    public function test_the_four_accounts_and_the_five_manager_contexts_match_the_spec(): void
    {
        $accounts = User::withoutGlobalScopes()->whereNotNull('username')->get()->keyBy('username');
        $this->assertEqualsCanonicalizing(
            [PreviewSeeder::ADMIN, PreviewSeeder::INITIAL, PreviewSeeder::MANAGER, PreviewSeeder::UNIT],
            $accounts->keys()->all(),
        );
        $this->assertTrue($accounts[PreviewSeeder::ADMIN]->isSuperAdmin());
        $this->assertSame(0, $this->live($accounts[PreviewSeeder::INITIAL])->count(), 'حسابِ اولیه عضویت دارد.');

        // واحد: دقیقاً یک مجتمعِ مسکونی با پلنِ پیشرفته (بندِ ۲.۲)
        $unit = $this->live($accounts[PreviewSeeder::UNIT])->get();
        $this->assertCount(1, $unit);
        $this->assertSame([ComplexType::Residential, UserRole::Owner, 'business'], $this->shape($unit->first()));

        // مدیر: یک Account، پنج عضویتِ مستقل با نقش و نوع و پلنِ دقیقِ جدول (بندِ ۲.۳)
        $manager = $accounts[PreviewSeeder::MANAGER];
        $contexts = $this->live($manager)->get()->map(fn (ComplexMembership $m) => $this->shape($m))->all();
        $this->assertEqualsCanonicalizing([
            [ComplexType::Residential, UserRole::ComplexAdmin, 'basic'],
            [ComplexType::Residential, UserRole::ComplexAdmin, 'plus'],
            [ComplexType::Commercial, UserRole::ComplexAdmin, 'business'],
            [ComplexType::Commercial, UserRole::Owner, 'basic'],
            [ComplexType::Residential, UserRole::Tenant, 'plus'],
        ], $contexts);

        // پنج زمینه پنج حساب نیستند
        $this->assertSame(1, User::withoutGlobalScopes()->where('username', PreviewSeeder::MANAGER)->count());
    }

    public function test_the_six_type_and_plan_combinations_are_all_covered(): void
    {
        $combos = Complex::where('is_demo', true)->get()
            ->map(fn (Complex $c) => $c->type->value.'+'.$this->planOf($c))
            ->unique()->values()->all();

        // هر شش ترکیب، و هیچ ترکیبِ دیگری (بندِ ۳)
        $this->assertEqualsCanonicalizing([
            'residential+basic', 'residential+plus', 'residential+business',
            'commercial+basic', 'commercial+plus', 'commercial+business',
        ], $combos);
    }

    public function test_everything_seeded_is_marked_demo_and_has_no_phone(): void
    {
        $this->assertSame(0, User::withoutGlobalScopes()->where('is_demo', false)->count());
        $this->assertSame(0, Complex::where('is_demo', false)->count());
        $this->assertSame(0, User::withoutGlobalScopes()->whereNotNull('phone')->count());
        $this->assertSame(0, Subscription::where('method', '!=', 'manual')->count(), 'اشتراکی با پرداخت ساخته شد.');
        $this->assertSame(0, (int) Subscription::sum('amount'), 'اشتراکِ نمونه مبلغ دارد.');
    }

    /* ── بندِ ۷: نقش از عضویتِ همان زمینه ─────────────────────────────── */

    public function test_the_manager_role_does_not_leak_into_the_unit_contexts(): void
    {
        $manager = User::withoutGlobalScopes()->where('username', PreviewSeeder::MANAGER)->firstOrFail();

        $this->inContext($manager, 'demo-a')->getJson('/api/v1/residents')->assertOk();

        foreach (['demo-d', 'demo-e'] as $slug) {
            $this->inContext($manager, $slug)->getJson('/api/v1/residents')->assertForbidden();
        }
    }

    /* ── بندِ ۶.۳: idempotent، بی بازنشانیِ رمز ──────────────────────── */

    public function test_seeding_again_creates_nothing_and_keeps_a_changed_password(): void
    {
        $unit = User::withoutGlobalScopes()->where('username', PreviewSeeder::UNIT)->firstOrFail();
        $unit->forceFill(['password' => Hash::make('changed-by-the-reviewer')])->save();

        $before = [User::withoutGlobalScopes()->count(), Complex::count(), ComplexMembership::count(), Subscription::count()];

        $again = new PreviewSeeder;
        $again->run();

        $this->assertSame($before, [User::withoutGlobalScopes()->count(), Complex::count(), ComplexMembership::count(), Subscription::count()]);
        $this->assertSame([], $again->issued(), 'رمزِ حسابِ موجود دوباره صادر شد.');
        $this->assertTrue(Hash::check('changed-by-the-reviewer', $unit->fresh()->password));
    }

    /**
     * بندِ ۵ و ۸.۳: هر چهار حساب رمز را از مسیرِ واقعیِ «اطلاعاتِ کاربری» عوض می‌کنند؛ hash در سرور، ورودِ بعدی با رمزِ
     * تازه، رمزِ قبلی رد، حساب‌های دیگر دست‌نخورده، و شناسه‌ی دستکاری‌شده‌ی درخواست بی‌اثر.
     */
    public function test_each_demo_account_changes_only_its_own_password_through_the_real_flow(): void
    {
        $usernames = [PreviewSeeder::ADMIN, PreviewSeeder::INITIAL, PreviewSeeder::MANAGER, PreviewSeeder::UNIT];
        foreach ($usernames as $username) {
            User::withoutGlobalScopes()->where('username', $username)->firstOrFail()
                ->forceFill(['password' => Hash::make('Initial123')])->save();
        }

        foreach ($usernames as $i => $username) {
            $account = User::withoutGlobalScopes()->where('username', $username)->firstOrFail();
            $others = User::withoutGlobalScopes()->whereIn('username', array_diff($usernames, [$username]))->pluck('password', 'id');

            $this->actingAs($account)->putJson('/api/v1/profile/password', [
                'current_password' => 'Initial123',
                'password' => "Changed{$i}99",
                'password_confirmation' => "Changed{$i}99",
                // شناسه‌ی حسابِ دیگر در بدنه هیچ اثری ندارد
                'user_id' => $others->keys()->first(),
            ])->assertOk();

            $fresh = $account->fresh();
            $this->assertTrue(Hash::check("Changed{$i}99", $fresh->password));
            $this->assertStringNotContainsString("Changed{$i}99", (string) $fresh->getRawOriginal('password'), 'رمز بی hash ذخیره شد.');
            $this->assertEquals($others, User::withoutGlobalScopes()->whereIn('id', $others->keys())->pluck('password', 'id'), 'رمزِ حسابِ دیگری عوض شد.');

            $this->app['auth']->forgetGuards();
            $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => $username, 'password' => 'Initial123'])
                ->assertStatus(422);
            $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => $username, 'password' => "Changed{$i}99"])
                ->assertOk()->assertJsonPath('user.id', $account->id);
            $this->postJson('/api/v1/logout')->assertOk();
        }
    }

    public function test_the_seeder_refuses_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        (new PreviewSeeder)->run();
    }

    /* ── بندِ ۴: فهرست و آمارِ سوپر ادمین ─────────────────────────────── */

    public function test_super_admin_lists_and_stats_leave_demo_data_out_by_default(): void
    {
        $real = Complex::factory()->create();
        $admin = $this->realAdmin();

        $members = $this->actingAs($admin)->getJson('/api/v1/system/members')->assertOk();
        $this->assertSame([$admin->id], collect($members->json('data'))->pluck('id')->all(), 'دادهٔ آزمایشی در فهرستِ عادیِ کاربران آمد.');

        $withDemo = $this->actingAs($admin)->getJson('/api/v1/system/members?include_demo=1')->assertOk();
        $this->assertGreaterThan(1, $withDemo->json('meta.total'));
        $this->assertTrue(collect($withDemo->json('data'))->contains('isDemo', true));

        $complexes = $this->actingAs($admin)->getJson('/api/v1/system/complexes')->assertOk();
        $this->assertSame([$real->id], collect($complexes->json('data'))->pluck('id')->all());
        // شش زمینه‌ی سند + مجتمعِ ششمِ ترکیبِ «تجاری + حرفه‌ای» (پ-۵) + مجتمعِ واقعی
        $this->assertSame(8, $this->actingAs($admin)->getJson('/api/v1/system/complexes?include_demo=1')->json('meta.total'));

        $stats = $this->actingAs($admin)->getJson('/api/v1/system/stats')->assertOk();
        $this->assertSame(1, $stats->json('complexes.total'));
        $this->assertSame(1, $stats->json('people.total'));
        $this->assertSame(0, $stats->json('complexes.units'));
        $this->assertSame(0, $stats->json('money.activeSubscriptions'));

        $statsWithDemo = $this->actingAs($admin)->getJson('/api/v1/system/stats?include_demo=1')->assertOk();
        $this->assertSame(8, $statsWithDemo->json('complexes.total'));
    }

    public function test_the_demo_section_lists_the_three_demo_accounts_once_each(): void
    {
        $admin = User::withoutGlobalScopes()->where('username', PreviewSeeder::ADMIN)->firstOrFail();

        $section = collect($this->actingAs($admin)->getJson('/api/v1/system/members')->assertOk()->json('demoAccounts'))
            ->keyBy('username');

        $this->assertEqualsCanonicalizing([PreviewSeeder::INITIAL, PreviewSeeder::MANAGER, PreviewSeeder::UNIT], $section->keys()->all());
        $this->assertSame(5, $section[PreviewSeeder::MANAGER]['communities']);
        $this->assertSame(1, $section[PreviewSeeder::UNIT]['communities']);
        $this->assertSame(0, $section[PreviewSeeder::INITIAL]['communities']);
    }

    /** @return Builder<ComplexMembership> */
    private function live(User $user)
    {
        return ComplexMembership::where('user_id', $user->id)->where('status', ComplexMembership::ACTIVE);
    }

    /** @return array{0: ComplexType, 1: UserRole, 2: string|null} */
    private function shape(ComplexMembership $membership): array
    {
        $complex = Complex::findOrFail($membership->complex_id);

        return [$complex->type, $membership->role, $this->planOf($complex)];
    }

    private function planOf(Complex $complex): ?string
    {
        $planId = Subscription::where('complex_id', $complex->id)->where('status', 'active')->value('plan_id');

        return $planId === null ? null : Plan::find($planId)?->slug;
    }

    private function inContext(User $user, string $slug): static
    {
        $complex = Complex::where('slug', $slug)->firstOrFail();
        app(TenantContext::class)->set(null);
        $this->flushSession();
        $this->withCredentials();
        $this->withCookie(SessionContext::COOKIE, $user->id.':'.$complex->id);

        return $this->actingAs(User::withoutGlobalScopes()->findOrFail($user->id));
    }

    private function realAdmin(): User
    {
        $admin = (new User)->forceFill([
            'name' => 'سوپر ادمینِ واقعی', 'phone' => '09120000009', 'role' => UserRole::SuperAdmin,
            'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
        $admin->save();

        return $admin;
    }
}
