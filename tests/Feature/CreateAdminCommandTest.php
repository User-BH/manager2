<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\AdminTwoFactor;
use App\Services\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `admin:create` — نام و نام خانوادگیِ جدا (مرحلهٔ ۹ · فاز ۶).
 *
 * آخرین مسیرِ ساختِ حساب که فقط `name` می‌گرفت (با پیش‌فرضِ «مدیر سیستم»).
 * حالا همان قاعده‌ی ثبت‌نام (`PersianName`) را دارد.
 */
class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_gets_a_first_and_last_name(): void
    {
        $this->artisan('admin:create', [
            '--phone' => '09121234567', '--first-name' => 'مریم', '--last-name' => 'کاظمی', '--password' => 'strong1234',
        ])->assertSuccessful();

        $admin = User::where('phone', '09121234567')->sole();
        $this->assertSame(UserRole::SuperAdmin, $admin->role);
        $this->assertSame('مریم', $admin->first_name);
        $this->assertSame('کاظمی', $admin->last_name);
        $this->assertSame('مریم کاظمی', $admin->name);
        $this->assertFalse($admin->needsNameCompletion());
    }

    public function test_an_invalid_name_creates_nothing(): void
    {
        foreach ([['Maryam', 'کاظمی'], ['مریم', 'ک'], ['مریم', 'کاظمی۲']] as [$first, $last]) {
            $this->artisan('admin:create', [
                '--phone' => '09121234567', '--first-name' => $first, '--last-name' => $last, '--password' => 'strong1234',
            ])->assertFailed();
        }

        $this->assertDatabaseMissing('users', ['phone' => '09121234567']);
    }

    /**
     * ۲۰۲۶-۱۰-۲۹، بندِ ۲: دو مدیر، هر کدام با شماره و رمزِ شخصیِ خودش؛ ورود با شماره + رمز + Authenticator. فرمان
     * نام کاربری نمی‌گیرد و گامِ بعد (`admin:two-factor <شماره>`) را خودش می‌گوید؛ رمز از ورودیِ پنهان.
     */
    public function test_two_admins_each_with_their_own_phone_and_password_sign_in_with_the_authenticator(): void
    {
        $this->artisan('admin:create', ['--phone' => '09121234567', '--first-name' => 'مریم', '--last-name' => 'کاظمی'])
            ->expectsQuestion('رمز عبور', 'first-pass-1')
            ->expectsOutputToContain('php artisan admin:two-factor 09121234567')
            ->assertSuccessful();
        $this->artisan('admin:create', ['--phone' => '09121234568', '--first-name' => 'علی', '--last-name' => 'رضایی'])
            ->expectsQuestion('رمز عبور', 'second-pass-2')
            ->assertSuccessful();

        // شماره‌ی تکراری حسابِ دوم نمی‌سازد
        $this->artisan('admin:create', ['--phone' => '09121234567', '--first-name' => 'سارا', '--last-name' => 'نوری', '--password' => 'third-pass-3'])
            ->assertFailed();

        $admins = User::where('role', UserRole::SuperAdmin)->orderBy('phone')->get();
        $this->assertSame(['09121234567', '09121234568'], $admins->pluck('phone')->all());
        $this->assertSame([null, null], $admins->pluck('username')->all());

        foreach ([[$admins[0], 'first-pass-1', 'second-pass-2'], [$admins[1], 'second-pass-2', 'first-pass-1']] as [$admin, $password, $other]) {
            $this->assertTrue(Hash::check($password, $admin->password));
            $secret = app(AdminTwoFactor::class)->begin($admin)['secret'];
            app(AdminTwoFactor::class)->confirm($admin->fresh(), Totp::at($secret, Totp::step() - 1));

            $this->flushSession();
            $this->app['auth']->forgetGuards();
            // رمزِ مدیرِ دیگر با شماره‌ی این مدیر وارد نمی‌کند
            $this->postJson('/api/v1/login', ['phone' => $admin->phone, 'password' => $other])->assertStatus(422);
            $this->postJson('/api/v1/login', ['phone' => $admin->phone, 'password' => $password])
                ->assertOk()->assertJson(['twoFactorRequired' => true])->assertJsonMissing(['otpRequired' => true]);
            $this->postJson('/api/v1/login/two-factor', ['code' => Totp::at($secret, Totp::step())])
                ->assertOk()->assertJsonPath('user.id', $admin->id);
            $this->postJson('/api/v1/logout')->assertOk();
        }

        $this->assertStringNotContainsString('pass-', (string) json_encode(AuditLog::all()->toArray()));
    }
}
