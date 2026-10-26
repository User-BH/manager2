<?php

namespace Tests\Feature;

use App\Enums\ComplexType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\ComplexMembership;
use App\Models\UnitTenure;
use App\Models\User;
use App\Services\Account\ComplexUpgrader;
use App\Services\Account\Membership;
use App\Services\Account\MembershipLedger;
use App\Support\Preview;
use Database\Seeders\PreviewSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * بازگرداندنِ کنترل‌شده‌ی یک حسابِ آزمایشی به سناریوی خودش — «حفره ها۲» (۲۰۲۶-۱۰-۲۶)، بندهای ۴.۱ و ۴.۶:
 * «Reset نباید بدون اجازه رمز تغییرکرده را برگرداند یا داده‌های غیرمرتبط را حذف کند».
 *
 * رفتار این‌جا درونِ همان پردازه سنجیده می‌شود (محیطِ `preview` و پایگاه‌داده‌ی برچسب‌دار)؛ اجرای پردازه‌ی واقعی با
 * `APP_ENV=preview` در `PreviewResetTest::test_one_account_is_restored_explicitly_and_keeps_its_changed_password`.
 */
class PreviewAccountResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PreviewSeeder::class);
        Preview::markDatabase();
        $this->app['env'] = 'preview';
    }

    /**
     * حسابِ اولیه پس از آزمودنِ مسیرهای واقعی — پیوستن به یک مجتمع (با دوره‌ی واحد)، درخواستِ در انتظار، خریدِ مجتمع،
     * تغییرِ رمز — به «بی عضویت» برمی‌گردد. سابقه می‌ماند، مجتمعِ خریده حذف نمی‌شود، رمز دست نمی‌خورد، دیگران دست‌نخورده.
     */
    public function test_the_initial_account_returns_to_no_membership_keeping_history_and_password(): void
    {
        $initial = $this->account(PreviewSeeder::INITIAL);
        $initial->forceFill(['password' => Hash::make('Changed123')])->save();
        $joined = Complex::where('slug', 'demo-com-basic')->firstOrFail();
        $requested = Complex::where('slug', 'demo-res-pro')->firstOrFail();

        app(MembershipLedger::class)->start($initial, $joined, UserRole::Tenant, ComplexMembership::SOURCE_JOIN_REQUEST);
        $unit = $joined->units()->withoutGlobalScopes()->where('unit_number', '2')->firstOrFail();
        $unit->residents()->attach($initial->id, ['complex_id' => $joined->id, 'relation' => 'tenant', 'share_percent' => 100, 'is_current' => true, 'start_date' => now()]);
        $request = (new ComplexInvitation)->forceFill([
            'complex_id' => $requested->id, 'user_id' => $initial->id, 'direction' => ComplexInvitation::REQUEST,
            'status' => ComplexInvitation::PENDING, 'role' => UserRole::Owner, 'invited_by' => $initial->id,
            'live_key' => 'request:'.$requested->id.':'.$initial->id,
        ]);
        $request->save();
        $bought = app(ComplexUpgrader::class)->upgrade($this->account(PreviewSeeder::INITIAL), 'مجتمعِ خریدِ بازبین', null, ComplexType::Residential);
        $others = $this->snapshotOthers(PreviewSeeder::INITIAL);

        $this->assertSame(0, Artisan::call('preview:reset-account', ['username' => PreviewSeeder::INITIAL]), Artisan::output());

        $fresh = $this->account(PreviewSeeder::INITIAL);
        $this->assertNull($fresh->complex_id);
        $this->assertSame(UserRole::Owner, $fresh->role);
        $this->assertSame(0, ComplexMembership::where('user_id', $fresh->id)->whereIn('status', ComplexMembership::LIVE)->count());
        // سابقه: دو عضویتِ پایان‌یافته، دوره‌ی واحدِ بسته، درخواستِ پس‌گرفته — هیچ ردیفی حذف نشد
        $this->assertSame(2, ComplexMembership::where('user_id', $fresh->id)->where('status', ComplexMembership::ENDED)->count());
        $this->assertSame(0, UnitTenure::where('user_id', $fresh->id)->where('is_current', true)->count());
        $this->assertSame(1, UnitTenure::where('user_id', $fresh->id)->count());
        $this->assertSame(ComplexInvitation::REVOKED, $request->fresh()->status);
        $this->assertTrue(Complex::whereKey($bought->id)->exists(), 'مجتمعِ خریده‌ی آزمایشی حذف شد.');
        $this->assertTrue(Hash::check('Changed123', $fresh->password), 'رمزِ تغییرکرده بی‌اجازه برگشت.');
        $this->assertSame($others, $this->snapshotOthers(PreviewSeeder::INITIAL), 'بازگرداندن به حساب‌های دیگر دست زد.');
        $this->assertTrue(AuditLog::where('action', 'preview.account_restored')->exists());

        // دوباره: چیزی برای بازگرداندن نیست
        Artisan::call('preview:reset-account', ['username' => PreviewSeeder::INITIAL]);
        $this->assertStringContainsString('چیزی عوض نشد', Artisan::output());

        // و ورودِ بعدی دوباره داشبوردِ اولیه است (درخواستِ HTTP در محیطِ آزمون: نگهبانِ CSRF و کلیدِ دسترسیِ Preview جدا آزموده‌اند)
        $this->app['env'] = 'testing';
        $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => PreviewSeeder::INITIAL, 'password' => 'Changed123'])->assertOk();
        $this->assertSame('initial', $this->getJson('/api/v1/me')->json('user.dashboard'));
    }

    /**
     * حسابِ چندمجتمعی پس از دستکاریِ بازبین — یک عضویتِ جدول پایان یافته، یکی معلق، نقشِ یکی عوض‌شده، عضویتِ اضافه
     * بیرون از جدول، زمینه‌ی پیش‌فرضِ دیگر — دقیقاً به پنج زمینه‌ی جدول با همان نقش‌ها برمی‌گردد.
     */
    public function test_the_multi_account_returns_to_exactly_the_five_contexts_of_the_table(): void
    {
        $multi = $this->account(PreviewSeeder::MULTI);
        $slug = fn (string $s): int => (int) Complex::where('slug', $s)->value('id');

        app(Membership::class)->end($multi, null, $slug('demo-com-basic'));
        app(MembershipLedger::class)->suspend($this->account(PreviewSeeder::MULTI), $slug('demo-com-pro'));
        app(MembershipLedger::class)->changeRole($this->account(PreviewSeeder::MULTI), UserRole::Tenant, null, $slug('demo-res-pro'));
        app(MembershipLedger::class)->start($this->account(PreviewSeeder::MULTI), Complex::where('slug', PreviewSeeder::SINGLE_CONTEXT)->firstOrFail(), UserRole::Tenant, ComplexMembership::SOURCE_DIRECT, activate: false);
        app(MembershipLedger::class)->switchTo($this->account(PreviewSeeder::MULTI), $slug('demo-com-advanced'));
        $single = $this->snapshotOthers(PreviewSeeder::MULTI);

        $this->assertSame(0, Artisan::call('preview:reset-account', ['username' => PreviewSeeder::MULTI]), Artisan::output());

        $fresh = $this->account(PreviewSeeder::MULTI);
        $live = ComplexMembership::where('user_id', $fresh->id)->whereIn('status', ComplexMembership::LIVE)->get()
            ->mapWithKeys(fn (ComplexMembership $m) => [Complex::findOrFail($m->complex_id)->slug => [$m->role, $m->status]])->all();
        $expected = [];
        foreach (array_diff_key(PreviewSeeder::CONTEXTS, [PreviewSeeder::SINGLE_CONTEXT => true]) as $s => $spec) {
            $expected[$s] = [$spec['role'], ComplexMembership::ACTIVE];
        }
        $this->assertEquals($expected, $live);
        $this->assertSame($slug('demo-res-basic'), $fresh->complex_id, 'زمینه‌ی پیش‌فرض اولین سطرِ جدول نشد.');
        $this->assertSame(UserRole::ComplexAdmin, $fresh->role);
        // زمینه‌ی واحدِ پایان‌یافته دوباره دوره‌ی جاریِ واحدِ ۱ را دارد
        $this->assertSame(1, UnitTenure::where('user_id', $fresh->id)->where('complex_id', $slug('demo-com-basic'))->where('is_current', true)->count());
        $this->assertSame($single, $this->snapshotOthers(PreviewSeeder::MULTI));

        // نقشِ زمینه‌ی واحد از عضویتِ برگشته، از سرور
        $this->app['env'] = 'testing';
        $this->actingAs($fresh)->postJson('/api/v1/account/context', ['complex_id' => $slug('demo-com-basic')])->assertOk();
        $this->assertSame('unit', $this->getJson('/api/v1/me')->json('user.dashboard'));
    }

    /** `--password` رمزِ تصادفیِ تازه را یک بار در خروجی می‌دهد، رمزِ قبلی و همه‌ی نشست‌ها را باطل می‌کند */
    public function test_the_password_option_issues_a_new_password_once_and_ends_every_session(): void
    {
        config(['session.driver' => 'database']);
        $single = $this->account(PreviewSeeder::SINGLE);
        $single->forceFill(['password' => Hash::make('Old12345')])->save();
        DB::table('sessions')->insert([
            ['id' => 'one', 'user_id' => $single->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'two', 'user_id' => $single->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'other', 'user_id' => $this->account(PreviewSeeder::MULTI)->id, 'payload' => '', 'last_activity' => time()],
        ]);

        $result = app(PreviewSeeder::class)->restore(PreviewSeeder::SINGLE, newPassword: true);

        $this->assertNotNull($result['password']);
        $fresh = $this->account(PreviewSeeder::SINGLE);
        $this->assertTrue(Hash::check($result['password'], $fresh->password));
        $this->assertFalse(Hash::check('Old12345', $fresh->password));
        $this->assertStringNotContainsString($result['password'], (string) $fresh->getRawOriginal('password'));
        $this->assertSame(['other'], DB::table('sessions')->pluck('id')->all(), 'نشستِ حسابِ دیگری بسته شد یا نشستی از همین حساب ماند.');
        $this->assertContains('2 نشست پایان یافت', $result['actions']);
        // رمز در audit log نمی‌رود
        $this->assertStringNotContainsString($result['password'], (string) json_encode(AuditLog::where('action', 'preview.account_restored')->value('properties')));

        // از فرمان: رمز در خروجی، فقط همین یک بار
        $this->assertSame(0, Artisan::call('preview:reset-account', ['username' => PreviewSeeder::SINGLE, '--password' => true]));
        $this->assertStringContainsString('فقط همین یک بار', Artisan::output());
        $this->assertFalse(Hash::check($result['password'], $this->account(PreviewSeeder::SINGLE)->password));
    }

    /** سوپر ادمینِ آزمایشیِ غیرفعال‌شده دوباره فعال می‌شود؛ عضوِ مجتمعی نمی‌شود */
    public function test_the_demo_admin_is_reactivated_and_stays_out_of_communities(): void
    {
        $this->account(PreviewSeeder::ADMIN)->forceFill(['is_active' => false])->save();

        $this->assertSame(0, Artisan::call('preview:reset-account', ['username' => PreviewSeeder::ADMIN]));

        $admin = $this->account(PreviewSeeder::ADMIN);
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->isSuperAdmin());
        $this->assertSame(0, ComplexMembership::where('user_id', $admin->id)->count());
    }

    /**
     * دشمنانه: حسابِ واقعی با نام کاربری (سوپر ادمینِ اصلی)، حسابِ بی‌نشانگرِ هم‌نامِ حسابِ آزمایشی، نامِ ناشناخته، محیطِ
     * دیگر و پایگاه‌داده‌ی بی‌برچسب — همه رد، بی هیچ تغییری.
     */
    public function test_real_accounts_unknown_names_and_other_environments_are_refused(): void
    {
        $real = (new User)->forceFill(['name' => 'سوپر ادمینِ اصلی', 'username' => 'sakena.admin', 'phone' => '09120000009',
            'role' => UserRole::SuperAdmin, 'password' => Hash::make('Real12345'), 'is_active' => false]);
        $real->save();
        $this->assertSame(1, Artisan::call('preview:reset-account', ['username' => 'sakena.admin', '--password' => true]));
        $this->assertFalse($real->fresh()->is_active);
        $this->assertTrue(Hash::check('Real12345', $real->fresh()->password));

        // نشانگرِ آزمایشی برداشته شود: دیگر حسابِ آزمایشی نیست
        $single = $this->account(PreviewSeeder::SINGLE);
        $single->forceFill(['is_demo' => false, 'is_active' => false])->save();
        $this->assertSame(1, Artisan::call('preview:reset-account', ['username' => PreviewSeeder::SINGLE]));
        $this->assertFalse($single->fresh()->is_active);

        $this->assertSame(1, Artisan::call('preview:reset-account', ['username' => 'demo.manager']));

        $this->app['env'] = 'production';
        $this->assertSame(1, Artisan::call('preview:reset-account', ['username' => PreviewSeeder::INITIAL]));
        $this->expectException(RuntimeException::class);
        app(PreviewSeeder::class)->restore(PreviewSeeder::INITIAL);
    }

    public function test_an_unmarked_database_is_refused(): void
    {
        DB::table('settings')->whereNull('complex_id')->where('key', 'database_role')->delete();

        $this->assertSame(1, Artisan::call('preview:reset-account', ['username' => PreviewSeeder::INITIAL]));
    }

    private function account(string $username): User
    {
        return User::withoutGlobalScopes()->where('username', $username)->firstOrFail();
    }

    /**
     * رمز، وضعیت و عضویت‌های حساب‌های آزمایشیِ دیگر — باید پس از بازگرداندنِ یک حساب همان بماند.
     *
     * @return array<string, mixed>
     */
    private function snapshotOthers(string $except): array
    {
        return User::withoutGlobalScopes()->whereIn('username', array_diff(PreviewSeeder::ACCOUNTS, [$except]))->orderBy('username')->get()
            ->mapWithKeys(fn (User $u) => [$u->username => [
                $u->password, $u->is_active, $u->complex_id,
                ComplexMembership::where('user_id', $u->id)->orderBy('id')->get(['complex_id', 'role', 'status'])->toArray(),
            ]])->all();
    }
}
