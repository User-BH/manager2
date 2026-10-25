<?php

namespace Tests\Feature;

use App\Enums\ComplexType;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Services\Auth\AdminTwoFactor;
use App\Services\Auth\Totp;
use App\Support\SessionContext;
use App\Support\TenantContext;
use Database\Seeders\PreviewSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * دادهٔ نمونه‌ی Preview و جداییِ آن از فهرست و آمارِ عملیاتی (مرحلهٔ ۱۳ · فاز ۱؛ ساختارِ نهاییِ «دستورِ یکپارچه‌ی ادامه‌ی
 * مرحلهٔ ۱۳»، ۲۰۲۶-۱۰-۲۵، بخش‌های اول و دوم).
 *
 * ساختار از متنِ همان سند: حسابِ تک‌مجتمعی (مسکونی · پیشرفته · واحد)، حسابِ چندمجتمعی با **دقیقاً** پنج عضویتِ جدولِ
 * بندِ ۱.۲، سوپر ادمینِ آزمایشی؛ داشبوردِ ثبت‌نامِ اولیه حساب نیست. نقشِ هر زمینه از عضویتِ همان مجتمع، نه از حساب.
 */
class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    /** جدولِ بندِ ۱.۲، به همان ترتیب: نوع، پلن، نقش */
    private const MULTI_TABLE = [
        [ComplexType::Residential, 'basic', UserRole::ComplexAdmin],
        [ComplexType::Residential, 'plus', UserRole::ComplexAdmin],
        [ComplexType::Commercial, 'basic', UserRole::Owner],
        [ComplexType::Commercial, 'plus', UserRole::Owner],
        [ComplexType::Commercial, 'business', UserRole::ComplexAdmin],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PreviewSeeder::class);
    }

    /**
     * ⚠️ درخواست با `env = production` میزبان‌های مجاز را **ایستا** روی `Request` می‌نشاند (`TrustHosts`) و به آزمون‌های
     * بعدیِ همین پردازه نشت می‌کند (همان پاک‌سازیِ `PreviewEnvironmentTest`).
     */
    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    /* ── بندِ ۱: سه حساب، نه چهار ─────────────────────────────────────── */

    public function test_there_are_exactly_three_demo_accounts_and_no_initial_account(): void
    {
        $accounts = User::withoutGlobalScopes()->whereNotNull('username')->get()->keyBy('username');

        $this->assertEqualsCanonicalizing([PreviewSeeder::ADMIN, PreviewSeeder::MULTI, PreviewSeeder::SINGLE], $accounts->keys()->all());
        $this->assertTrue($accounts[PreviewSeeder::ADMIN]->isSuperAdmin());
        // داشبوردِ ثبت‌نامِ اولیه وضعیت است (بندِ ۱.۴): هیچ حسابِ آزمایشیِ واردشدنی‌ای بی مجتمع نیست جز سوپر ادمین
        $this->assertSame(0, User::withoutGlobalScopes()->whereNotNull('username')->whereNull('complex_id')->where('role', '!=', UserRole::SuperAdmin->value)->count());
        $this->assertSame('داشبوردِ آزمایشیِ تک‌مجتمعی', $accounts[PreviewSeeder::SINGLE]->name);
        $this->assertSame('داشبوردِ آزمایشیِ چندمجتمعی', $accounts[PreviewSeeder::MULTI]->name);
    }

    /** بندِ ۱.۱: دقیقاً یک مجتمع، مسکونی، پیشرفته، نقشِ واحد — و داشبوردِ واحد از سرور */
    public function test_the_single_community_account_has_exactly_one_residential_advanced_unit_membership(): void
    {
        $single = $this->account(PreviewSeeder::SINGLE);
        $memberships = $this->live($single)->get();

        $this->assertCount(1, $memberships);
        $this->assertSame([ComplexType::Residential, 'business', UserRole::Owner], $this->shape($memberships->first()));

        $me = $this->inContext($single, PreviewSeeder::SINGLE_CONTEXT)->getJson('/api/v1/me')->assertOk()->json('user');
        $this->assertSame('unit', $me['dashboard']);
        $this->assertCount(1, $me['memberships']);
    }

    /** بندِ ۱.۲: یک Account، دقیقاً پنج عضویت، دقیقاً جدول — نه شش، نه پنج حساب، نه ترکیبِ دیگر */
    public function test_the_multi_community_account_has_exactly_the_five_contexts_of_the_table(): void
    {
        $multi = $this->account(PreviewSeeder::MULTI);

        $contexts = $this->live($multi)->get()->map(fn (ComplexMembership $m) => $this->shape($m))->all();
        $this->assertEqualsCanonicalizing(
            array_map(fn (array $row) => [$row[0], $row[1], $row[2]], self::MULTI_TABLE),
            $contexts,
        );
        $this->assertCount(5, $contexts);
        $this->assertSame(1, User::withoutGlobalScopes()->where('username', PreviewSeeder::MULTI)->count(), 'پنج زمینه پنج حساب شد.');

        // هیچ عضویتِ معلق یا اضافه‌ای هم نیست
        $this->assertSame(5, ComplexMembership::where('user_id', $multi->id)->count());
    }

    /**
     * بندِ ۱.۲: نقشِ هر زمینه مستقل است و با تعویضِ واقعیِ زمینه (`/account/context`) عوض می‌شود؛ مدیرِ یک مجتمع در
     * مجتمعِ دیگر مدیر نیست و داده‌ی زمینه‌ی قبلی در زمینه‌ی تازه نمی‌آید.
     */
    public function test_switching_through_the_five_contexts_applies_each_role_and_isolates_the_data(): void
    {
        $multi = $this->account(PreviewSeeder::MULTI);
        $slugs = array_keys(array_diff_key(PreviewSeeder::CONTEXTS, [PreviewSeeder::SINGLE_CONTEXT => true]));

        foreach ($slugs as $i => $slug) {
            $complex = Complex::where('slug', $slug)->firstOrFail();
            [$type, , $role] = self::MULTI_TABLE[$i];

            $this->as($multi)->postJson('/api/v1/account/context', ['complex_id' => $complex->id])->assertOk()
                ->assertJsonPath('current', $complex->id);

            $me = $this->as($multi)->getJson('/api/v1/me')->assertOk()->json('user');
            $this->assertSame($complex->id, $me['complex']['id']);
            $this->assertSame($type->value, $me['complex']['type']);
            $this->assertSame($role->value, $me['role'], "نقشِ زمینه‌ی {$slug} از عضویتِ خودش نیامد.");
            $this->assertSame($role === UserRole::ComplexAdmin ? 'manager' : 'unit', $me['dashboard']);

            if ($role === UserRole::ComplexAdmin) {
                // مدیر: فقط واحدهای همین مجتمع
                $units = collect($this->as($multi)->getJson('/api/v1/units')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
                $this->assertSame(Unit::withoutGlobalScopes()->where('complex_id', $complex->id)->orderBy('id')->pluck('id')->all(), $units);
            } else {
                // واحد: مسیرِ مدیر بسته؛ صورتحساب‌ها فقط مالِ واحدِ خودش در همین مجتمع
                $this->as($multi)->getJson('/api/v1/units')->assertForbidden();
                $this->as($multi)->getJson('/api/v1/residents')->assertForbidden();
                $bills = collect($this->as($multi)->getJson('/api/v1/my-bills')->assertOk()->json('data'))->pluck('id');
                $own = Bill::withoutGlobalScopes()->where('complex_id', $complex->id)
                    ->whereIn('unit_id', Unit::withoutGlobalScopes()->where('complex_id', $complex->id)->where('unit_number', '1')->pluck('id'))
                    ->pluck('id');
                $this->assertEqualsCanonicalizing($own->all(), $bills->all(), "صورتحسابِ زمینه‌ی دیگری در {$slug} آمد.");
            }
        }
    }

    public function test_everything_seeded_is_marked_demo_and_has_no_phone(): void
    {
        $this->assertSame(0, User::withoutGlobalScopes()->where('is_demo', false)->count());
        $this->assertSame(0, Complex::where('is_demo', false)->count());
        $this->assertSame(0, User::withoutGlobalScopes()->whereNotNull('phone')->count());
        $this->assertSame(0, Subscription::where('method', '!=', 'manual')->count(), 'اشتراکی با پرداخت ساخته شد.');
        $this->assertSame(0, (int) Subscription::sum('amount'), 'اشتراکِ نمونه مبلغ دارد.');
        // شش مجتمع: پنج زمینه‌ی جدول و مجتمعِ تک‌مجتمعی — بی مجتمعِ اضافه
        $this->assertSame(6, Complex::where('is_demo', true)->count());
    }

    /* ── idempotent، بی بازنشانیِ رمز، و کنارگذاشتنِ ساختارِ پیشین ────── */

    public function test_seeding_again_creates_nothing_and_keeps_a_changed_password(): void
    {
        $single = $this->account(PreviewSeeder::SINGLE);
        $single->forceFill(['password' => Hash::make('changed-by-the-reviewer')])->save();

        $before = [User::withoutGlobalScopes()->count(), Complex::count(), ComplexMembership::count(), Subscription::count()];

        $again = new PreviewSeeder;
        $again->run();

        $this->assertSame($before, [User::withoutGlobalScopes()->count(), Complex::count(), ComplexMembership::count(), Subscription::count()]);
        $this->assertSame([], $again->issued(), 'رمزِ حسابِ موجود دوباره صادر شد.');
        $this->assertTrue(Hash::check('changed-by-the-reviewer', $single->fresh()->password));
    }

    /** حسابِ ساختارِ پیشینِ ۱۳/۱ روی پایگاه‌داده‌ی مانده غیرفعال می‌شود — نه حذف — و دیگر وارد نمی‌شود */
    public function test_a_legacy_demo_account_is_retired_not_deleted(): void
    {
        $legacy = (new User)->forceFill([
            'name' => 'حسابِ پیشین', 'username' => 'demo.manager', 'phone' => null, 'role' => UserRole::ComplexAdmin,
            'password' => Hash::make('Legacy123'), 'is_active' => true, 'is_demo' => true,
        ]);
        $legacy->save();

        $again = new PreviewSeeder;
        $again->run();

        $this->assertSame(['demo.manager'], $again->retired());
        $this->assertFalse($legacy->fresh()->is_active);
        $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => 'demo.manager', 'password' => 'Legacy123'])
            ->assertStatus(422);
    }

    /**
     * هر سه حساب رمز را از مسیرِ واقعیِ «اطلاعاتِ کاربری» عوض می‌کنند؛ hash در سرور، ورودِ بعدی با رمزِ تازه، رمزِ قبلی
     * رد، حساب‌های دیگر دست‌نخورده، و شناسه‌ی دستکاری‌شده‌ی درخواست بی‌اثر.
     */
    public function test_each_demo_account_changes_only_its_own_password_through_the_real_flow(): void
    {
        $usernames = [PreviewSeeder::ADMIN, PreviewSeeder::MULTI, PreviewSeeder::SINGLE];
        foreach ($usernames as $username) {
            $this->account($username)->forceFill(['password' => Hash::make('Initial123')])->save();
        }

        foreach ($usernames as $i => $username) {
            $account = $this->account($username);
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

    /* ── بندِ دوم: فهرست و آمارِ سوپر ادمین ───────────────────────────── */

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
        // شش مجتمعِ آزمایشی + مجتمعِ واقعی
        $this->assertSame(7, $this->actingAs($admin)->getJson('/api/v1/system/complexes?include_demo=1')->json('meta.total'));

        $stats = $this->actingAs($admin)->getJson('/api/v1/system/stats')->assertOk();
        $this->assertSame(1, $stats->json('complexes.total'));
        $this->assertSame(1, $stats->json('people.total'));
        $this->assertSame(0, $stats->json('complexes.units'));
        $this->assertSame(0, $stats->json('money.activeSubscriptions'));

        $statsWithDemo = $this->actingAs($admin)->getJson('/api/v1/system/stats?include_demo=1')->assertOk();
        $this->assertSame(7, $statsWithDemo->json('complexes.total'));

        $this->assertTrue($this->actingAs($admin)->getJson('/api/v1/me')->json('user.demoData'));
    }

    /**
     * بندِ دوم: سوپر ادمینِ Production هیچ داده‌ی آزمایشی نمی‌گیرد — حتی اگر داده‌ی نشان‌داری به پایگاه‌داده‌اش رسیده
     * باشد و client پارامترِ `include_demo` را بفرستد. بخشِ «حساب‌های آزمایشی» هم نیست و رابط کلیدش را نشان نمی‌دهد.
     */
    public function test_on_production_include_demo_is_ignored_and_the_demo_section_is_absent(): void
    {
        $real = Complex::factory()->create();
        $admin = $this->realAdmin();
        // سوپر ادمینِ Production بی TOTP به هیچ فهرستی نمی‌رسد (`EnsureAdminTwoFactor`)؛ این‌جا راه‌اندازی‌شده است
        $secret = app(AdminTwoFactor::class)->begin($admin)['secret'];
        $this->assertNotNull(app(AdminTwoFactor::class)->confirm($admin->fresh(), Totp::at($secret, Totp::step())));
        $admin = $admin->fresh();
        $this->app['env'] = 'production';

        foreach (['', '?include_demo=1', '?include_demo=true'] as $query) {
            $members = $this->actingAs($admin)->getJson('/api/v1/system/members'.$query)->assertOk();
            $this->assertSame([$admin->id], collect($members->json('data'))->pluck('id')->all(), "داده‌ی آزمایشی با «{$query}» روی Production آمد.");
            $this->assertSame([], $members->json('demoAccounts'));

            $complexes = $this->actingAs($admin)->getJson('/api/v1/system/complexes'.$query)->assertOk();
            $this->assertSame([$real->id], collect($complexes->json('data'))->pluck('id')->all());

            $stats = $this->actingAs($admin)->getJson('/api/v1/system/stats'.$query)->assertOk();
            $this->assertSame(1, $stats->json('complexes.total'));
            $this->assertSame(1, $stats->json('people.total'));
        }

        // جست‌وجو هم: نامِ حسابِ آزمایشی پیدا نمی‌شود
        $search = $this->actingAs($admin)->getJson('/api/v1/system/members?include_demo=1&q='.urlencode('آزمایشی'))->assertOk();
        $this->assertSame([], $search->json('data'));

        $this->assertFalse($this->actingAs($admin)->getJson('/api/v1/me')->json('user.demoData'));
    }

    public function test_the_demo_section_lists_the_two_loginable_demo_accounts_once_each(): void
    {
        $admin = $this->account(PreviewSeeder::ADMIN);

        $section = collect($this->actingAs($admin)->getJson('/api/v1/system/members')->assertOk()->json('demoAccounts'))
            ->keyBy('username');

        $this->assertEqualsCanonicalizing([PreviewSeeder::MULTI, PreviewSeeder::SINGLE], $section->keys()->all());
        $this->assertSame(5, $section[PreviewSeeder::MULTI]['communities']);
        $this->assertSame(1, $section[PreviewSeeder::SINGLE]['communities']);
        $this->assertSame('داشبوردِ آزمایشیِ چندمجتمعی', $section[PreviewSeeder::MULTI]['name']);
    }

    private function account(string $username): User
    {
        return User::withoutGlobalScopes()->where('username', $username)->firstOrFail();
    }

    /** @return Builder<ComplexMembership> */
    private function live(User $user)
    {
        return ComplexMembership::where('user_id', $user->id)->where('status', ComplexMembership::ACTIVE);
    }

    /** @return array{0: ComplexType, 1: string|null, 2: UserRole} */
    private function shape(ComplexMembership $membership): array
    {
        $complex = Complex::findOrFail($membership->complex_id);

        return [$complex->type, $this->planOf($complex), $membership->role];
    }

    private function planOf(Complex $complex): ?string
    {
        $planId = Subscription::where('complex_id', $complex->id)->where('status', 'active')->value('plan_id');

        return $planId === null ? null : Plan::find($planId)?->slug;
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs(User::withoutGlobalScopes()->findOrFail($user->id));
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
