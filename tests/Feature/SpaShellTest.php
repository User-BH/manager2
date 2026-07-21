<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * فرانت‌اند حالا یک SPA است: سرور برای همه‌ی مسیرهای غیر api/legacy یک قالب
 * واحد برمی‌گرداند و react-router تصمیم می‌گیرد چه چیزی رندر شود. بنابراین
 * ریدایرکت‌های سمت سرور جای خود را به ۴۰۱ روی API داده‌اند.
 */
class SpaShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_spa_shell_is_served_for_every_client_route(): void
    {
        foreach (['/', '/auth', '/dashboard', '/units', '/some/unknown/path'] as $path) {
            $this->get($path)
                ->assertStatus(200)
                ->assertSee('id="root"', false)
                ->assertSee('csrf-token', false);
        }
    }

    public function test_guest_api_returns_null_user_and_protected_endpoints_are_rejected(): void
    {
        $this->getJson('/api/me')->assertOk()->assertJson(['user' => null]);

        // برای درخواست JSON، لاراول به‌جای ریدایرکت ۴۰۱ می‌دهد و کلاینت خودش
        // کاربر را به /auth می‌فرستد.
        $this->getJson('/api/dashboard')->assertStatus(401);
        $this->getJson('/api/units')->assertStatus(401);
    }

    public function test_signed_in_user_is_reported_by_the_me_endpoint(): void
    {
        $user = User::create([
            'name' => 'تست', 'phone' => '09121234567', 'role' => UserRole::Tenant,
            'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->actingAs($user)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.phone', '09121234567')
            ->assertJsonPath('user.role', 'tenant')
            ->assertJsonPath('user.isAdmin', false);
    }

    public function test_complex_admins_cannot_reach_system_endpoints(): void
    {
        $complex = Complex::create(['name' => 'مجتمع تست', 'slug' => 'test-'.uniqid()]);

        $admin = User::create([
            'complex_id' => $complex->id,
            'name' => 'مدیر', 'phone' => '09121230000', 'role' => UserRole::ComplexAdmin,
            'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        // بخش سیستم فقط برای ادمین کل است؛ مدیر مجتمع نباید مجتمع بسازد یا
        // تنظیمات پیامک و بکاپ کل سیستم را ببیند.
        $this->actingAs($admin)->getJson('/api/system/complexes')->assertStatus(403);
        $this->actingAs($admin)->getJson('/api/system/sms')->assertStatus(403);
        $this->actingAs($admin)->getJson('/api/system/backups')->assertStatus(403);

        // ولی تنظیمات و بکاپ مجتمع خودش در دسترس است
        $this->actingAs($admin)->getJson('/api/settings')->assertOk();
        $this->actingAs($admin)->getJson('/api/backups')->assertOk();
    }

    /** اعتبارنامه‌ی درگاه بانکی هرگز نباید در پاسخ API دیده شود. */
    public function test_settings_never_expose_the_gateway_password(): void
    {
        $complex = Complex::create([
            'name' => 'مجتمع رمزدار', 'slug' => 'secret-'.uniqid(),
            'gateway_config' => ['terminal_id' => '123', 'username' => 'u', 'password' => 'SUPERSECRET'],
        ]);

        $admin = User::create([
            'complex_id' => $complex->id,
            'name' => 'مدیر', 'phone' => '09121230001', 'role' => UserRole::ComplexAdmin,
            'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/settings')->assertOk();

        $response->assertJsonPath('settings.gw_password_set', true);
        $this->assertStringNotContainsString('SUPERSECRET', $response->getContent());
    }

    public function test_residents_cannot_reach_admin_endpoints(): void
    {
        $resident = User::create([
            'name' => 'ساکن', 'phone' => '09121234500', 'role' => UserRole::Owner,
            'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->actingAs($resident)->getJson('/api/units')->assertStatus(403);
        $this->actingAs($resident)->getJson('/api/residents')->assertStatus(403);
    }
}
