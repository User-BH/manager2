<?php

namespace Tests\Feature;

use App\Enums\ComplexType;
use App\Enums\PlanTier;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\ComplexUpgrader;
use App\Services\Auth\AdminTwoFactor;
use App\Services\Auth\Totp;
use App\Support\SessionContext;
use App\Support\TenantContext;
use Database\Seeders\PreviewSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * دادهٔ نمونه‌ی Preview و جداییِ آن از فهرست و آمارِ عملیاتی (مرحلهٔ ۱۳ · فاز ۱؛ ساختارِ نهاییِ «دستورِ یکپارچه‌ی ادامه‌ی
 * مرحلهٔ ۱۳»، ۲۰۲۶-۱۰-۲۵، بخش‌های اول و دوم).
 *
 * ساختار از متنِ «حفره ها۲» (۲۰۲۶-۱۰-۲۶، بخشِ ۴): حسابِ ثبت‌نامِ اولیه بی عضویت، حسابِ تک‌مجتمعی (مسکونی · پیشرفته ·
 * واحد)، حسابِ چندمجتمعی با **دقیقاً** پنج عضویتِ جدولِ بندِ ۴.۳، سوپر ادمینِ آزمایشی. نقشِ هر زمینه از عضویتِ همان
 * مجتمع، نه از حساب.
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

    /* ── «حفره ها۲» بخشِ ۴: چهار حساب، یکی برای هر سناریو ─────────────── */

    public function test_there_are_exactly_four_demo_accounts_one_per_scenario(): void
    {
        $accounts = User::withoutGlobalScopes()->whereNotNull('username')->get()->keyBy('username');

        $this->assertEqualsCanonicalizing(PreviewSeeder::ACCOUNTS, $accounts->keys()->all());
        $this->assertTrue($accounts[PreviewSeeder::ADMIN]->isSuperAdmin());
        // بی مجتمع فقط حسابِ اولیه (و سوپر ادمین که عضوِ مجتمع نمی‌شود)
        $this->assertSame([PreviewSeeder::INITIAL], User::withoutGlobalScopes()->whereNotNull('username')->whereNull('complex_id')
            ->where('role', '!=', UserRole::SuperAdmin->value)->pluck('username')->all());
        $this->assertSame('داشبوردِ آزمایشیِ ثبت‌نامِ اولیه', $accounts[PreviewSeeder::INITIAL]->name);
        $this->assertSame('داشبوردِ آزمایشیِ تک‌مجتمعی', $accounts[PreviewSeeder::SINGLE]->name);
        $this->assertSame('داشبوردِ آزمایشیِ چندمجتمعی', $accounts[PreviewSeeder::MULTI]->name);
    }

    /**
     * بندِ ۴.۱: Account ِ مستقل بی هیچ عضویت (نه فعال، نه معلق، نه درخواستِ در انتظار)، نقشِ ثبت‌نامِ واقعی؛ ورود با نام
     * کاربری و رمز، بی پیامک، به داشبوردِ اولیه — و پس از خروج و ورودِ دوباره همان‌جا (state حفظ می‌شود).
     */
    public function test_the_initial_account_has_no_membership_and_lands_on_the_initial_dashboard_after_every_login(): void
    {
        $initial = $this->account(PreviewSeeder::INITIAL);

        $this->assertNull($initial->complex_id);
        $this->assertSame(UserRole::Owner, $initial->role);
        $this->assertNull($initial->phone);
        $this->assertSame(0, ComplexMembership::where('user_id', $initial->id)->count(), 'حسابِ اولیه عضویتِ جعلی گرفت.');
        $initial->forceFill(['password' => Hash::make('Initial123')])->save();

        foreach ([1, 2] as $round) {
            $login = $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => PreviewSeeder::INITIAL, 'password' => 'Initial123'])
                ->assertOk()->assertJsonPath('user.id', $initial->id);
            $this->assertNull($login->json('requiresOtp'), 'حسابِ آزمایشی کدِ پیامکی خواست.');
            $this->assertSame('initial', $this->getJson('/api/v1/me')->assertOk()->json('user.dashboard'), "ورودِ {$round} به داشبوردِ اولیه نرسید.");
            // فقط‌خواندنی: نوشتن روی داده‌ی مجتمع رد، بی ساختنِ عضویت
            $this->postJson('/api/v1/units', ['unit_number' => '9'])->assertForbidden();
            $this->postJson('/api/v1/logout')->assertOk();
            $this->app['auth']->forgetGuards();
        }

        $this->assertSame(0, ComplexMembership::where('user_id', $initial->id)->count());
    }

    /**
     * بندِ ۱۸.۵: مجتمعی که حسابِ آزمایشی از مسیرِ واقعی می‌سازد — خریدِ حسابِ اولیه، یا ساختِ سوپر ادمینِ آزمایشی — خودش
     * دادهٔ آزمایشی است و در فهرست و آمارِ عادی نمی‌آید؛ مجتمعِ کاربرِ واقعی نشانگر نمی‌گیرد.
     */
    public function test_a_community_built_by_a_demo_account_is_demo_data(): void
    {
        $bought = app(ComplexUpgrader::class)->upgrade($this->account(PreviewSeeder::INITIAL), 'مجتمعِ خریدِ آزمایشی', null, ComplexType::Residential);
        $this->assertTrue($bought->fresh()->is_demo);

        $this->actingAs($this->account(PreviewSeeder::ADMIN))->postJson('/api/v1/system/complexes', [
            'name' => 'مجتمعِ سوپر ادمینِ آزمایشی', 'type' => 'commercial', 'admin_phone' => '09121110001',
        ])->assertSuccessful();
        $this->assertTrue(Complex::where('name', 'مجتمعِ سوپر ادمینِ آزمایشی')->sole()->is_demo);

        $real = (new User)->forceFill(['name' => 'خریدارِ واقعی', 'phone' => '09121110002', 'role' => UserRole::Owner,
            'password' => Hash::make('secret123'), 'is_active' => true]);
        $real->save();
        $this->assertFalse(app(ComplexUpgrader::class)->upgrade($real, 'مجتمعِ واقعی', null, ComplexType::Residential)->fresh()->is_demo);

        $admin = $this->realAdmin();
        $listed = collect($this->actingAs($admin)->getJson('/api/v1/system/complexes')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['مجتمعِ واقعی'], $listed, 'مجتمعِ ساخته‌ی حسابِ آزمایشی در فهرستِ عادی آمد.');
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

        // «دستکاری رد؛ بی زمینه‌ی دوم» (سندِ آزمایشی، بندِ ۲.۲؛ آشتیِ ماتریس در ۱۳/۹): تعویض به مجتمعِ آزمایشیِ دیگر رد،
        // و هیچ عضویتی ساخته نمی‌شود
        $other = Complex::where('slug', 'demo-com-advanced')->firstOrFail();
        $this->inContext($single, PreviewSeeder::SINGLE_CONTEXT)->postJson('/api/v1/account/context', ['complex_id' => $other->id])
            ->assertUnprocessable();
        $this->assertCount(1, $this->live($single)->get());
        $this->assertSame(PreviewSeeder::SINGLE_CONTEXT, Complex::findOrFail($this->live($single)->sole()->complex_id)->slug);
    }

    /**
     * سندِ آزمایشی، بندِ ۳ (آشتیِ ماتریس در ۱۳/۹): هر شش ترکیبِ نوع × سطح در دو حسابِ آزمایشی هست، و قاعده‌ی **واقعیِ**
     * پلن در هر زمینه اعمال می‌شود — دستیارِ پلنِ پیشرفته (۱۳/۸) فقط در زمینه‌های پیشرفته، از همان `/me` ِ سرور.
     */
    public function test_the_six_type_and_tier_combinations_are_covered_and_the_plan_rules_apply(): void
    {
        // عضویت‌ها پیش از هر درخواست: درخواست زمینه‌ی مستأجر را می‌نشاند و `ComplexMembership` دامنه‌ی مستأجر دارد
        $contexts = [];
        foreach ([PreviewSeeder::SINGLE, PreviewSeeder::MULTI] as $username) {
            $account = $this->account($username);
            foreach ($this->live($account)->get() as $membership) {
                $contexts[] = [$account, $membership];
            }
        }

        $combinations = [];
        foreach ($contexts as [$account, $membership]) {
            $complex = Complex::findOrFail($membership->complex_id);
            // `value()` cast نمی‌کند؛ سطح از مدل
            $tier = Plan::where('slug', $this->planOf($complex))->firstOrFail()->tier;
            $combinations[] = $complex->type->value.'·'.$tier?->value;

            $assistant = $this->inContext($account, (string) $complex->slug)->getJson('/api/v1/me')->assertOk()->json('user.assistant');
            $this->assertSame($tier === PlanTier::Advanced, $assistant, "قاعده‌ی پلن در «{$complex->slug}» اعمال نشد.");
        }

        sort($combinations);
        $this->assertSame([
            'commercial·advanced', 'commercial·basic', 'commercial·professional',
            'residential·advanced', 'residential·basic', 'residential·professional',
        ], $combinations);
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

        // «داده‌ی نمونه‌ی کافی در هر زمینه» (سندِ آزمایشی، بندِ ۶.۱؛ آشتیِ ماتریس در ۱۳/۹): هر شش مجتمع واحد، قبض،
        // اطلاعیه و پیام دارد — قابلیت‌های واقعی چیزی برای نشان‌دادن دارند
        foreach (Complex::where('is_demo', true)->get() as $complex) {
            foreach ([Unit::class, Bill::class, Announcement::class, Message::class] as $model) {
                $this->assertGreaterThan(0, $model::withoutGlobalScopes()->where('complex_id', $complex->id)->count(), "«{$complex->slug}» هیچ ".class_basename($model).' ِ نمونه‌ای ندارد.');
            }
        }
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
     * هر چهار حساب (بندِ ۱۸.۴) — حسابِ اولیه‌ی فقط‌خواندنی هم — رمز را از مسیرِ واقعیِ «اطلاعاتِ کاربری» عوض می‌کنند؛ hash
     * در سرور، ورودِ بعدی با رمزِ تازه، رمزِ قبلی رد، حساب‌های دیگر دست‌نخورده، و شناسه‌ی دستکاری‌شده‌ی درخواست بی‌اثر.
     */
    public function test_each_demo_account_changes_only_its_own_password_through_the_real_flow(): void
    {
        $usernames = PreviewSeeder::ACCOUNTS;
        foreach ($usernames as $username) {
            $this->account($username)->forceFill(['password' => Hash::make('Initial123')])->save();
        }
        // هر چه در این آزمون log شود (پیام و context) — رمزِ ساده نباید در log هم باشد (سندِ آزمایشی، بندِ ۸.۳)
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->message.' '.json_encode($e->context, JSON_UNESCAPED_UNICODE);
        });

        foreach ($usernames as $i => $username) {
            $account = $this->account($username);
            $others = User::withoutGlobalScopes()->whereIn('username', array_diff($usernames, [$username]))->pluck('password', 'id');

            $response = $this->actingAs($account)->putJson('/api/v1/profile/password', [
                'current_password' => 'Initial123',
                'password' => "Changed{$i}99",
                'password_confirmation' => "Changed{$i}99",
                // شناسه‌ی حسابِ دیگر در بدنه هیچ اثری ندارد
                'user_id' => $others->keys()->first(),
            ])->assertOk();
            // رمزِ ساده در پاسخ هم نه (سندِ آزمایشی، بندِ ۸.۳)؛ ابطالِ نشست‌ها همان مسیر است: `SessionLifecycleTest`
            $this->assertStringNotContainsString("Changed{$i}99", (string) $response->getContent());

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
        foreach ($usernames as $i => $username) {
            $this->assertStringNotContainsString("Changed{$i}99", implode("\n", $logged), 'رمزِ ساده در log آمد.');
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

    public function test_the_demo_section_lists_the_three_loginable_demo_accounts_once_each(): void
    {
        $admin = $this->account(PreviewSeeder::ADMIN);

        $section = collect($this->actingAs($admin)->getJson('/api/v1/system/members')->assertOk()->json('demoAccounts'))
            ->keyBy('username');

        $this->assertEqualsCanonicalizing([PreviewSeeder::INITIAL, PreviewSeeder::MULTI, PreviewSeeder::SINGLE], $section->keys()->all());
        $this->assertSame(0, $section[PreviewSeeder::INITIAL]['communities']);
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
