<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\User;
use App\Support\SystemSettings;
use Database\Seeders\PreviewSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * ورود با نام کاربری و جداییِ حسابِ آزمایشی از Production (مرحلهٔ ۱۳ · فاز ۱؛ پیامِ کارفرما ۲۰۲۶-۱۰-۱۹، بخش ۳).
 *
 * هشت آزمونِ اجباریِ همان بخش، به ترتیب:
 *   ۱ کاربرِ واقعی با شماره و کد · ۲ سوپر ادمین با نام کاربری در مسیرِ خودش · ۳ حسابِ آزمایشی فقط بیرون از Production ·
 *   ۴ ردِ ورودِ آزمایشی در Production · ۵ شماره‌ی حسابِ آزمایشی مانعِ ثبت‌نامِ واقعی نیست · ۶ نام کاربریِ نامعتبر، رمزِ
 *   غلط و تلاشِ پرتعداد · ۷ دستکاریِ شناسه، نوع یا نشست · ۸ حسابِ آزمایشی به دادهٔ واقعی نمی‌رسد.
 */
class UsernameLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret123';

    protected function setUp(): void
    {
        parent::setUp();

        // شمارنده‌ها بین آزمون‌ها نشت نکنند
        RateLimiter::clear('login');
        $this->app['cache']->flush();
    }

    /** ⚠️ درخواستِ `env = production` میزبان‌های مجاز را ایستا روی `Request` می‌نشاند (همان پاک‌سازیِ `PreviewEnvironmentTest`) */
    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    /* ── ۱ ─────────────────────────────────────────────────────────────── */

    public function test_a_real_user_still_signs_in_with_the_phone_and_a_code(): void
    {
        $user = $this->account(['phone' => '09121234567', 'role' => UserRole::Owner]);

        $this->postJson('/api/v1/login', ['phone' => '09121234567', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJson(['otpRequired' => true, 'phone' => '09121234567']);

        // نوعِ صریحِ «phone» همان رفتار را دارد (حسابِ دوم، تا فاصله‌ی ارسالِ دوباره‌ی کد مانع نشود)
        $this->account(['phone' => '09121234568', 'role' => UserRole::Tenant]);
        $this->postJson('/api/v1/login', ['identifier_type' => 'phone', 'phone' => '09121234568', 'password' => self::PASSWORD])
            ->assertOk()->assertJson(['otpRequired' => true]);

        $this->assertGuest();
        $this->assertNull($user->fresh()->username);
    }

    public function test_a_real_user_cannot_sign_in_with_a_username_even_if_one_is_set(): void
    {
        $this->account(['phone' => '09121234567', 'role' => UserRole::Owner, 'username' => 'ali.real']);

        $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => 'ali.real', 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('errors.username.0', 'نام کاربری یا رمز عبور نادرست است.');

        $this->assertGuest();
    }

    /* ── ۲ ─────────────────────────────────────────────────────────────── */

    public function test_the_super_admin_signs_in_by_username_and_still_needs_the_code(): void
    {
        $this->account(['phone' => '09120000001', 'role' => UserRole::SuperAdmin, 'username' => 'sakena.admin']);

        $response = $this->postJson('/api/v1/login', [
            'identifier_type' => 'username', 'username' => 'Sakena.Admin ', 'password' => self::PASSWORD,
        ])->assertOk()->assertJson(['otpRequired' => true]);

        // شماره را کسی نداده؛ فقط نسخه‌ی پوشیده برمی‌گردد
        $this->assertSame('0912•••0001', $response->json('phone'));
        $this->assertStringNotContainsString('09120000001', $response->getContent());
        $this->assertGuest();

        $this->postJson('/api/v1/login/verify', ['code' => $response->json('dev_code')])->assertOk();
        $this->assertAuthenticated();
    }

    public function test_a_super_admin_without_a_phone_has_no_second_factor_and_is_refused(): void
    {
        $this->account(['phone' => null, 'role' => UserRole::SuperAdmin, 'username' => 'sakena.admin']);

        $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => 'sakena.admin', 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('errors.username.0', 'برای این حساب عاملِ دومِ ورود تعریف نشده است.');

        $this->assertGuest();
    }

    public function test_the_username_route_never_reads_a_phone_or_guesses_the_type(): void
    {
        $this->account(['phone' => '09120000001', 'role' => UserRole::SuperAdmin, 'username' => 'sakena.admin']);

        // نام کاربری در فیلدِ شماره: به‌عنوانِ شماره خوانده و ناکام می‌شود، نه حدسِ نام کاربری
        $this->postJson('/api/v1/login', ['phone' => 'sakena.admin', 'password' => self::PASSWORD])
            ->assertStatus(422)->assertJsonPath('errors.phone.0', 'شماره تلفن یا رمز عبور نادرست است.');

        // شماره در فیلدِ نام کاربری: هیچ حسابی با آن «نام کاربری» نیست
        $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => '09120000001', 'password' => self::PASSWORD])
            ->assertStatus(422)->assertJsonPath('errors.username.0', 'نام کاربری یا رمز عبور نادرست است.');

        // نوعِ ناشناخته رد می‌شود
        $this->postJson('/api/v1/login', ['identifier_type' => 'email', 'username' => 'sakena.admin', 'password' => self::PASSWORD])
            ->assertStatus(422);

        $this->assertGuest();
    }

    public function test_the_username_page_is_served_to_guests_only(): void
    {
        $this->get('/auth/username')->assertOk();

        $admin = $this->account(['phone' => '09120000001', 'role' => UserRole::SuperAdmin, 'username' => 'sakena.admin']);
        $this->actingAs($admin)->get('/auth/username')->assertRedirect();
    }

    /* ── ۳ ─────────────────────────────────────────────────────────────── */

    public function test_a_demo_account_signs_in_by_username_outside_production(): void
    {
        $demo = $this->account(['phone' => null, 'role' => UserRole::Owner, 'username' => 'demo.single', 'is_demo' => true]);

        $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => 'demo.single', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.id', $demo->id);

        $this->assertAuthenticatedAs($demo);
    }

    /* ── ۴ ─────────────────────────────────────────────────────────────── */

    public function test_a_demo_account_is_refused_in_production_with_the_generic_answer(): void
    {
        $this->account(['phone' => null, 'role' => UserRole::Owner, 'username' => 'demo.single', 'is_demo' => true]);
        $this->account(['phone' => '09127777777', 'role' => UserRole::Owner, 'username' => 'demo.phone', 'is_demo' => true]);
        $this->app['env'] = 'production';
        // در production نگهبانِ CSRF فعال است؛ این آزمون فقط مرزِ دادهٔ آزمایشی را می‌سنجد
        $this->withoutMiddleware(PreventRequestForgery::class);

        $missing = $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => 'nobody.here', 'password' => self::PASSWORD])
            ->assertStatus(422)->json('errors');
        $refused = $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => 'demo.single', 'password' => self::PASSWORD])
            ->assertStatus(422)->json('errors');

        // همان پاسخِ «نیست»: وجودِ حسابِ آزمایشی لو نمی‌رود
        $this->assertSame($missing, $refused);

        // از مسیرِ شماره هم نه، حتی با کلیدِ «ورودِ بی کد»
        SystemSettings::set('otp_disabled', true);
        $this->postJson('/api/v1/login', ['phone' => '09127777777', 'password' => self::PASSWORD])
            ->assertStatus(422)->assertJsonPath('errors.phone.0', 'شماره تلفن یا رمز عبور نادرست است.');

        $this->assertGuest();
    }

    public function test_an_existing_demo_session_is_cut_off_in_production(): void
    {
        $demo = $this->account(['phone' => null, 'role' => UserRole::Owner, 'username' => 'demo.single', 'is_demo' => true]);

        $this->actingAs($demo)->getJson('/api/v1/me')->assertOk();

        $this->app['env'] = 'production';
        $this->actingAs($demo)->getJson('/api/v1/dashboard')
            ->assertStatus(403)
            ->assertJson(['accountDisabled' => true]);
    }

    /* ── ۵ ─────────────────────────────────────────────────────────────── */

    public function test_a_demo_account_reserves_no_phone_for_a_real_signup(): void
    {
        $this->seed(PreviewSeeder::class);

        $this->assertSame(0, User::withoutGlobalScopes()->where('is_demo', true)->whereNotNull('phone')->count(),
            'دادهٔ آزمایشی شماره‌ی موبایل نگه داشته است.');

        // یک شماره‌ی تازه بی‌مانع ثبت‌نام می‌شود
        $this->postJson('/api/v1/register', [
            'first_name' => 'علی', 'last_name' => 'محمدی', 'phone' => '09125555555',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'accept_terms' => true,
        ])->assertOk();
    }

    /* ── ۶ ─────────────────────────────────────────────────────────────── */

    public function test_wrong_passwords_unknown_usernames_and_bursts_are_handled(): void
    {
        $this->account(['phone' => '09120000001', 'role' => UserRole::SuperAdmin, 'username' => 'sakena.admin']);

        $wrongPassword = $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => 'sakena.admin', 'password' => 'wrong-pass'])
            ->assertStatus(422)->json('errors');
        $unknown = $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => 'nobody.here', 'password' => 'wrong-pass'])
            ->assertStatus(422)->json('errors');
        $this->assertSame($unknown, $wrongPassword, 'رمزِ غلط و نام کاربریِ ناموجود باید یک پاسخ بگیرند.');

        // پنج تلاش در دقیقه برای هر نام کاربری؛ یکی بالا رفت («nobody.here» کلیدِ خودش را دارد)
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => 'sakena.admin', 'password' => 'wrong-pass'])
                ->assertStatus(422);
        }
        $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => 'sakena.admin', 'password' => self::PASSWORD])
            ->assertStatus(429);

        $this->assertGuest();
    }

    /* ── ۷ ─────────────────────────────────────────────────────────────── */

    public function test_the_demo_marker_cannot_be_set_from_a_request(): void
    {
        $user = $this->account(['phone' => '09121234567', 'role' => UserRole::Owner]);

        $this->actingAs($user)->patchJson('/api/v1/profile', [
            'first_name' => 'علی', 'last_name' => 'محمدی', 'is_demo' => true, 'username' => 'hijack.me',
        ]);

        $fresh = $user->fresh();
        $this->assertFalse($fresh->is_demo);
        $this->assertNull($fresh->username);
    }

    /* ── ۸ ─────────────────────────────────────────────────────────────── */

    public function test_a_demo_manager_reaches_no_real_community(): void
    {
        $this->seed(PreviewSeeder::class);
        $real = Complex::factory()->create(['name' => 'مجتمعِ واقعی']);

        $manager = User::withoutGlobalScopes()->where('username', 'demo.multi')->firstOrFail();

        // جعلِ مجتمع با پارامتر هیچ دسترسی‌ای نمی‌دهد: سرور عضویت را می‌سنجد
        $this->actingAs($manager)->postJson('/api/v1/account/context', ['complex_id' => $real->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'عضویتِ فعالی در این مجتمع ندارید.');
        $this->actingAs($manager)->getJson('/api/v1/units?complex_id='.$real->id)->assertOk()
            ->assertJsonMissing(['complex_id' => $real->id]);

        $this->assertNotSame($real->id, $manager->fresh()->complex_id);
    }

    /** @param  array<string, mixed>  $attributes */
    private function account(array $attributes): User
    {
        $user = (new User)->forceFill($attributes + [
            'name' => 'کاربرِ آزمون',
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true,
        ]);
        $user->save();

        return $user;
    }
}
