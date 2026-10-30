<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Backup;
use App\Models\Complex;
use App\Models\LoginEvent;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Auth\TrustedDeviceService;
use App\Services\Features\FeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * تأییدِ دوباره‌ی رمز پیش از عملیاتِ حساس (مرحلهٔ ۵ · فاز ۱۰).
 *
 * ⚠️ این کلاس عمداً `withConfirmedPassword()` را صدا **نمی‌زند** — موضوعش
 * خودِ نگهبان است.
 */
class ReauthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * مسیرهایی که بدونِ تأییدِ تازه باز نمی‌شوند، و چرا.
     *
     * 🔴 فهرست فقط بزرگ می‌شود: حذفِ `reauth` از هرکدام این تست را قرمز می‌کند.
     */
    private const GUARDED = [
        'api.managers.store' => 'دادنِ نقشِ مدیرِ مجتمع',
        'api.managers.destroy' => 'گرفتنِ نقشِ مدیرِ مجتمع',
        'api.backups.download' => 'کلِ داده‌ی مجتمع در یک فایل',
        'api.system.backups.download' => 'کلِ داده‌ی سامانه در یک فایل',
        'api.system.backups.restore' => 'بازنویسیِ کلِ دیتابیس',
        'api.system.features.update' => 'حالتِ تعمیر، ثبت‌نامِ عمومی',
        'api.system.members.update' => 'نقشِ هر کاربر، از جمله سوپرادمین',
        'api.system.members.destroy' => 'حذفِ هر کاربر',
        'api.system.observability.update' => 'DSNِ Sentry و GTM: مقصدِ داده و اسکریپت',
        'api.system.sms.update' => 'اعتبارنامه‌ی پیامک و خاموش‌کردنِ کدِ ورود',
        'api.profile.sessions.destroy' => 'بستنِ یک نشست',
        'api.profile.sessions.end-others' => 'بستنِ همه‌ی نشست‌های دیگر',
        'api.profile.devices.destroy' => 'لغوِ اعتمادِ یک دستگاه',
    ];

    private User $superAdmin;

    private User $manager;

    private Complex $complex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::create(['type' => 'residential',
            'name' => 'مجتمع تأیید', 'slug' => 'reauth-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'none',
            'settings' => ['card_number' => '6037-9911-1111-1111', 'card_holder' => 'صندوق مجتمع'],
        ]);

        $this->superAdmin = User::create([
            'name' => 'سوپرادمین', 'phone' => '09120000041',
            'role' => UserRole::SuperAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->manager = User::create([
            'complex_id' => $this->complex->id, 'name' => 'مدیر', 'phone' => '09120000042',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
    }

    private function flagState(): bool
    {
        return app(FeatureFlags::class)->enabled('polls');
    }

    /** 🔴 نشستِ واردشده به‌تنهایی کافی نیست. */
    public function test_a_sensitive_action_is_refused_without_a_fresh_password(): void
    {
        $before = $this->flagState();

        $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/system/features/polls', ['enabled' => ! $before])
            ->assertForbidden()
            ->assertJsonPath('code', 'auth.reauth_required');

        $this->assertSame($before, $this->flagState(), 'درخواستِ ردشده اثری گذاشت.');
    }

    public function test_confirming_the_password_opens_a_short_window(): void
    {
        $this->actingAs($this->superAdmin);

        $this->getJson('/api/v1/profile/confirm-password')->assertJsonPath('confirmed', false);
        $this->postJson('/api/v1/profile/confirm-password', ['password' => 'secret123'])->assertOk();
        $this->getJson('/api/v1/profile/confirm-password')->assertJsonPath('confirmed', true);

        $this->putJson('/api/v1/system/features/polls', ['enabled' => false])->assertOk();

        // پس از پنجره، دوباره بسته است
        $this->travel((int) config('auth.password_timeout') + 60)->seconds();

        $this->putJson('/api/v1/system/features/polls', ['enabled' => true])
            ->assertForbidden()
            ->assertJsonPath('code', 'auth.reauth_required');
    }

    /** ⚠️ رمزِ غلط ثبت و سقف‌دار است: نشستِ دزدیده نباید راهِ حدس‌زدنِ رمز باشد. */
    public function test_a_wrong_password_is_recorded_and_rate_limited(): void
    {
        $this->actingAs($this->superAdmin);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/profile/confirm-password', ['password' => 'wrong-'.$attempt])
                ->assertUnprocessable();
        }

        $this->postJson('/api/v1/profile/confirm-password', ['password' => 'secret123'])
            ->assertStatus(429);

        $this->assertSame(5, LoginEvent::where('user_id', $this->superAdmin->id)
            ->where('event', LoginEvent::FAILED_REAUTH)->count());
    }

    /** ورود با رمز (درِ مدیران) همین حالا رمز را تأیید کرده؛ مدیر بلافاصله دوباره رمز نمی‌زند. */
    public function test_signing_in_with_the_password_counts_as_a_fresh_confirmation(): void
    {
        $this->superAdmin->forceFill(['username' => 'sakena.admin'])->save();

        $this->postJson('/api/v1/admin/login', ['username' => 'sakena.admin', 'password' => 'secret123'])->assertOk();

        $this->putJson('/api/v1/system/features/polls', ['enabled' => false])->assertOk();
    }

    /**
     * 🔴 ولی ورودِ خودکارِ دستگاهِ مورداعتماد رمزی نزده — پس تأیید هم نیست.
     */
    public function test_a_trusted_device_sign_in_is_not_a_confirmation(): void
    {
        $plain = Str::random(48);
        $device = TrustedDevice::create([
            'user_id' => $this->manager->id, 'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(10),
        ]);

        $this->withUnencryptedCookie(TrustedDeviceService::COOKIE, $device->id.':'.$plain)
            ->withCredentials()
            ->postJson('/api/v1/managers', [
                'name' => 'مدیرِ دوم', 'phone' => '09120000043', 'password' => 'Secret123x',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'auth.reauth_required');

        $this->assertDatabaseMissing('users', ['phone' => '09120000043']);
    }

    /**
     * 🔴 مسیرِ پول: شماره‌کارت بدونِ رمز عوض نمی‌شود؛ ذخیره‌ی ساده رمز نمی‌خواهد.
     */
    public function test_changing_the_card_number_needs_the_password_but_a_plain_save_does_not(): void
    {
        $base = [
            'name' => $this->complex->name, 'currency' => 'toman', 'charge_due_day' => 10,
            'payment_gateway' => 'none', 'penalty_type' => 'fixed',
            'penalty_value' => 0, 'penalty_grace_days' => 0,
            'card_number' => '6037-9911-1111-1111', 'card_holder' => 'صندوق مجتمع',
        ];

        $this->actingAs($this->manager);

        $this->putJson('/api/settings', [...$base, 'card_number' => '6219-8600-0000-0000', 'card_holder' => 'مهاجم'])
            ->assertForbidden()
            ->assertJsonPath('code', 'auth.reauth_required');

        $this->assertSame('6037-9911-1111-1111', $this->complex->fresh()->settings['card_number']);

        $this->putJson('/api/settings', [...$base, 'charge_due_day' => 15])->assertOk();
        $this->assertSame(15, (int) $this->complex->fresh()->charge_due_day);

        // درگاه هم مسیرِ پول است
        $this->putJson('/api/settings', [...$base, 'payment_gateway' => 'fake'])
            ->assertForbidden();
    }

    /** دانلود پیوندِ معمولیِ مرورگر است: ۴۰۳ِ ساده، نه ۵۰۰. */
    public function test_a_plain_download_link_gets_a_clean_403(): void
    {
        $backup = Backup::create([
            'complex_id' => $this->complex->id, 'type' => 'complex', 'status' => 'completed',
            'disk' => 'local', 'path' => 'backups/reauth.json', 'size' => 2,
        ]);

        $this->actingAs($this->manager)
            ->get("/api/v1/backups/{$backup->id}/download")
            ->assertForbidden()
            ->assertSee('رمز عبورتان');
    }

    public function test_every_sensitive_route_carries_the_guard(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->keyBy(fn (RouteDefinition $r) => $r->getName());

        foreach (self::GUARDED as $name => $why) {
            $this->assertTrue($routes->has($name), "مسیرِ «{$name}» پیدا نشد.");
            $this->assertContains(
                'reauth',
                $routes[$name]->gatherMiddleware(),
                "🔴 «{$name}» ({$why}) بدونِ تأییدِ دوباره‌ی رمز باز است.",
            );
        }

        $this->assertGreaterThanOrEqual(13, count(self::GUARDED));
    }
}
