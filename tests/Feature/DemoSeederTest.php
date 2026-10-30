<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * دادهٔ نمایشی (مرحلهٔ ۸ · فاز ۱).
 *
 * چهار حسابی که برای کار با داشبورد داده می‌شوند باید واقعاً با «password»
 * وارد شوند و همان نقشی را بگیرند که گفته شده؛ و چون رمزِ همه‌شان همین
 * است، seeder روی محصول نباید اجرا شود.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string, bool, bool}> شماره، نقش، سوپرادمین؟، مجتمع دارد؟ */
    public static function accounts(): array
    {
        return [
            'سوپرادمین' => ['09120000001', 'super_admin', true, false],
            'ثبت‌نامِ اولیه' => [DemoSeeder::FRESH_SIGNUP_PHONE, 'owner', false, false],
            'مدیرِ مجتمع' => ['09120000000', 'complex_admin', false, true],
            'واحد' => ['09121111101', 'owner', false, true],
        ];
    }

    #[DataProvider('accounts')]
    public function test_each_of_the_four_accounts_signs_in_with_its_role(string $phone, string $role, bool $super, bool $hasComplex): void
    {
        $this->seed(DemoSeeder::class);

        // کاربرانِ سایت با شماره و کدِ پیامکی؛ مدیرِ سامانه از درِ خودش با نام کاربری + رمز (۲۰۲۶-۱۰-۳۰)
        $super
            ? $this->postJson('/api/v1/admin/login', ['username' => 'sakena.admin', 'password' => 'password'])->assertOk()
            : $this->signInByPhone($phone)->assertOk();

        $user = $this->getJson('/api/me')->assertOk()->json('user');
        $this->assertSame($role, $user['role']);
        $this->assertSame($super, $user['isSuperAdmin']);
        $this->assertSame($hasComplex, $user['complex'] !== null);
    }

    public function test_the_fresh_signup_account_looks_like_a_real_self_registration(): void
    {
        $this->seed(DemoSeeder::class);

        $user = User::withoutGlobalScopes()->where('phone', DemoSeeder::FRESH_SIGNUP_PHONE)->firstOrFail();

        // همان چیزی که `AuthController::register` می‌سازد
        $this->assertNull($user->complex_id);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertSame(0, $user->units()->withoutGlobalScopes()->count());
    }

    /** ۱۳/۹-ج: `db:seed` ِ دوم روی همان پایگاه‌داده با UNIQUE ِ `users.phone` می‌شکست */
    public function test_seeding_twice_creates_nothing_and_does_not_fail(): void
    {
        $this->seed(DemoSeeder::class);
        $before = [User::withoutGlobalScopes()->count(), DB::table('complexes')->count(), DB::table('units')->count(), DB::table('bills')->count()];

        $this->seed(DemoSeeder::class);

        $this->assertSame($before, [User::withoutGlobalScopes()->count(), DB::table('complexes')->count(), DB::table('units')->count(), DB::table('bills')->count()]);
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        // ⚠️ مستقیم، نه `seed()`: فرمانِ db:seed روی محصول تأییدِ تعاملی می‌خواهد
        try {
            $this->app->call([new DemoSeeder, 'run']);
            $this->fail('DemoSeeder روی محصول اجرا شد.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('محصول', $e->getMessage());
        }

        $this->assertSame(0, User::withoutGlobalScopes()->count(), 'پیش از رد کردن چیزی ساخته شد.');
    }
}
