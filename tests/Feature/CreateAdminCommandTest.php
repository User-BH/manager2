<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
