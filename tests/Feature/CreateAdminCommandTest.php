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
     * «حفره ها۲» بندِ ۵.۱: ورودِ سوپر ادمینِ اصلی «نام کاربری + رمز + Authenticator» است، ولی تا این‌جا راهی برای دادنِ
     * نام کاربری به او نبود (`username` نه `fillable` است نه در هیچ API). حالا فقط از فرمانِ سرور — و با همان نام کاربری
     * چالشِ TOTP می‌آید، نه ورودِ مستقیم.
     */
    public function test_the_main_admin_gets_a_username_only_from_the_server_and_then_signs_in_with_it(): void
    {
        $this->artisan('admin:create', [
            '--phone' => '09121234567', '--first-name' => 'مریم', '--last-name' => 'کاظمی', '--password' => 'strong1234',
            '--username' => 'Sakena.Admin',
        ])->assertSuccessful();
        $admin = User::where('phone', '09121234567')->sole();
        $this->assertSame('sakena.admin', $admin->username);

        $this->artisan('admin:username', ['username' => 'sakena.ops', '--phone' => '09121234567'])->assertSuccessful();
        $this->assertSame('sakena.ops', $admin->fresh()->username);
        $this->assertTrue(AuditLog::where('action', 'admin.username_set')->exists());

        $secret = app(AdminTwoFactor::class)->begin($admin->fresh())['secret'];
        app(AdminTwoFactor::class)->confirm($admin->fresh(), Totp::at($secret, Totp::step() - 1));
        $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => 'sakena.ops', 'password' => 'strong1234'])
            ->assertOk()->assertJson(['twoFactorRequired' => true]);
        $this->assertGuest();
    }

    /** دشمنانه: نامِ نامعتبر، گرفته‌شده، با پیشوندِ حساب‌های آزمایشی، برای کاربرِ عادی یا حسابِ آزمایشی — همه رد */
    public function test_a_username_is_refused_when_invalid_taken_demo_or_not_a_real_admin(): void
    {
        $admin = (new User)->forceFill(['name' => 'سوپر ادمین', 'phone' => '09121234567', 'role' => UserRole::SuperAdmin,
            'password' => Hash::make('strong1234'), 'is_active' => true]);
        $admin->save();
        $owner = User::factory()->create(['phone' => '09121234568', 'role' => UserRole::Owner]);
        $demo = (new User)->forceFill(['name' => 'آزمایشی', 'phone' => '09121234569', 'role' => UserRole::SuperAdmin,
            'username' => 'taken.name', 'password' => Hash::make('x'), 'is_active' => true, 'is_demo' => true]);
        $demo->save();

        foreach (['9admin', 'ab', 'سوپر', 'taken.name', 'demo.admin'] as $bad) {
            $this->artisan('admin:username', ['username' => $bad, '--phone' => '09121234567'])->assertFailed();
        }
        $this->artisan('admin:username', ['username' => 'owner.name', '--phone' => '09121234568'])->assertFailed();
        $this->artisan('admin:username', ['username' => 'demo.name2', '--phone' => '09121234569'])->assertFailed();
        $this->artisan('admin:username', ['username' => 'fresh.name', '--phone' => '09121234569'])->assertFailed();
        $this->artisan('admin:create', [
            '--phone' => '09121234570', '--first-name' => 'مریم', '--last-name' => 'کاظمی', '--password' => 'strong1234', '--username' => 'taken.name',
        ])->assertFailed();

        $this->assertNull($admin->fresh()->username);
        $this->assertNull($owner->fresh()->username);
        $this->assertSame('taken.name', $demo->fresh()->username);
        $this->assertDatabaseMissing('users', ['phone' => '09121234570']);
    }
}
