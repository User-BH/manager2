<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountState;
use App\Enums\ComplexType;
use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminLoginRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\LoginTwoFactorRequest;
use App\Http\Requests\LoginVerifyRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\RegisterVerifyRequest;
use App\Listeners\StartAuthenticatedSession;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\ComplexMembership;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Account\Membership;
use App\Services\Account\ProfilePhoto;
use App\Services\Assistant\AssistantAccess;
use App\Services\Auth\AdminTwoFactor;
use App\Services\Auth\LoginRecorder;
use App\Services\Auth\OtpService;
use App\Services\Auth\PasswordConfirmation;
use App\Services\Auth\SessionRegistry;
use App\Services\Auth\TrustedDeviceService;
use App\Services\Messenger\Presence;
use App\Support\Audit;
use App\Support\DemoData;
use App\Support\Jalali;
use App\Support\Notifications;
use App\Support\PersianName;
use App\Support\Phone;
use App\Support\Preview;
use App\Support\SessionContext;
use App\Support\Username;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * احراز هویت برای اپلیکیشن React.
 *
 * ورود دومرحله‌ای است: رمز عبور، سپس کد پیامکیِ شش‌رقمی. تنها استثنا دستگاهِ
 * مورداعتماد است (پس از «مرا به خاطر بسپار») که تا ۱۰ روز هر دو مرحله را رد
 * می‌کند. نشست و کوکی همان نشست لاراول است، نه توکن bearer.
 */
class AuthController extends Controller
{
    /** پیامِ یکسانِ «نیست یا رمز غلط» — هر دو نوعِ شناسه */
    public const WRONG_PHONE = 'شماره تلفن یا رمز عبور نادرست است.';

    public const WRONG_USERNAME = 'نام کاربری یا رمز عبور نادرست است.';

    /** Preview فقط حساب‌های نمونه را دارد و آن‌ها شماره ندارند (۲۰۲۶-۱۰-۲۹، بندِ ۴) */
    public const PREVIEW_USERNAME_ONLY = 'در محیطِ آزمایشی ورود فقط با نام کاربریِ حساب‌های نمونه است.';

    /** مرحله‌ی دومِ ورودِ در انتظار: کدِ پیامکی یا کدِ برنامه‌ی Authenticator ِ سوپر ادمین */
    private const FACTOR_SMS = 'sms';

    private const FACTOR_TOTP = 'totp';

    /** چالشِ TOTP: مهلت و شمارِ تلاشِ نادرست پیش از بازگشت به گامِ رمز */
    private const TOTP_PENDING_SECONDS = 300;

    private const TOTP_ATTEMPTS = 5;

    /** قفلِ هر حساب پس از تلاش‌های نادرستِ پیاپی، مستقل از نشست و IP */
    private const TOTP_LOCK_ATTEMPTS = 10;

    private const TOTP_LOCK_SECONDS = 900;

    public function __construct(
        protected TrustedDeviceService $devices,
        protected LoginRecorder $recorder,
        protected PasswordConfirmation $confirmation,
        protected SessionRegistry $sessions,
        protected AdminTwoFactor $twoFactor,
    ) {}

    /** کاربر واردشده‌ی فعلی، یا null برای مهمان. */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
            'csrfToken' => csrf_token(),
        ]);
    }

    /** توکن CSRF تازه — کلاینت پس از خطای ۴۱۹ آن را می‌گیرد و دوباره تلاش می‌کند. */
    public function csrfToken(): JsonResponse
    {
        return response()->json(['csrfToken' => csrf_token()]);
    }

    /**
     * گام ۱ ورود.
     *
     * دو شکل، و نوعِ شناسه را خودِ درخواست می‌گوید (مرحلهٔ ۱۳ · فاز ۱) — هیچ‌کدام از شکلِ دیگری حدس زده نمی‌شود:
     *
     *   - **شماره** (کاربرانِ سایت اصلی، ۲۰۲۶-۱۰-۳۰): بی رمز؛ کدِ پیامکی به شماره می‌رود و گامِ دوم همان کد است.
     *   - **نام کاربری + رمز**: فقط حساب‌های نمونه‌ی Preview (بیرون از Production).
     *
     * دو مدیرِ سامانه از این‌جا وارد نمی‌شوند — مسیرشان `adminLogin` (`/auth/admin`) است.
     */
    public function login(LoginRequest $request, OtpService $otp): JsonResponse
    {
        $request->validated();

        if (! $request->byUsername()) {
            $this->refusePhoneFlowsInPreview('phone');

            return $this->loginWithPhone($request, $otp);
        }

        $user = User::where('username', Username::normalize($request->input('username')))->first();

        /*
         * پیامِ یکسان و **زمانِ** یکسان برای «نیست»، «رمز غلط»، «مالِ حسابِ نمونه نیست» و «حسابِ نمونه روی Production»:
         * در هر حالت یک بار bcrypt سنجیده می‌شود و پاسخ نمی‌گوید چنین حسابی هست.
         */
        $passwordOk = Hash::check((string) $request->input('password'), $user->password ?? OtpService::dummyHash());
        $eligible = $user !== null && $user->signsInByUsername() && ! DemoData::refuses($user) && $user->is_active;

        if (! $eligible || ! $passwordOk) {
            if ($user) {
                $this->recorder->failure($user, $request, LoginEvent::FAILED_PASSWORD);
            }

            throw ValidationException::withMessages(['username' => self::WRONG_USERNAME]);
        }

        // حسابِ نمونه شماره و عاملِ دوم ندارد؛ Preview پشتِ کلیدِ دسترسیِ خودش است
        return $this->completeLogin($request, $user, remember: false, otp: $otp);
    }

    /**
     * ورودِ کاربرانِ سایت اصلی با شماره و کدِ پیامکی — بی رمز (۲۰۲۶-۱۰-۳۰، بندِ ۴ ِ کارفرما).
     *
     * ─── 🔴 پاسخ نمی‌گوید این شماره حساب دارد یا نه ────────────────────────
     * برای **هر** شماره‌ی معتبر همان کار انجام می‌شود (`OtpService::issue`): ردیفِ کد، همان فاصله‌ی ارسالِ دوباره، همان
     * پاسخ؛ فقط برای حسابِ واجدِ شرایط پیامک واقعاً فرستاده می‌شود. شماره‌ی بی‌حساب، حسابِ غیرفعال، مدیرِ سامانه (که فقط
     * از `/auth/admin` وارد می‌شود) و حسابِ نمونه روی Production کدی نمی‌گیرند و گامِ دوم برایشان همان «کدِ نادرست» است.
     *
     * ⚠️ «ورود بدونِ کد» (`otp_disabled`) دیگر وجود ندارد: بی رمز، ورود بی کد یعنی ورود با دانستنِ یک شماره.
     */
    private function loginWithPhone(LoginRequest $request, OtpService $otp): JsonResponse
    {
        $phone = Phone::normalize($request->input('phone'));

        if (! Phone::isValidMobile($phone)) {
            throw ValidationException::withMessages(['phone' => 'شماره تلفن همراه معتبر نیست.']);
        }

        $user = User::where('phone', $phone)->first();
        $eligible = $user !== null && $user->is_active && ! $user->isSuperAdmin() && ! DemoData::refuses($user);

        // دستگاهِ مورداعتمادِ همین حساب («مرا به خاطر بسپار») تا مهلتش گامِ کد را رد می‌کند
        if ($eligible && $this->devices->isTrustedFor($user, $request)) {
            return $this->completeLogin($request, $user, remember: false, otp: $otp);
        }

        $result = $otp->issue($phone, deliver: $eligible);
        if (! $result['ok']) {
            throw ValidationException::withMessages(['phone' => $result['error'] ?? 'ارسال کد ورود ناموفق بود.']);
        }

        $request->session()->put('login.pending', [
            'user_id' => $eligible ? $user->id : null,
            'phone' => $phone,
            'remember' => $request->boolean('remember'),
            'at' => now()->timestamp,
            'factor' => self::FACTOR_SMS,
        ]);

        return response()->json([
            'otpRequired' => true,
            'phone' => $phone,
            'message' => 'کد ورود به شماره '.$phone.' ارسال شد.',
            // فقط درایورِ log در توسعه و آزمون؛ در Production همیشه null
            'dev_code' => $result['dev_code'] ?? null,
        ]);
    }

    /**
     * ورودِ دو مدیرِ سامانه — فقط از `/auth/admin` (۲۰۲۶-۱۰-۳۰، بندِ ۴): نام کاربری + رمز، سپس کدِ برنامه‌ی Authenticator.
     *
     * - همان پیام و زمانِ «نادرست» برای نام کاربریِ ناموجود، رمزِ غلط و هر حسابی که سوپر ادمینِ واقعی نیست؛
     * - روی Production مدیرِ بی TOTP وارد نمی‌شود (راه‌اندازی: `admin:two-factor`)؛ بیرون از Production (توسعه و آزمونِ
     *   سرتاسری) مدیرِ بی TOTP با همین دو قلم وارد می‌شود؛
     * - Preview این درِ ورود را ندارد (یک درِ نام کاربری برای حساب‌های نمونه).
     */
    public function adminLogin(AdminLoginRequest $request, OtpService $otp): JsonResponse
    {
        $request->validated();

        if (Preview::active()) {
            throw ValidationException::withMessages(['username' => self::PREVIEW_USERNAME_ONLY]);
        }

        $user = User::where('username', Username::normalize($request->input('username')))->first();
        $passwordOk = Hash::check((string) $request->input('password'), $user->password ?? OtpService::dummyHash());
        $eligible = $user !== null && $user->isSuperAdmin() && ! $user->is_demo && $user->is_active;

        if (! $eligible || ! $passwordOk) {
            if ($user) {
                $this->recorder->failure($user, $request, LoginEvent::FAILED_PASSWORD);
            }

            throw ValidationException::withMessages(['username' => self::WRONG_USERNAME]);
        }

        if ($this->twoFactor->enabled($user)) {
            return $this->startTwoFactor($request, $user);
        }

        if ($this->twoFactor->setupRequired($user)) {
            throw ValidationException::withMessages([
                'username' => 'ورودِ دومرحله‌ایِ (Authenticator) این حساب هنوز راه‌اندازی نشده. روی سرور اجرا کنید: php artisan admin:two-factor <شماره موبایل>',
            ]);
        }

        return $this->completeLogin($request, $user, remember: false, otp: $otp);
    }

    /**
     * گام ۲ ورود: تایید کد پیامکی و ورود نهایی.
     */
    public function loginVerify(LoginVerifyRequest $request, OtpService $otp): JsonResponse
    {
        $request->validated();

        $pending = $request->session()->get('login.pending');

        // مرحله‌ی دومِ پیامکی فقط برای ورودی که با پیامک شروع شد — نه برای چالشِ TOTP
        if (! $pending || ($pending['factor'] ?? self::FACTOR_SMS) !== self::FACTOR_SMS) {
            throw ValidationException::withMessages([
                'code' => 'مهلت ورود تمام شده است. دوباره شماره را وارد کنید.',
            ]);
        }

        $user = $pending['user_id'] === null ? null : User::find($pending['user_id']);
        $phone = (string) ($pending['phone'] ?? $user?->phone);

        /*
         * شماره‌ی بی‌حساب (یا حسابی که دیگر واجدِ شرایط نیست) همان «کدِ نادرست» را می‌گیرد — نه پیامِ جدا؛ کدش هرگز
         * فرستاده نشده و تصادفی است. سوپر ادمین هرگز با کدِ پیامکی وارد نمی‌شود.
         */
        $verified = $otp->verify($phone, (string) $request->input('code'));
        if (! $verified || ! $user || ! $user->is_active || $user->isSuperAdmin() || DemoData::refuses($user)) {
            if ($user) {
                $this->recorder->failure($user, $request, LoginEvent::FAILED_OTP);
            }

            throw ValidationException::withMessages([
                'code' => 'کد واردشده نادرست یا منقضی شده است.',
            ]);
        }

        $request->session()->forget('login.pending');

        return $this->completeLogin($request, $user, remember: (bool) ($pending['remember'] ?? false), otp: $otp);
    }

    /**
     * گام ۲ ِ ورودِ سوپر ادمین با TOTP: کدِ برنامه‌ی Authenticator یا یک کدِ بازیابیِ یک‌بارمصرف.
     *
     * - چالش فقط از گامِ رمزِ همین نشست می‌آید و پنج دقیقه زنده است؛ پنج کدِ نادرست ← بازگشت به گامِ رمز.
     * - مستقل از نشست و IP، هر حساب پس از ده کدِ نادرست ۱۵ دقیقه قفل است — نشستِ تازه یا IP ِ دیگر شمارش را صفر
     *   نمی‌کند.
     * - کدِ پذیرفته‌شده دوباره پذیرفته نمی‌شود (`two_factor_last_step`)؛ کدِ بازیابی پس از مصرف از فهرست بیرون می‌رود.
     */
    public function loginTwoFactor(LoginTwoFactorRequest $request, OtpService $otp): JsonResponse
    {
        $data = $request->validated();
        $field = filled($data['recovery_code'] ?? null) ? 'recovery_code' : 'code';
        $expired = 'مهلت ورود تمام شده است. دوباره با رمز عبور وارد شوید.';

        $pending = $request->session()->get('login.pending');
        if (! $pending || ($pending['factor'] ?? null) !== self::FACTOR_TOTP
            || now()->timestamp - (int) $pending['at'] > self::TOTP_PENDING_SECONDS) {
            $request->session()->forget('login.pending');
            throw ValidationException::withMessages([$field => $expired]);
        }

        $user = User::find($pending['user_id']);
        if (! $user || ! $user->is_active || DemoData::refuses($user) || ! $this->twoFactor->enabled($user)) {
            $request->session()->forget('login.pending');
            throw ValidationException::withMessages([$field => $expired]);
        }

        $lock = 'two-factor:'.$user->id;
        if (RateLimiter::tooManyAttempts($lock, self::TOTP_LOCK_ATTEMPTS)) {
            return response()->json([
                'message' => 'تلاش‌های نادرستِ زیادی شد. '.Jalali::digits((string) ceil(RateLimiter::availableIn($lock) / 60)).' دقیقه‌ی دیگر دوباره بکوشید.',
            ], 429);
        }

        $remaining = null;
        $ok = $field === 'recovery_code'
            ? ($remaining = $this->twoFactor->useRecoveryCode($user, (string) $data['recovery_code'])) !== null
            : $this->twoFactor->verifyCode($user, (string) $data['code']);

        if (! $ok) {
            RateLimiter::hit($lock, self::TOTP_LOCK_SECONDS);
            $this->recorder->failure($user, $request, LoginEvent::FAILED_OTP);

            $attempts = (int) ($pending['attempts'] ?? 0) + 1;
            if ($attempts >= self::TOTP_ATTEMPTS) {
                $request->session()->forget('login.pending');
                throw ValidationException::withMessages([$field => 'کدِ نادرستِ پیاپی. دوباره با رمز عبور وارد شوید.']);
            }
            $request->session()->put('login.pending', [...$pending, 'attempts' => $attempts]);

            throw ValidationException::withMessages([$field => $field === 'recovery_code'
                ? 'کدِ بازیابی نادرست است یا پیش‌تر به کار رفته.'
                : 'کدِ برنامه نادرست یا منقضی است.']);
        }

        RateLimiter::clear($lock);
        $request->session()->forget('login.pending');

        $response = $this->completeLogin($request, $user, remember: false, otp: $otp);

        // پس از ورود با کدِ بازیابی، رابط شمارِ باقی‌مانده را نشان می‌دهد تا صاحبِ حساب کدهای تازه بسازد
        return $remaining === null ? $response : $response->setData([...$response->getData(true), 'recoveryCodesRemaining' => $remaining]);
    }

    /**
     * Preview یک درِ ورود دارد: نام کاربریِ حساب‌های نمونه (۲۰۲۶-۱۰-۲۹، بندِ ۴). ورود، ثبت‌نام و بازیابیِ رمز با شماره
     * آن‌جا بسته‌اند — حساب‌های نمونه شماره ندارند و پیامکِ واقعی هم آن‌جا خاموش است.
     */
    private function refusePhoneFlowsInPreview(string $field): void
    {
        if (Preview::active()) {
            throw ValidationException::withMessages([$field => self::PREVIEW_USERNAME_ONLY]);
        }
    }

    /** آغازِ چالشِ TOTP ِ سوپر ادمین؛ هیچ پیامکی نمی‌رود */
    private function startTwoFactor(Request $request, User $user): JsonResponse
    {
        $request->session()->put('login.pending', [
            'user_id' => $user->id,
            'remember' => false,
            'at' => now()->timestamp,
            'factor' => self::FACTOR_TOTP,
            'attempts' => 0,
        ]);

        return response()->json([
            'twoFactorRequired' => true,
            'message' => 'کدِ شش‌رقمیِ برنامه‌ی Authenticator را وارد کنید.',
        ]);
    }

    /**
     * ارسال دوباره‌ی کد در مرحله‌ی دوم ورود.
     */
    public function loginResend(Request $request, OtpService $otp): JsonResponse
    {
        $pending = $request->session()->get('login.pending');
        // چالشِ TOTP پیامکی نمی‌فرستد
        if (! $pending || ($pending['factor'] ?? self::FACTOR_SMS) !== self::FACTOR_SMS) {
            throw ValidationException::withMessages([
                'code' => 'مهلت ورود تمام شده است. دوباره شماره را وارد کنید.',
            ]);
        }

        // همان قاعده‌ی گامِ اول: برای هر شماره همان پاسخ، پیامک فقط برای حسابِ واجدِ شرایطِ همین ورود
        $user = $pending['user_id'] === null ? null : User::find($pending['user_id']);
        $result = $otp->issue((string) $pending['phone'], deliver: $user !== null && $user->is_active);
        if (! $result['ok']) {
            throw ValidationException::withMessages(['code' => $result['error'] ?? 'ارسال مجدد کد ناموفق بود.']);
        }

        return response()->json([
            'message' => 'کد جدید ارسال شد.',
            'dev_code' => $result['dev_code'] ?? null,
        ]);
    }

    /**
     * ثبت‌نام ساکن جدید.
     *
     * کاربر با نقش «مالک» و در وضعیت غیرفعال ساخته می‌شود: تا وقتی مدیر او را
     * تایید و به مجتمع و واحدی متصل نکند، نباید به داده‌ای دسترسی داشته باشد.
     *
     * نام مجتمع دیگر پرسیده نمی‌شود، پس `complex_id` هنگام ثبت‌نام خالی است و
     * مدیر آن را هنگام تایید تعیین می‌کند.
     *
     * پذیرش قوانین هم اجباری است و لحظه‌اش ثبت می‌شود؛ پیش از این فقط یک تیکِ
     * سمت مرورگر بود که هیچ ردی به جا نمی‌گذاشت.
     */
    /**
     * ثبت‌نام — گام ۱: اعتبارسنجی و ارسالِ کدِ پیامکی.
     *
     * عمداً کاربر همین‌جا ساخته نمی‌شود؛ فقط داده‌ها در نشست می‌ماند و یک کد
     * فرستاده می‌شود. اگر کاربر کد را تکمیل نکند و منصرف شود، هیچ رکوردی در
     * فهرست اعضا به‌جا نمی‌ماند. حساب تنها پس از تاییدِ کد (گام ۲) ساخته می‌شود.
     */
    public function register(RegisterRequest $request, OtpService $otp): JsonResponse
    {
        $data = $request->validated();

        $this->refusePhoneFlowsInPreview('phone');

        $phone = Phone::normalize($data['phone']);

        if (! Phone::isValidMobile($phone)) {
            throw ValidationException::withMessages(['phone' => 'شماره تلفن همراه معتبر نیست.']);
        }

        /*
         * 🔴 «این شماره قبلاً ثبت شده» دیگر اینجا گفته نمی‌شود.
         *
         * این پاسخ به هر کسی — بی‌هیچ پیامکی — می‌گفت کدام شماره حساب دارد؛
         * بستنِ شمارش در «فراموشیِ رمز» بی‌این بی‌فایده بود. حالا کد برای هر
         * شماره‌ی معتبر فرستاده می‌شود و همین پیام **پس از تأییدِ کد** می‌آید
         * (`registerVerify`)، یعنی فقط به صاحبِ همان سیم‌کارت.
         */
        $result = $otp->request($phone);
        if (! $result['ok']) {
            throw ValidationException::withMessages(['phone' => $result['error'] ?? 'ارسال کد تایید ناموفق بود.']);
        }

        // داده‌ها موقتاً در نشست؛ حساب هنوز ساخته نشده.
        $request->session()->put('register.pending', [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone' => $phone,
            'at' => now()->timestamp,
        ]);

        return response()->json([
            'otpRequired' => true,
            'phone' => $phone,
            'message' => 'کد تایید به شماره '.$phone.' ارسال شد.',
            'dev_code' => $result['dev_code'] ?? null,
        ]);
    }

    /**
     * ثبت‌نام — گام ۲: تاییدِ کد و ساختِ حساب.
     *
     * تنها اینجا کاربر ساخته می‌شود، پس شماره‌ای که کدش تایید نشده هرگز عضو
     * نمی‌شود. حساب غیرفعال ساخته می‌شود تا مدیرِ کل/مدیرِ مجتمع نقش و دسترسی‌اش
     * را تعیین کند.
     */
    public function registerVerify(RegisterVerifyRequest $request, OtpService $otp, Membership $membership): JsonResponse
    {
        $request->validated();

        $pending = $request->session()->get('register.pending');
        if (! $pending) {
            throw ValidationException::withMessages([
                'code' => 'مهلت ثبت‌نام تمام شده است. دوباره از ابتدا ثبت‌نام کنید.',
            ]);
        }

        if (! $otp->verify($pending['phone'], $request->input('code'))) {
            throw ValidationException::withMessages(['code' => 'کد واردشده نادرست یا منقضی شده است.']);
        }

        // مبادا در این فاصله همان شماره از راهی دیگر ثبت شده باشد.
        if (User::withoutGlobalScopes()->where('phone', $pending['phone'])->exists()) {
            $request->session()->forget('register.pending');
            throw ValidationException::withMessages(['code' => 'این شماره قبلاً ثبت شده است. وارد شوید.']);
        }

        /*
         * حساب **فعال** ساخته می‌شود و به هیچ مجتمعی وصل نیست — «حالتِ اولیه»
         * (R21).
         *
         * پیش از این `is_active => false` بود و پیامش می‌گفت «پس از تایید وارد
         * شوید»، ولی هیچ مسیرِ تاییدی وجود نداشت: خودش نمی‌توانست وارد شود و
         * مدیرِ مجتمع هم نمی‌توانست اضافه‌اش کند (شماره یکتا بود و ۴۲۲ می‌گرفت).
         * یعنی هر کسی که خودش ثبت‌نام می‌کرد، برای همیشه گیر می‌کرد. سنجیده و
         * اثبات شد.
         *
         * ورودش خطری ندارد چون داشبوردش فقط‌خواندنی است و دامنه‌ی مستأجر برای
         * کاربرِ بدونِ مجتمع «هیچ‌چیز» است، نه «همه‌چیز».
         */
        $user = User::create([
            'complex_id' => null,
            // `name` را مدل از این دو می‌سازد
            'first_name' => $pending['first_name'],
            'last_name' => $pending['last_name'],
            'name' => PersianName::full($pending['first_name'], $pending['last_name']),
            'phone' => $pending['phone'],
            // کاربرانِ سایت رمز ندارند (۲۰۲۶-۱۰-۳۰)؛ ستون پُر می‌ماند با رمزِ تصادفیِ دورریختنی که هیچ مسیری نمی‌پذیرد
            'password' => Hash::make(Str::random(64)),
            'role' => UserRole::Owner,
            'is_active' => true,
            'terms_accepted_at' => now(),
        ]);

        $request->session()->forget('register.pending');
        $otp->clear($pending['phone']);

        $joined = $this->joinByInvitation($membership, $user, (string) $request->input('invitation_token', ''));

        /*
         * شماره همین حالا با کد ثابت شد و رمزی در کار نیست (۲۰۲۶-۱۰-۳۰): ثبت‌نام همان ورود است — گامِ «اکنون وارد شوید»
         * فقط یک پیامکِ دیگر می‌خواست.
         */
        $response = $this->completeLogin($request, $user, remember: false, otp: $otp);

        return $response->setStatusCode(201)->setData([...$response->getData(true),
            'message' => $joined === null ? 'ثبت‌نام کامل شد.' : 'ثبت‌نام کامل شد و عضوِ «'.$joined->name.'» شدید.',
            'joined' => $joined?->name,
        ]);
    }

    /**
     * ثبت‌نام از پیوندِ دعوتِ مستقیم ← عضویت، بی بازگشت به داشبوردِ اولیه (مرحلهٔ ۱۰ · فاز ۵؛ بندِ ۹۰).
     *
     *   ثبت‌نام ← برقراریِ عضویت ← داشبوردِ واحد
     *
     * ⚠️ مسیریابی با پرچمِ کلاینت امن نمی‌شود؛ همه‌چیز اینجا و در `Membership::accept` (زیرِ قفل) سنجیده
     * می‌شود: دعوتِ مستقیم، زنده، و بسته به **همین شماره**. کدِ پیامکیِ پذیرش لازم نیست چون همین درخواست
     * با کدِ پیامکیِ همان شماره تأیید شد — همان اثباتِ مالکیتِ سیم‌کارت، و نه چیزی کمتر: ثبت‌نام برخلافِ
     * پذیرش با `otp_disabled` هم بی کد نمی‌شود.
     *
     * دعوتِ نامعتبر، مصرف‌شده یا برای شماره‌ی دیگر ثبت‌نام را خراب نمی‌کند: حساب ساخته می‌شود و در حالتِ
     * اولیه می‌ماند (بندِ ۹۱)، و دعوتِ زنده‌ی همین شماره همان‌جا منتظر است.
     */
    private function joinByInvitation(Membership $membership, User $user, string $token): ?Complex
    {
        $invitation = ComplexInvitation::findLiveByToken($token);

        if ($invitation === null || $invitation->phone !== $user->phone) {
            return null;
        }

        try {
            $membership->accept($invitation, $user);
        } catch (DomainException) {
            // مثلاً هم‌زمان باطل شد یا واحد پُر شد: همان حالتِ اولیه، بی شکستنِ ثبت‌نام
            return null;
        }

        Audit::log('invitation.accepted', 'پذیرش دعوت به مجتمع هنگامِ ثبت‌نام', $invitation, [
            'complex_id' => $invitation->complex_id,
            'via' => 'registration',
        ]);

        return Complex::find($invitation->complex_id);
    }

    public function logout(Request $request): JsonResponse
    {
        // دستگاهِ مورداعتمادِ همین مرورگر باطل می‌شود تا خروج واقعاً خروج باشد؛
        // دفعه‌ی بعد باید دوباره با رمز و کد وارد شود.
        $this->devices->forget($request);
        // خروج یعنی همین حالا آفلاین، نه ۹۰ ثانیه‌ی دیگر («چند نکته»، بندِ ۳)
        if ($request->user() instanceof User) {
            Presence::forget($request->user());
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'خارج شدید.',
            'csrfToken' => csrf_token(),
        ]);
    }

    /**
     * تکمیل ورود: احراز، بازتولید نشست و در صورت لزوم ثبت دستگاهِ مورداعتماد.
     */
    private function completeLogin(Request $request, User $user, bool $remember, ?OtpService $otp = null): JsonResponse
    {
        // لایه‌ی آخر (۱۳/۱): هیچ مسیرِ ورودی — رمز، کد، دستگاهِ مورداعتماد — حسابِ آزمایشی را روی Production وارد نمی‌کند
        if (DemoData::refuses($user)) {
            throw ValidationException::withMessages(['phone' => self::WRONG_PHONE]);
        }

        Auth::login($user);

        // ورودِ هر مدیرِ سامانه با نامش در «فعالیتِ مدیران» (۲۰۲۶-۱۰-۲۹، بندِ ۳)
        if ($user->isSuperAdmin()) {
            Audit::log('admin.signed_in', 'ورود به پنلِ مدیریتِ سامانه', $user, [], null);
        }

        /*
         * ورودِ حسابِ چندمجتمعی به صفحه‌ی انتخاب است (۱۳/۳، `StartAuthenticatedSession`) — پاسخِ همین ورود هم همان را
         * می‌گوید، نه پیش‌فرضِ حساب؛ درخواست‌های بعدی را `SetCurrentComplex` به همین شکل می‌بیند.
         */
        if (($mode = SessionContext::mode($user)) !== null) {
            SessionContext::detach($user, $mode);
        }

        // جلوگیری از session fixation. توکن CSRF هم عوض می‌شود، پس تازه‌اش
        // باید در پاسخ برگردد وگرنه اولین درخواستِ نوشتنیِ بعدی ۴۱۹ می‌گیرد.
        $request->session()->regenerate();

        // کدهای این شماره پاک می‌شوند تا اگر کاربر بلافاصله بیرون بیاید و دوباره
        // وارد شود، «فاصله‌ی ارسال مجدد» جلوی کدِ تازه را نگیرد و پیامِ «کمی صبر
        // کنید» زیر شماره‌ی موبایل ظاهر نشود.
        if ($user->phone !== null) {
            $otp?->clear($user->phone);
        }

        // رمز همین حالا زده شد؛ پنجره‌ی «تأییدِ دوباره» از همین لحظه باز است
        $this->confirmation->confirm($request);

        if ($remember) {
            $this->devices->remember($user, $request);
        }

        return response()->json([
            'user' => $this->userPayload($user),
            'csrfToken' => csrf_token(),
        ]);
    }

    /** شکل ثابتی از کاربر که کلاینت روی آن حساب می‌کند. */
    private function userPayload(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $complex = $user->isSuperAdmin()
            ? (session('active_complex_id') ? Complex::find(session('active_complex_id')) : null)
            : $user->complex;

        return [
            'id' => $user->id,
            'name' => $user->name,
            // تصویرِ canonical ِ حساب برای منوی کاربر (مرحلهٔ ۱۰ · فاز ۷)
            'avatarUrl' => ProfilePhoto::url($user),
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            // حسابِ پیش از مرحلهٔ ۹: نامش حفظ شده و فقط درخواستِ تکمیل می‌بیند
            'needsName' => $user->needsNameCompletion(),
            'phone' => $user->phone,
            'role' => $user->role->value,
            'roleLabel' => $user->role->label(),
            'isAdmin' => $user->role->isAdmin(),
            'isSuperAdmin' => $user->isSuperAdmin(),
            // تغییرِ رمز و تأییدِ دوباره با رمز فقط برای حسابِ رمزدار (۲۰۲۶-۱۰-۳۰)
            'usesPassword' => $user->usesPassword(),
            // کلیدِ «شاملِ دادهٔ آزمایشی» فقط جایی که سرور هم آن را می‌پذیرد (`DemoData::included`)؛ روی Production نه
            'demoData' => $user->isSuperAdmin() && DemoData::allowedHere(),
            // نشستِ سوپر ادمینِ Production بی TOTP: رابط فقط راه‌اندازیِ عاملِ دوم را پیش می‌گذارد (`EnsureAdminTwoFactor`)
            'twoFactorSetupRequired' => $this->twoFactor->setupRequired($user),
            // دکمه‌ی دستیار (۱۳/۸): همان دروازه‌ی API — اولیه هرگز، مجتمع فقط با پلنِ پیشرفته (`AssistantAccess`)
            'assistant' => app(AssistantAccess::class)->allows($user),
            'complex' => $complex ? [
                'id' => $complex->id,
                'name' => $complex->name,
                // نوع (R67): فرانت واژگانِ «ساکن/کسب‌وکار» را از آن می‌گیرد
                'type' => $complex->type->value,
                'typeLabel' => $complex->type->label(),
                'occupantLabel' => $complex->type->occupantLabel(),
                'occupantPluralLabel' => $complex->type->occupantPluralLabel(),
                'unitLabel' => $complex->type->unitLabel(),
            ] : null,

            /*
             * حالتِ حساب برای شاخه‌زدنِ رابط (R21). از واقعیت مشتق می‌شود
             * (وصل بودن به مجتمع)، نه از یک نقشِ جدا که می‌تواند با واقعیت
             * ناهماهنگ شود.
             */
            'accountState' => AccountState::of($user)->value,
            /*
             * کدام داشبورد (مرحلهٔ ۱۲ · فاز ۱؛ بندِ ۳۴): سرور می‌گوید، از حالتِ حساب و نقشِ عضویتِ **زمینه‌ی همین
             * نشست** — نه پرچمی روی رابط. «چند عضویت» همان `memberships` است.
             */
            'dashboard' => match (true) {
                AccountState::of($user) === AccountState::Platform => 'platform',
                // حسابِ چندمجتمعی که هنوز مجتمعی برنگزیده (۱۳/۳): فقط صفحه‌ی انتخاب، نه داشبوردِ اولیه
                SessionContext::detachedAs() === SessionContext::CHOOSING => 'select',
                AccountState::of($user) === AccountState::Initial => 'initial',
                $user->role === UserRole::ComplexAdmin => 'manager',
                default => 'unit',
            },
            'canWrite' => AccountState::of($user)->canWrite(),
            // از ۱۰/۴ عضو هم دعوت می‌گیرد؛ ادمینِ کل نه
            'pendingInvitations' => $user->isSuperAdmin()
                ? 0
                : ComplexInvitation::invites()->live()->where('phone', $user->phone)->count(),
            // نشانِ آدمک (۱۳/۵): اعلان‌های دعوتِ خوانده‌نشده با دعوتِ زنده — حسابی، پس در هر زمینه همان عدد
            'unreadInvitations' => Notifications::unreadInvitationCount($user),

            /*
             * عضویت‌های فعالِ خودِ حساب برای تعویضِ مجتمع (مرحلهٔ ۱۰ · فاز ۴). یک کوئری؛
             * ادمینِ کل عضویت ندارد و مجتمعِ کاری‌اش را از پنلِ سیستم انتخاب می‌کند.
             */
            'memberships' => $user->isSuperAdmin() ? [] : DB::table('complex_memberships as cm')
                ->join('complexes', 'complexes.id', '=', 'cm.complex_id')
                ->where('cm.user_id', $user->id)
                // معلق‌ها هم، با وضعیت (۱۰/۶): کاربر می‌بیند کجا معلق است؛ تعویض به آن‌جا را سرور رد می‌کند
                ->whereIn('cm.status', ComplexMembership::LIVE)
                ->orderBy('cm.started_at')
                ->get(['cm.complex_id', 'cm.role', 'cm.status', 'complexes.name', 'complexes.type'])
                ->map(fn (object $m) => [
                    'complexId' => (int) $m->complex_id,
                    'complexName' => $m->name,
                    // کارتِ صفحه‌ی انتخاب (۱۳/۳): «مسکونی» یا «تجاری» کنارِ نام
                    'complexTypeLabel' => ComplexType::from($m->type)->label(),
                    'role' => $m->role,
                    'roleLabel' => UserRole::from($m->role)->label(),
                    'status' => $m->status,
                    'current' => (int) $m->complex_id === $user->complex_id,
                ])->values()->all(),
        ];
    }
}
