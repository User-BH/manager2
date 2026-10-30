<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\LoginEvent;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\NewDeviceLoginNotification;
use App\Services\Auth\LoginRecorder;
use App\Services\Auth\TrustedDeviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * تاریخچه‌ی ورود و هشدارِ «دستگاهِ تازه» (مرحلهٔ ۵ · فاز ۱۰).
 */
class LoginHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'ساکن', 'phone' => '09120000051',
            'role' => UserRole::Owner, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
    }

    /**
     * ورود از «مرورگری» با نشانه‌ی دستگاهِ داده‌شده (یا بی‌نشانه): شماره ← کدِ پیامکی (۲۰۲۶-۱۰-۳۰). `$rightCode = false`
     * یعنی کدِ نادرست — همان «تلاشِ ناموفق» ِ امروز.
     */
    private function signInFrom(?string $deviceToken = null, bool $rightCode = true): TestResponse
    {
        // فاصله‌ی ارسالِ دوباره‌ی کدِ همین شماره (`OtpService::RESEND_COOLDOWN`)
        $this->travel(61)->seconds();

        // هر ورود از «مرورگرِ» تازه: هیچ نشستی از درخواستِ قبلی نماند
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->defaultCookies = [];

        if ($deviceToken) {
            $this->withCredentials()->withCookie(LoginRecorder::DEVICE_COOKIE, $deviceToken);
        }

        $code = $this->postJson('/api/login', ['phone' => $this->user->phone])->assertOk()->json('dev_code');

        return $this->postJson('/api/login/verify', ['code' => $rightCode ? $code : '000000']);
    }

    private function events(): array
    {
        return LoginEvent::where('user_id', $this->user->id)->orderBy('id')->pluck('event')->all();
    }

    public function test_failed_and_successful_attempts_are_recorded(): void
    {
        $this->signInFrom(rightCode: false)->assertUnprocessable();
        $this->signInFrom()->assertOk();

        $this->assertSame([LoginEvent::FAILED_OTP, LoginEvent::SUCCESS], $this->events());
    }

    public function test_a_wrong_code_is_recorded_too(): void
    {
        $this->postJson('/api/login', ['phone' => $this->user->phone])->assertOk();
        $this->postJson('/api/login/verify', ['code' => '000000'])->assertUnprocessable();

        $this->assertSame([LoginEvent::FAILED_OTP], $this->events());
    }

    /**
     * ⚠️ شماره‌ای که حساب ندارد ثبت نمی‌شود: صاحبی ندارد که ببیندش، و ثبتش
     * فقط شماره‌ی آدم‌های بیرونی را انبار می‌کرد.
     */
    public function test_an_unknown_phone_leaves_no_row(): void
    {
        // همان پاسخِ «کد فرستاده شد»؛ هر کدی نادرست است و ردیفی نمی‌ماند
        $this->postJson('/api/login', ['phone' => '09129999999'])->assertOk();
        $this->postJson('/api/login/verify', ['code' => '000000'])->assertUnprocessable();

        $this->assertSame(0, LoginEvent::count());
    }

    public function test_a_login_from_a_new_device_raises_an_alert_and_a_known_one_does_not(): void
    {
        Notification::fake();

        $laptop = Str::random(40);

        // اولین ورودِ عمر «تازه» نیست
        $this->signInFrom($laptop)->assertOk();
        Notification::assertNothingSent();

        // همان دستگاه
        $this->signInFrom($laptop)->assertOk();
        Notification::assertNothingSent();

        // دستگاهِ دیگر
        $this->signInFrom(Str::random(40))->assertOk();
        Notification::assertSentTo($this->user, NewDeviceLoginNotification::class);
        Notification::assertSentTimes(NewDeviceLoginNotification::class, 1);
    }

    /** دستگاهِ مورداعتماد تعریفاً آشناست؛ ورودِ خودکارش ثبت می‌شود ولی هشدار نه. */
    public function test_a_trusted_device_sign_in_is_recorded_without_an_alert(): void
    {
        Notification::fake();

        $this->signInFrom(Str::random(40))->assertOk();

        $plain = Str::random(48);
        $device = TrustedDevice::create([
            'user_id' => $this->user->id, 'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(10),
        ]);

        // هر ورود از «مرورگرِ» تازه: هیچ نشستی از درخواستِ قبلی نماند
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->withUnencryptedCookie(TrustedDeviceService::COOKIE, $device->id.':'.$plain)
            ->withCredentials()
            ->getJson('/api/me')
            ->assertJsonPath('user.id', $this->user->id);

        $this->assertSame([LoginEvent::SUCCESS, LoginEvent::TRUSTED_DEVICE], $this->events());
        Notification::assertNothingSent();
    }

    public function test_each_user_sees_only_their_own_history_newest_first(): void
    {
        $this->signInFrom(rightCode: false);
        $this->signInFrom();

        $neighbour = User::create([
            'name' => 'همسایه', 'phone' => '09120000052',
            'role' => UserRole::Owner, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
        LoginEvent::create(['user_id' => $neighbour->id, 'event' => LoginEvent::SUCCESS, 'ip_address' => '10.9.9.9']);

        $events = $this->actingAs($this->user)->getJson('/api/v1/profile/login-history')
            ->assertOk()
            ->json('events');

        $this->assertCount(2, $events);
        $this->assertSame([false, true], array_column($events, 'failed'));
        $this->assertNotContains('10.9.9.9', array_column($events, 'ip'));
    }
}
