<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Complex;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Auth\TrustedDeviceService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
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
    public function __construct(protected TrustedDeviceService $devices) {}

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
     * گام ۱ ورود: بررسی رمز عبور.
     *
     * رمز درست، کاربر را بلافاصله وارد نمی‌کند: شناسه‌اش در نشست به‌عنوان
     * «در انتظار مرحله‌ی دوم» نگه داشته می‌شود و یک کد پیامکی فرستاده می‌شود.
     * تنها میان‌بر، دستگاهِ مورداعتماد است.
     */
    public function login(Request $request, OtpService $otp): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [], ['phone' => 'شماره تلفن', 'password' => 'رمز عبور']);

        $phone = Phone::normalize($request->input('phone'));
        $user = User::where('phone', $phone)->first();

        // پیامِ یکسان برای «کاربر نیست» و «رمز غلط» تا نشود شماره‌ها را شمارش کرد.
        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'phone' => 'شماره تلفن یا رمز عبور نادرست است.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'phone' => 'حساب کاربری شما هنوز فعال نشده است. پس از تایید مدیر مجتمع می‌توانید وارد شوید.',
            ]);
        }

        // دستگاهِ مورداعتماد: رد کردن کاملِ مرحله‌ی دوم.
        if ($this->devices->isTrustedFor($user, $request)) {
            return $this->completeLogin($request, $user, remember: false);
        }

        // شروع مرحله‌ی دوم: کد پیامکی.
        $result = $otp->request($phone);
        if (! $result['ok']) {
            throw ValidationException::withMessages([
                'phone' => $result['error'] ?? 'ارسال کد ورود ناموفق بود.',
            ]);
        }

        $request->session()->put('login.pending', [
            'user_id' => $user->id,
            'remember' => $request->boolean('remember'),
            'at' => now()->timestamp,
        ]);

        return response()->json([
            'otpRequired' => true,
            'phone' => $phone,
            'message' => 'کد ورود به شماره '.$phone.' ارسال شد.',
            // در حالت تست (درایور log) کد برگردانده می‌شود تا بدون پیامک واقعی هم
            // بتوان ورود را آزمود؛ در حالت واقعی null است.
            'dev_code' => $result['dev_code'] ?? null,
        ]);
    }

    /**
     * گام ۲ ورود: تایید کد پیامکی و ورود نهایی.
     */
    public function loginVerify(Request $request, OtpService $otp): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ], [], ['code' => 'کد']);

        $pending = $request->session()->get('login.pending');

        if (! $pending) {
            throw ValidationException::withMessages([
                'code' => 'مهلت ورود تمام شده است. دوباره با رمز عبور وارد شوید.',
            ]);
        }

        $user = User::find($pending['user_id']);
        if (! $user || ! $user->is_active) {
            $request->session()->forget('login.pending');
            throw ValidationException::withMessages(['code' => 'حساب کاربری در دسترس نیست.']);
        }

        if (! $otp->verify($user->phone, $request->input('code'))) {
            throw ValidationException::withMessages([
                'code' => 'کد واردشده نادرست یا منقضی شده است.',
            ]);
        }

        $request->session()->forget('login.pending');

        return $this->completeLogin($request, $user, remember: (bool) ($pending['remember'] ?? false));
    }

    /**
     * ارسال دوباره‌ی کد در مرحله‌ی دوم ورود.
     */
    public function loginResend(Request $request, OtpService $otp): JsonResponse
    {
        $pending = $request->session()->get('login.pending');
        if (! $pending) {
            throw ValidationException::withMessages([
                'code' => 'مهلت ورود تمام شده است. دوباره با رمز عبور وارد شوید.',
            ]);
        }

        $user = User::find($pending['user_id']);
        abort_unless($user, 422);

        $result = $otp->request($user->phone);
        if (! $result['ok']) {
            throw ValidationException::withMessages(['code' => $result['error'] ?? 'ارسال مجدد کد ناموفق بود.']);
        }

        return response()->json([
            'message' => 'کد جدید ارسال شد.',
            'dev_code' => $result['dev_code'] ?? null,
        ]);
    }

    /**
     * فراموشی رمز — گام ۱: فرستادن کد به شماره‌ی کاربر.
     *
     * فقط شماره گرفته می‌شود؛ اثبات هویت با کد پیامکی است. محدودیت نرخِ
     * `otp-request` جلوی سوءاستفاده و مصرف بی‌رویه‌ی اعتبار پیامک را می‌گیرد.
     */
    public function forgotPassword(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
        ], [], ['phone' => 'شماره موبایل']);

        $phone = Phone::normalize($data['phone']);
        $user = User::where('phone', $phone)->first();

        /*
         * پیام یکسان برای «شماره ثبت نشده» و «حساب غیرفعال»، تا نشود با
         * آزمون‌وخطا فهمید کدام شماره در سامانه حساب دارد.
         *
         * اثباتِ هویت اینجا خودِ کدِ پیامکی است: فقط کسی که به آن سیم‌کارت
         * دسترسی دارد می‌تواند ادامه دهد.
         */
        if (! $user || ! $user->is_active) {
            throw ValidationException::withMessages([
                'phone' => 'حساب فعالی با این شماره پیدا نشد.',
            ]);
        }

        $result = $otp->request($phone);
        if (! $result['ok']) {
            throw ValidationException::withMessages(['phone' => $result['error'] ?? 'ارسال کد ناموفق بود.']);
        }

        $request->session()->put('reset.pending', [
            'user_id' => $user->id,
            'verified' => false,
            'at' => now()->timestamp,
        ]);

        return response()->json([
            'message' => 'کد بازیابی به شماره '.$phone.' ارسال شد.',
            'dev_code' => $result['dev_code'] ?? null,
        ]);
    }

    /**
     * فراموشی رمز — گام ۲: تایید کد پیامکی.
     */
    public function forgotVerify(Request $request, OtpService $otp): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']], [], ['code' => 'کد']);

        $pending = $request->session()->get('reset.pending');
        if (! $pending) {
            throw ValidationException::withMessages(['code' => 'مهلت بازیابی تمام شده است. از ابتدا شروع کنید.']);
        }

        $user = User::find($pending['user_id']);
        abort_unless($user, 422);

        if (! $otp->verify($user->phone, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => 'کد واردشده نادرست یا منقضی شده است.']);
        }

        // فقط پرچمِ «تاییدشده» را می‌زنیم؛ خودِ تغییر رمز گام بعد است.
        $request->session()->put('reset.pending', [...$pending, 'verified' => true]);

        return response()->json(['message' => 'کد تایید شد. اکنون رمز تازه را وارد کنید.']);
    }

    /**
     * فراموشی رمز — گام ۳: ثبت رمز تازه.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [], ['password' => 'رمز عبور']);

        $pending = $request->session()->get('reset.pending');
        if (! $pending || ! ($pending['verified'] ?? false)) {
            throw ValidationException::withMessages(['password' => 'ابتدا کد پیامکی را تایید کنید.']);
        }

        $user = User::find($pending['user_id']);
        abort_unless($user, 422);

        $user->update(['password' => Hash::make($request->input('password'))]);

        // همه‌ی دستگاه‌های مورداعتماد باطل می‌شوند: تغییر رمز یعنی «دیگر به هیچ
        // دستگاه قبلی اعتماد نکن»، مبادا رمز به‌خاطرِ نشت لو رفته باشد.
        $user->trustedDevices()->delete();

        $request->session()->forget('reset.pending');

        /*
         * ورودِ خودکار پس از بازیابی.
         *
         * کاربر همین حالا با کد پیامکی ثابت کرده که صاحب این شماره است و رمز
         * تازه را هم خودش گذاشته؛ فرستادنش به فرم ورود و یک پیامکِ دومرحله‌ایِ
         * دیگر، بدون افزودن امنیت فقط اصطکاک است.
         */
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'message' => 'رمز عبور با موفقیت تغییر کرد.',
            'user' => $this->userPayload($user),
            'csrfToken' => csrf_token(),
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
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'accept_terms' => ['required', 'accepted'],
        ], [
            'accept_terms.required' => 'برای ادامه باید قوانین و مقررات را بپذیرید.',
            'accept_terms.accepted' => 'برای ادامه باید قوانین و مقررات را بپذیرید.',
        ], [
            'name' => 'نام',
            'phone' => 'شماره تلفن',
            'password' => 'رمز عبور',
        ]);

        $phone = Phone::normalize($data['phone']);

        if (! Phone::isValidMobile($phone)) {
            throw ValidationException::withMessages(['phone' => 'شماره تلفن همراه معتبر نیست.']);
        }

        if (User::withoutGlobalScopes()->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages([
                'phone' => 'این شماره قبلاً ثبت شده است. از همین صفحه وارد شوید.',
            ]);
        }

        User::create([
            'complex_id' => null,
            'name' => $data['name'],
            'phone' => $phone,
            'password' => Hash::make($data['password']),
            'role' => UserRole::Owner,
            'is_active' => false,
            'terms_accepted_at' => now(),
        ]);

        return response()->json([
            'message' => 'ثبت‌نام انجام شد. پس از تایید مدیر مجتمع می‌توانید وارد شوید.',
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        // دستگاهِ مورداعتمادِ همین مرورگر باطل می‌شود تا خروج واقعاً خروج باشد؛
        // دفعه‌ی بعد باید دوباره با رمز و کد وارد شود.
        $this->devices->forget($request);

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
    private function completeLogin(Request $request, User $user, bool $remember): JsonResponse
    {
        Auth::login($user);

        // جلوگیری از session fixation. توکن CSRF هم عوض می‌شود، پس تازه‌اش
        // باید در پاسخ برگردد وگرنه اولین درخواستِ نوشتنیِ بعدی ۴۱۹ می‌گیرد.
        $request->session()->regenerate();

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
            'phone' => $user->phone,
            'role' => $user->role->value,
            'roleLabel' => $user->role->label(),
            'isAdmin' => $user->role->isAdmin(),
            'isSuperAdmin' => $user->isSuperAdmin(),
            'complex' => $complex ? ['id' => $complex->id, 'name' => $complex->name] : null,
        ];
    }
}
