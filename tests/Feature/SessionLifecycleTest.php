<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Auth\SessionRegistry;
use App\Services\Auth\TrustedDeviceService;
use App\Support\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * چرخه‌ی عمرِ نشست، **رفتاری** و با کوکیِ واقعی (مرحلهٔ ۵ · فاز ۱۰).
 *
 * ─── 🔴 چرا این کلاس جدا و این‌قدر دست‌ساز است ─────────────────────────────
 * `AuthorizationAttackTest` صریح نوشته بود بازتولیدِ نشست «NOT VERIFIED» است:
 * سنجشِ رفتاری درایورِ ماندگار و حملِ کوکی بینِ درخواست‌ها می‌خواست. همین
 * کمبود بود که نشان نداد تغییرِ رمز نشستِ دزدیده را **نمی‌بندد**.
 *
 * اینجا درایورِ `database` فقط برای همین کلاس روشن است و هر «مرورگر» ظرفِ
 * کوکیِ خودش را دارد. بینِ درخواست‌ها نگهبانِ احراز و انبارِ نشست از نو
 * ساخته می‌شوند — وگرنه کاربرِ درخواستِ قبلی در حافظه می‌ماند و هر ادعای
 * «بیرون افتاد» دروغ سبز می‌شد.
 */
class SessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
        SystemSettings::set('otp_disabled', true);

        $complex = Complex::create(['type' => 'residential',
            'name' => 'مجتمع نشست', 'slug' => 'session-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'none',
        ]);

        $this->user = User::create([
            'complex_id' => $complex->id, 'name' => 'ساکن', 'phone' => '09120000031',
            'role' => UserRole::Owner, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->other = User::create([
            'complex_id' => $complex->id, 'name' => 'همسایه', 'phone' => '09120000032',
            'role' => UserRole::Owner, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
    }

    /* ─────────────────────────── مرورگرِ ساختگی ─────────────────────────── */

    /**
     * یک درخواست از «مرورگری» که کوکی‌هایش در `$jar` است؛ کوکی‌های پاسخ به
     * همان ظرف برمی‌گردند.
     *
     * @param  array<string, string>  $jar
     */
    private function browse(array &$jar, string $method, string $uri, array $data = []): TestResponse
    {
        // 🔴 هیچ چیزی از درخواستِ قبلی در حافظه نماند
        $this->app['auth']->forgetGuards();
        // ⚠️ `user_id`ِ ردیفِ نشست از همین نمونه‌ی تکی خوانده می‌شود؛ کهنه بماند، نشست به کاربرِ قبلی نسبت داده می‌شود
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        // نگهبان انبارِ نشست را از این نمونه‌ی تکی می‌گیرد، نه از مدیرِ نشست
        $this->app->forgetInstance('session.store');
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        // ⚠️ بدونِ این، `json()` هیچ کوکی‌ای نمی‌فرستد و هر مرورگر هر بار تازه است
        $this->withCredentials();

        foreach ($jar as $name => $value) {
            $name === TrustedDeviceService::COOKIE
                ? $this->withUnencryptedCookie($name, $value)
                : $this->withCookie($name, $value);
        }

        $response = $this->json($method, $uri, $data);

        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            $name = $cookie->getName();

            if ($cookie->isCleared()) {
                unset($jar[$name]);

                continue;
            }

            $jar[$name] = $name === TrustedDeviceService::COOKIE
                ? $cookie->getValue()
                : (string) $response->getCookie($name, decrypt: true)?->getValue();
        }

        return $response;
    }

    /** @return array<string, string> ظرفِ کوکیِ مرورگری که همین حالا وارد شده */
    private function signIn(User $user, bool $remember = false): array
    {
        $jar = [];
        $this->browse($jar, 'POST', '/api/login', [
            'phone' => $user->phone, 'password' => 'secret123', 'remember' => $remember,
        ])->assertOk()->assertJsonPath('user.id', $user->id);

        return $jar;
    }

    /** @param  array<string, string>  $jar */
    private function whoIs(array &$jar): ?int
    {
        return $this->browse($jar, 'GET', '/api/me')->json('user.id');
    }

    private function sessionId(array $jar): string
    {
        return $jar[config('session.cookie')];
    }

    /* ─────────────────────────── تغییر و بازیابیِ رمز ─────────────────────────── */

    /**
     * 🔴 مهم‌ترین ادعای فاز: رمز که عوض شد، نشستِ دزدیده بسته است.
     *
     * پیش از اصلاح، مرورگرِ مهاجم پس از تغییرِ رمز هنوز کاربر را می‌دید.
     */
    public function test_changing_the_password_signs_out_a_stolen_session(): void
    {
        $victim = $this->signIn($this->user);
        $attacker = $this->signIn($this->user);

        $this->assertSame($this->user->id, $this->whoIs($attacker));

        $this->browse($victim, 'PUT', '/api/profile/password', [
            'current_password' => 'secret123',
            'password' => 'NewSecret456x',
            'password_confirmation' => 'NewSecret456x',
        ])->assertOk();

        $this->assertNull($this->whoIs($attacker), '🔴 نشستِ دزدیده پس از تغییرِ رمز زنده ماند.');
        $this->assertSame($this->user->id, $this->whoIs($victim), 'خودِ کاربر نباید بیرون بیفتد.');
    }

    /**
     * 🔴 شناسه‌ی قبلیِ همین نشست هم مرده است.
     *
     * `regenerate()` بدونِ `true` ردیفِ قبلی را واردشده نگه می‌داشت؛ کسی که
     * همان شناسه را دزدیده بود با آن ادامه می‌داد.
     */
    public function test_the_old_session_id_is_destroyed_by_a_password_change(): void
    {
        $victim = $this->signIn($this->user);
        $stolen = $victim;

        $this->browse($victim, 'PUT', '/api/profile/password', [
            'current_password' => 'secret123',
            'password' => 'NewSecret456x',
            'password_confirmation' => 'NewSecret456x',
        ])->assertOk();

        $this->assertNotSame($this->sessionId($stolen), $this->sessionId($victim));
        $this->assertNull($this->whoIs($stolen), '🔴 شناسه‌ی قبلیِ نشست هنوز واردشده است.');
    }

    public function test_resetting_the_password_signs_out_every_session(): void
    {
        SystemSettings::set('otp_disabled', false);
        SystemSettings::forget();

        // ورودِ مهاجم (با کد) پیش از بازیابی
        $attacker = [];
        $code = $this->browse($attacker, 'POST', '/api/login', [
            'phone' => $this->user->phone, 'password' => 'secret123',
        ])->json('dev_code');
        $this->browse($attacker, 'POST', '/api/login/verify', ['code' => $code])->assertOk();
        $this->assertSame($this->user->id, $this->whoIs($attacker));

        // صاحبِ حساب از مرورگرِ خودش رمز را بازیابی می‌کند
        $owner = [];
        $this->travel(2)->minutes();
        $code = $this->browse($owner, 'POST', '/api/password/forgot', ['phone' => $this->user->phone])->json('dev_code');
        $this->browse($owner, 'POST', '/api/password/forgot/verify', ['code' => $code])->assertOk();
        $this->browse($owner, 'POST', '/api/password/reset', [
            'password' => 'NewSecret456x', 'password_confirmation' => 'NewSecret456x',
        ])->assertOk();

        $this->assertNull($this->whoIs($attacker), '🔴 بازیابیِ رمز نشستِ مهاجم را نبست.');
        $this->assertSame($this->user->id, $this->whoIs($owner));
    }

    /* ─────────────────────────── فهرست و بستنِ نشست ─────────────────────────── */

    public function test_the_session_list_never_exposes_a_raw_session_id(): void
    {
        $laptop = $this->signIn($this->user);
        $phone = $this->signIn($this->user);

        $response = $this->browse($laptop, 'GET', '/api/v1/profile/sessions')->assertOk();

        $this->assertCount(2, $response->json('sessions'));
        $this->assertSame([true, false], collect($response->json('sessions'))->sortByDesc('current')->pluck('current')->all());

        // 🔴 شناسه‌ی نشست خودش راز است
        $body = $response->getContent();
        $this->assertStringNotContainsString($this->sessionId($laptop), $body);
        $this->assertStringNotContainsString($this->sessionId($phone), $body);
    }

    public function test_ending_another_session_signs_it_out(): void
    {
        $laptop = $this->signIn($this->user);
        $phone = $this->signIn($this->user);

        $target = app(SessionRegistry::class)->publicId($this->sessionId($phone));

        // ورود همین حالا رمز را تأیید کرده؛ پنجره‌ی تأییدِ دوباره باز است
        $this->browse($laptop, 'DELETE', "/api/v1/profile/sessions/{$target}")->assertOk();

        $this->assertNull($this->whoIs($phone));
        $this->assertSame($this->user->id, $this->whoIs($laptop));
    }

    /** نشستِ کاربرِ دیگر با این مسیر بسته نمی‌شود — حتی با شناسه‌ی عمومیِ درست. */
    public function test_a_user_cannot_end_someone_elses_session(): void
    {
        $mine = $this->signIn($this->user);
        $theirs = $this->signIn($this->other);

        $target = app(SessionRegistry::class)->publicId($this->sessionId($theirs));

        $this->browse($mine, 'DELETE', "/api/v1/profile/sessions/{$target}")->assertNotFound();
        $this->assertSame($this->other->id, $this->whoIs($theirs));
    }

    /**
     * 🔴 «خروج از همه‌جا» دستگاهِ مورداعتماد را هم می‌گیرد.
     *
     * بستنِ نشست به‌تنهایی بی‌اثر بود: کوکیِ دستگاه در درخواستِ بعدی دوباره
     * واردش می‌کرد.
     */
    public function test_ending_other_sessions_also_revokes_their_trusted_devices(): void
    {
        $laptop = $this->signIn($this->user, remember: true);
        $phone = $this->signIn($this->user, remember: true);

        $this->assertSame(2, $this->user->trustedDevices()->count());

        $this->browse($laptop, 'POST', '/api/v1/profile/sessions/end-others')->assertOk();

        $this->assertNull($this->whoIs($phone), '🔴 کوکیِ دستگاهِ مورداعتماد دوباره واردش کرد.');
        $this->assertSame($this->user->id, $this->whoIs($laptop));
        $this->assertSame(1, $this->user->trustedDevices()->count(), 'دستگاهِ همین مرورگر باید بماند.');
    }

    /* ─────────────────────────── سقفِ عمر و ورودِ خودکار ─────────────────────────── */

    /**
     * 🔴 نشستی که مدام استفاده شود هم تمام می‌شود.
     *
     * هر یک ساعت یک درخواست — هرگز بیکار نمی‌ماند — و باز هم پس از سقفِ
     * مطلق بیرون می‌افتد.
     */
    public function test_an_active_session_still_ends_at_the_absolute_lifetime(): void
    {
        config(['session.absolute_lifetime' => 180, 'session.lifetime' => 120]);

        $browser = $this->signIn($this->user);

        foreach ([1, 2] as $hour) {
            $this->travel(59)->minutes();
            $this->assertSame($this->user->id, $this->whoIs($browser), "ساعتِ {$hour}");
        }

        $this->travel(65)->minutes();

        $this->browse($browser, 'GET', '/api/v1/units')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'auth.session_expired');

        $this->assertNull($this->whoIs($browser));
    }

    /**
     * ورودِ خودکارِ دستگاهِ مورداعتماد شناسه‌ی نشست را عوض می‌کند
     * (session fixation).
     *
     * ⚠️ این از قبل درست بود: `login()`ِ نگهبانِ لاراول خودش `regenerate(true)`
     * می‌کند. خرابکاری (برداشتنِ یک `regenerate()`ِ اضافه) تست را قرمز نکرد و
     * همین نشان داد آن سطر زائد بود. تست می‌ماند تا اگر روزی ورودِ خودکار از
     * راهی بگذرد که نشست را نچرخاند، قرمز شود.
     */
    public function test_a_trusted_device_sign_in_rotates_the_session_id(): void
    {
        $plain = Str::random(48);
        $device = TrustedDevice::create([
            'user_id' => $this->user->id, 'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(10),
        ]);

        // مرورگری با شناسه‌ی نشستِ مهمانی که «کسی از پیش کاشته»
        $browser = [];
        $this->browse($browser, 'GET', '/api/me');
        $planted = $this->sessionId($browser);

        $browser[TrustedDeviceService::COOKIE] = $device->id.':'.$plain;
        $this->assertSame($this->user->id, $this->whoIs($browser));

        $this->assertNotSame($planted, $this->sessionId($browser), '🔴 شناسه‌ی کاشته‌شده واردشده ماند.');

        $fixed = [config('session.cookie') => $planted];
        $this->assertNull($this->whoIs($fixed));
    }

    /**
     * 🔴 سوپرادمین هر بار رمز و کد می‌زند؛ کوکیِ ده‌روزه برایش صادر و پذیرفته
     * نمی‌شود.
     */
    public function test_a_super_admin_never_rides_a_trusted_device(): void
    {
        $admin = User::create([
            'name' => 'مدیرِ سامانه', 'phone' => '09120000033',
            'role' => UserRole::SuperAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->signIn($admin, remember: true);
        $this->assertSame(0, $admin->trustedDevices()->count(), 'برای سوپرادمین دستگاهِ مورداعتماد ساخته شد.');

        // دستگاهی که پیش از این قاعده ساخته شده بود
        $plain = Str::random(48);
        $device = TrustedDevice::create([
            'user_id' => $admin->id, 'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(10),
        ]);

        $browser = [TrustedDeviceService::COOKIE => $device->id.':'.$plain];
        $this->assertNull($this->whoIs($browser), '🔴 دستگاهِ قدیمیِ سوپرادمین بی‌رمز واردش کرد.');
    }

    /** ⚠️ مدیریتِ نشست فقط روی درایورِ database واقعی است؛ درایورِ همه‌ی محیط‌ها همان است. */
    public function test_every_environment_uses_the_database_session_driver(): void
    {
        foreach (['.env.example', '.env.production.example', '.env.staging.example'] as $file) {
            $this->assertMatchesRegularExpression(
                '/^SESSION_DRIVER=database$/m',
                (string) file_get_contents(base_path($file)),
                "{$file}: مدیریتِ نشست و «خروج از همه‌جا» روی درایورِ دیگر کار نمی‌کند.",
            );
        }

        $this->assertStringContainsString("env('SESSION_DRIVER', 'database')", (string) file_get_contents(config_path('session.php')));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('sessions', 'user_id'));
    }
}
