<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Complex;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `sample-data:retire` (۲۰۲۶-۱۰-۲۹، بندِ ۱): روی sakena.ir فقط سوپر ادمین‌های واقعی و کسانی که خودشان ثبت‌نام کرده‌اند
 * وارد می‌شوند؛ حساب‌های نمونه‌ی `DemoSeeder` (رمزِ «password») کنار می‌روند و کسِ واقعی دست نمی‌خورد.
 */
class RetireSampleDataTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    public function test_the_sample_accounts_lose_their_login_and_phones_and_real_people_are_untouched(): void
    {
        $this->seed(DemoSeeder::class);
        $sample = User::withoutGlobalScopes()->where('phone', '09121111101')->sole();
        $sampleComplex = Complex::withoutGlobalScopes()->where('name', 'مجتمع مسکونی آفتاب')->sole();
        // کسی که خودش ثبت‌نام کرده، و سوپر ادمینِ واقعی با رمزِ شخصی
        $real = User::factory()->create(['name' => 'ثبت‌نامِ واقعی', 'phone' => '09351234567', 'password' => Hash::make('real-pass-1'), 'is_active' => true]);
        $admin = User::factory()->create(['name' => 'مریم کاظمی', 'role' => UserRole::SuperAdmin, 'phone' => '09351234568', 'is_active' => true]);
        // همان شماره‌ی نمونه ولی کسِ دیگری (نام و ایمیلِ seeder را ندارد) — دست نمی‌خورد
        User::withoutGlobalScopes()->where('phone', '09120000002')->update(['name' => 'ثبت‌نامِ دیگر', 'email' => 'real@example.com']);

        // پیش‌فرض فقط گزارش است
        $this->artisan('sample-data:retire')->expectsOutputToContain('فقط گزارش')->assertSuccessful();
        $this->assertFalse($sample->fresh()->is_demo);

        $this->artisan('sample-data:retire', ['--force' => true])->assertSuccessful();

        $retired = User::withoutGlobalScopes()->where('is_demo', true)->get();
        $this->assertSame(0, $retired->whereNotNull('phone')->count(), 'شماره‌ی نمونه آزاد نشد.');
        $this->assertTrue($retired->every(fn (User $u) => ! $u->is_active && ! Hash::check('password', $u->password)));
        $this->assertTrue($sample->fresh()->is_demo);
        $this->assertTrue($sampleComplex->fresh()->is_demo, 'مجتمعِ نمونه کنار نرفت.');
        // ۱۲ حسابِ seeder (سوپر ادمین، ثبت‌نامِ اولیه، مدیر، ۶ مالک، ۳ مستأجر)، جز همان یکی که حالا کسِ دیگری است
        $this->assertCount(11, $retired);
        $this->assertFalse(User::withoutGlobalScopes()->where('name', 'ثبت‌نامِ دیگر')->sole()->is_demo);
        $this->assertFalse($real->fresh()->is_demo);
        $this->assertFalse($admin->fresh()->is_demo);
        $this->assertTrue(AuditLog::where('action', 'sample_data.retired')->exists());

        // روی Production: «password» و شماره‌های نمونه دیگر وارد نمی‌کنند؛ آن شماره‌ها برای ثبت‌نامِ واقعی آزادند
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class);
        foreach (['09120000000', '09121111101', '09122222202', '09120000001'] as $phone) {
            $this->flushSession();
            $this->postJson('/api/v1/login', ['phone' => $phone])->assertOk();
            $this->postJson('/api/v1/login/verify', ['code' => '000000'])->assertStatus(422);
        }
        $this->assertGuest();
        $this->assertNull(User::withoutGlobalScopes()->where('phone', '09121111101')->first());

        // دوباره اجرا: چیزی نمانده
        $this->artisan('sample-data:retire', ['--force' => true])->expectsOutputToContain('پیدا نشد')->assertSuccessful();
    }

    public function test_a_sample_complex_with_a_real_member_is_left_alone(): void
    {
        $this->seed(DemoSeeder::class);
        $complex = Complex::withoutGlobalScopes()->where('name', 'مجتمع مسکونی آفتاب')->sole();
        User::factory()->create(['name' => 'ساکنِ واقعی', 'phone' => '09351112233', 'complex_id' => $complex->id]);

        $this->artisan('sample-data:retire', ['--force' => true])->expectsOutputToContain('عضوِ واقعی دارد')->assertSuccessful();

        $this->assertFalse($complex->fresh()->is_demo);
    }

    public function test_preview_and_kept_phones_are_refused_or_skipped(): void
    {
        $this->seed(DemoSeeder::class);

        $this->artisan('sample-data:retire', ['--force' => true, '--keep' => ['09120000001']])->assertSuccessful();
        $this->assertFalse(User::withoutGlobalScopes()->where('phone', '09120000001')->sole()->is_demo);

        $this->app['env'] = 'preview';
        $this->artisan('sample-data:retire', ['--force' => true])->assertFailed();
    }
}
