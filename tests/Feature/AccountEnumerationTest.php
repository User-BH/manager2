<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Sms\SmsManager;
use App\Support\SystemSettings;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * 🔴 پاسخِ عمومی نمی‌گوید یک شماره در سامانه حساب دارد یا نه.
 *
 * ─── یافته ───────────────────────────────────────────────────────────────
 * `POST /password/forgot` برای شماره‌ی دارای حساب ۲۰۰ و برای بقیه ۴۲۲
 * می‌داد، و `POST /register` بی‌هیچ پیامکی «این شماره قبلاً ثبت شده» را
 * برمی‌گرداند. با پاسخِ یکسان هم چهار نشانه می‌ماند که این فایل یکی‌یکی
 * می‌سنجد: **فاصله‌ی ارسالِ مجدد** (ردیفِ کد فقط برای حسابِ موجود ساخته
 * می‌شد)، **گامِ دوم** («حساب پیدا نشد» در برابرِ «کد نادرست»)، **شکستِ
 * پیامک**، و **زمان** (bcrypt و تماسِ شبکه‌ایِ پیامک فقط در یک مسیر).
 *
 * هر تست دو شماره را کنارِ هم می‌گذارد: یکی با حساب، یکی بی‌حساب (و جایی
 * که معنا دارد، حسابِ غیرفعال) — و می‌خواهد مهاجم فرقی نبیند.
 */
class AccountEnumerationTest extends TestCase
{
    use RefreshDatabase;

    private const KNOWN = '09120000500';

    private const INACTIVE = '09120000501';

    private const UNKNOWN = '09129999500';

    /** @var list<array{phone:string, at:float}> */
    private array $delivered = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::KNOWN => true, self::INACTIVE => false] as $phone => $active) {
            User::create([
                'name' => 'کاربر', 'phone' => $phone, 'role' => UserRole::Owner,
                'password' => Hash::make('secret123'), 'is_active' => $active,
            ]);
        }

        // پیامکِ ساختگی که زمانِ فرستادن را ثبت می‌کند
        $test = $this;
        $this->app->instance(SmsManager::class, new class($test) extends SmsManager
        {
            public function __construct(private AccountEnumerationTest $test) {}

            public function sendOtp(string $phone, string $code): bool
            {
                $this->test->recordDelivery($phone);

                return true;
            }
        });
    }

    public function recordDelivery(string $phone): void
    {
        $this->delivered[] = ['phone' => $phone, 'at' => microtime(true)];
    }

    private function forgot(string $phone): TestResponse
    {
        // هر درخواست مثلِ مرورگرِ تازه — بی‌نشستِ بازیابیِ قبلی
        $this->flushSession();

        return $this->postJson('/api/password/forgot', ['phone' => $phone]);
    }

    /** پاسخ بی‌`dev_code` (کدِ محیطِ توسعه) و بی‌شماره، برای مقایسه‌ی عین‌به‌عین. */
    private function comparable(TestResponse $response, string $phone): array
    {
        $body = $response->json();
        unset($body['dev_code'], $body['requestId']);

        return [
            'status' => $response->status(),
            'keys' => array_keys($response->json()),
            'body' => json_decode(str_replace($phone, '{phone}', (string) json_encode($body, JSON_UNESCAPED_UNICODE)), true),
        ];
    }

    /* ─────────────────────────── فراموشیِ رمز ─────────────────────────── */

    public function test_forgot_password_answers_identically_with_or_without_an_account(): void
    {
        $known = $this->comparable($this->forgot(self::KNOWN), self::KNOWN);
        $unknown = $this->comparable($this->forgot(self::UNKNOWN), self::UNKNOWN);
        $inactive = $this->comparable($this->forgot(self::INACTIVE), self::INACTIVE);

        $this->assertSame(200, $known['status']);
        $this->assertSame($known, $unknown, 'شماره‌ی بی‌حساب پاسخِ متفاوتی گرفت.');
        $this->assertSame($known, $inactive, 'حسابِ غیرفعال پاسخِ متفاوتی گرفت.');

        // پیامک فقط به صاحبِ حسابِ فعال رفت
        $this->assertSame([self::KNOWN], array_column($this->delivered, 'phone'));
    }

    /**
     * با درایورِ واقعیِ پیامک `dev_code` همیشه تهی است؛ پس کلِ بدنه عین‌به‌عین
     * یکی است (در محیطِ توسعه با درایورِ log، `dev_code` فقط برای حسابِ موجود پر
     * است — و آن محیط هرگز رو به اینترنت نیست؛ قیدِ R20).
     */
    public function test_with_a_real_sms_driver_the_whole_body_is_byte_identical(): void
    {
        SystemSettings::set('sms_driver', 'kavenegar');

        $known = $this->forgot(self::KNOWN)->assertOk()->json();
        $unknown = $this->forgot(self::UNKNOWN)->assertOk()->json();
        unset($known['requestId'], $unknown['requestId']);

        $this->assertSame($known, $unknown);
        $this->assertNull($known['dev_code']);
    }

    /**
     * 🔴 فاصله‌ی ارسالِ مجدد نشانه نیست — نه در همین مسیر، نه در ثبت‌نام.
     *
     * ردیفِ کد برای هر شماره ساخته می‌شود؛ وگرنه درخواستِ دوم (یا ثبت‌نام با
     * همان شماره) فقط برای حسابِ موجود «کمی صبر کنید» می‌گرفت.
     */
    public function test_the_resend_cooldown_is_the_same_for_every_number(): void
    {
        $this->forgot(self::KNOWN)->assertOk();
        $this->forgot(self::UNKNOWN)->assertOk();

        $again = fn (string $phone) => $this->comparable($this->forgot($phone), $phone);
        $this->assertSame($again(self::KNOWN), $again(self::UNKNOWN));
        $this->assertSame(422, $again(self::KNOWN)['status']);

        $register = fn (string $phone) => $this->comparable($this->postJson('/api/register', [
            'first_name' => 'مهاجم', 'last_name' => 'ناشناس', 'phone' => $phone, 'password' => 'newpass123',
            'password_confirmation' => 'newpass123', 'accept_terms' => true,
        ]), $phone);
        $this->flushSession();
        $this->assertSame($register(self::KNOWN), $register(self::UNKNOWN), 'فاصله‌ی ارسالِ مجدد از راهِ ثبت‌نام نشانه شد.');

        $this->assertSame(1, OtpCode::where('phone', self::UNKNOWN)->count(), 'برای شماره‌ی بی‌حساب هم باید ردیفِ کد (بی‌پیامک) ساخته شود.');
    }

    /** گامِ دوم همان پیامِ «کد نادرست» را می‌دهد — نه «حساب پیدا نشد». */
    public function test_the_verify_step_does_not_reveal_a_missing_account(): void
    {
        $verify = function (string $phone): array {
            $this->forgot($phone)->assertOk();
            $response = $this->postJson('/api/password/forgot/verify', ['code' => '000000']);

            return $this->comparable($response, $phone);
        };

        $this->assertSame($verify(self::KNOWN), $verify(self::UNKNOWN));
        $this->assertSame(422, $verify(self::INACTIVE)['status']);
    }

    /** برای شماره‌ی بی‌حساب هیچ کدی درست نیست؛ پس هیچ بازنشانی‌ای ممکن نیست. */
    public function test_an_unknown_number_can_never_reach_the_reset_step(): void
    {
        $this->forgot(self::UNKNOWN)->assertOk()->assertJsonPath('dev_code', null);

        $this->postJson('/api/password/forgot/verify', ['code' => '123456'])->assertStatus(422);
        $this->postJson('/api/password/reset', ['password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('users', ['phone' => self::UNKNOWN]);
        $this->assertGuest();
    }

    /* ─────────────────────────── زمان ─────────────────────────── */

    /**
     * 🔴 پیامک **پس از** ارسالِ پاسخ فرستاده می‌شود.
     *
     * درخواست مستقیم از هسته گذرانده می‌شود تا مرزِ «پاسخ آماده شد» و
     * «پس از پاسخ» جدا دیده شود (کلاینتِ تستِ لاراول هر دو را پشتِ هم اجرا
     * می‌کند). اگر پیامک پیش از پاسخ برود، زمانِ پاسخ به شبکه‌ی پیامک وابسته
     * است و همین شماره‌ی دارای حساب را لو می‌دهد.
     */
    public function test_the_sms_leaves_only_after_the_response(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $request = Request::create('/api/password/forgot', 'POST', server: [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
        ], content: (string) json_encode(['phone' => self::KNOWN]));

        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->delivered, 'پیامک پیش از آماده‌شدنِ پاسخ رفت.');

        $kernel->terminate($request, $response);
        $this->assertSame([self::KNOWN], array_column($this->delivered, 'phone'));
    }

    /**
     * هر دو مسیر یک بار bcrypt می‌پردازند — هم در فراموشیِ رمز و هم در ورود.
     *
     * ⚠️ این سنجشِ ساختاری است و عمداً: اندازه‌گیریِ مستقیمِ میلی‌ثانیه در
     * تست ناپایدار است. تستِ بعدی همان را با زمانِ واقعی و حاشیه‌ی گشاده
     * می‌سنجد.
     */
    public function test_both_paths_pay_the_same_hashing_cost(): void
    {
        $count = function (callable $action): array {
            $calls = ['make' => 0, 'check' => 0];
            $real = $this->app->make('hash');
            Hash::swap($counting = new class($real, $calls) implements Hasher
            {
                public function __construct(private Hasher $real, private array &$calls) {}

                public function info($hashedValue)
                {
                    return $this->real->info($hashedValue);
                }

                public function needsRehash($hashedValue, array $options = [])
                {
                    return $this->real->needsRehash($hashedValue, $options);
                }

                public function make($value, array $options = [])
                {
                    $this->calls['make']++;

                    return $this->real->make($value, $options);
                }

                public function check($value, $hashed, array $options = [])
                {
                    $this->calls['check']++;

                    return $this->real->check($value, $hashed, $options);
                }

                public function __call($method, $args)
                {
                    return $this->real->{$method}(...$args);
                }
            });

            // ⚠️ همان نمونه به کانتینر هم می‌رود، تا `EloquentUserProvider` هم از آن بخواند
            $this->app->instance('hash', $counting);
            $action();
            Hash::swap($real);
            $this->app->instance('hash', $real);

            return $calls;
        };

        // فراموشیِ رمز: یک هشِ کد در هر دو مسیر
        $this->assertSame(
            $count(fn () => $this->forgot(self::KNOWN)->assertOk()),
            $count(fn () => $this->forgot(self::UNKNOWN)->assertOk()),
        );

        // ورود: شماره‌ی بی‌حساب هم یک بار bcrypt می‌سنجد
        $login = fn (string $phone) => $count(fn () => $this->postJson('/api/login', ['phone' => $phone, 'password' => 'wrong-pass'])->assertStatus(422));
        $this->assertSame($login(self::KNOWN)['check'], $login(self::UNKNOWN)['check']);
        $this->assertSame(1, $login(self::UNKNOWN)['check']);

        // گامِ تأیید وقتی اصلاً ردیفِ کدی نمانده (مثلاً منقضی و پاک‌شده): باز هم یک bcrypt
        $this->forgot('09129999501')->assertOk();
        OtpCode::query()->delete();
        $verify = $count(fn () => $this->postJson('/api/password/forgot/verify', ['code' => '000000'])->assertStatus(422));
        $this->assertSame(1, $verify['check'], 'نبودِ ردیفِ کد زودتر از سنجیدنِ هش جواب داد.');
    }

    /**
     * زمانِ واقعیِ پاسخ، با پیامکی که ۱۵۰ میلی‌ثانیه طول می‌کشد (شبیهِ شبکه).
     *
     * پیش از اصلاح، مسیرِ حسابِ موجود دستِ‌کم همین ۱۵۰ میلی‌ثانیه کندتر بود.
     * حاشیه‌ی ۶۰ میلی‌ثانیه گشاده است تا تست روی ماشینِ شلوغ ناپایدار نشود،
     * ولی همان تفاوتِ شبکه‌ای را قطعاً می‌گیرد.
     */
    public function test_response_time_does_not_depend_on_the_account(): void
    {
        $this->app->instance(SmsManager::class, new class extends SmsManager
        {
            public function sendOtp(string $phone, string $code): bool
            {
                usleep(150_000);

                return true;
            }
        });

        $kernel = $this->app->make(Kernel::class);
        $time = function (string $phone) use ($kernel): float {
            // هر درخواست بی‌فاصله‌ی ارسالِ مجدد و سقفِ نرخ، تا فقط خودِ مسیر سنجیده شود
            OtpCode::query()->delete();
            Cache::flush();

            $request = Request::create('/api/password/forgot', 'POST', server: [
                'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            ], content: (string) json_encode(['phone' => $phone]));

            $start = hrtime(true);
            $response = $kernel->handle($request);
            $elapsed = (hrtime(true) - $start) / 1e6;

            $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
            $kernel->terminate($request, $response);

            return $elapsed;
        };

        $median = function (string $phone) use ($time): float {
            $samples = array_map(fn () => $time($phone), range(1, 7));
            sort($samples);

            return $samples[3];
        };

        $time(self::KNOWN); // گرم‌کردن
        $difference = abs($median(self::KNOWN) - $median(self::UNKNOWN));

        $this->assertLessThan(60, $difference, "اختلافِ میانه‌ی زمانِ پاسخ {$difference}ms است.");
    }

    /* ─────────────────────────── ثبت‌نام ─────────────────────────── */

    /**
     * 🔴 «این شماره قبلاً ثبت شده» فقط پس از تأییدِ کد — یعنی فقط به صاحبِ
     * همان سیم‌کارت.
     */
    public function test_registration_does_not_reveal_an_existing_number_before_the_code(): void
    {
        $step = function (string $phone): array {
            $this->flushSession();

            return $this->comparable($this->postJson('/api/register', [
                'first_name' => 'کسی', 'last_name' => 'ناشناس', 'phone' => $phone, 'password' => 'newpass123',
                'password_confirmation' => 'newpass123', 'accept_terms' => true,
            ]), $phone);
        };

        $known = $step(self::KNOWN);
        $this->assertSame(200, $known['status']);
        $this->assertSame($known, $step(self::UNKNOWN), 'ثبت‌نام وجودِ حساب را پیش از کد لو داد.');

        // صاحبِ شماره کد را وارد می‌کند — حالا، و فقط حالا، می‌شنود که حساب دارد
        $this->flushSession();
        $code = $this->postJson('/api/register', [
            'first_name' => 'صاحب', 'last_name' => 'شماره', 'phone' => '09120000502', 'password' => 'newpass123',
            'password_confirmation' => 'newpass123', 'accept_terms' => true,
        ])->json('dev_code');
        User::create(['name' => 'هم‌زمان', 'phone' => '09120000502', 'role' => UserRole::Owner, 'password' => Hash::make('x'), 'is_active' => true]);

        $this->postJson('/api/register/verify', ['code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'این شماره قبلاً ثبت شده است. وارد شوید.');
    }

    /* ─────────────────────────── ورود ─────────────────────────── */

    public function test_login_answers_identically_for_an_unknown_number_and_a_wrong_password(): void
    {
        $known = $this->comparable($this->postJson('/api/login', ['phone' => self::KNOWN, 'password' => 'wrong-pass']), self::KNOWN);
        $unknown = $this->comparable($this->postJson('/api/login', ['phone' => self::UNKNOWN, 'password' => 'wrong-pass']), self::UNKNOWN);

        $this->assertSame(422, $known['status']);
        $this->assertSame($known, $unknown);
    }
}
