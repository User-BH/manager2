<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * احراز هویت برای اپلیکیشن React.
 *
 * منطق دقیقاً همان LoginController وب است، فقط به‌جای redirect، JSON
 * برمی‌گرداند. نشست و کوکی همان نشست لاراول است، پس کاربری که اینجا وارد
 * می‌شود به صفحه‌های Blade زیر /legacy هم دسترسی دارد.
 */
class AuthController extends Controller
{
    /** کاربر واردشده‌ی فعلی، یا null برای مهمان. */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    /** روش ۱: شماره تلفن + رمز عبور. */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [], ['phone' => 'شماره تلفن', 'password' => 'رمز عبور']);

        $phone = Phone::normalize($request->input('phone'));

        if (! Auth::attempt(
            ['phone' => $phone, 'password' => $request->input('password')],
            $request->boolean('remember'),
        )) {
            throw ValidationException::withMessages([
                'phone' => 'شماره تلفن یا رمز عبور نادرست است.',
            ]);
        }

        if (! Auth::user()->is_active) {
            Auth::logout();
            throw ValidationException::withMessages([
                'phone' => 'حساب کاربری شما غیرفعال است.',
            ]);
        }

        // جلوگیری از session fixation: شناسه‌ی نشست پس از ورود عوض می‌شود.
        $request->session()->regenerate();

        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    /** روش ۲ (گام ۱): درخواست کد پیامکی. */
    public function requestOtp(Request $request, OtpService $otp): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string']], [], ['phone' => 'شماره تلفن']);

        $phone = Phone::normalize($request->input('phone'));

        if (! Phone::isValidMobile($phone)) {
            throw ValidationException::withMessages(['phone' => 'شماره تلفن همراه معتبر نیست.']);
        }

        $user = User::where('phone', $phone)->first();
        if (! $user || ! $user->is_active) {
            throw ValidationException::withMessages([
                'phone' => 'کاربری با این شماره فعال نیست. با مدیر ساختمان تماس بگیرید.',
            ]);
        }

        $result = $otp->request($phone);
        if (! $result['ok']) {
            throw ValidationException::withMessages([
                'phone' => $result['error'] ?? 'ارسال کد ناموفق بود.',
            ]);
        }

        $request->session()->put('otp_phone', $phone);

        return response()->json([
            'message' => 'کد ورود به شماره '.$phone.' ارسال شد.',
            'phone' => $phone,
            // در حالت تست (درایور log) کد برگردانده می‌شود تا بدون پیامک واقعی
            // هم بتوان ورود را آزمود. در حالت واقعی این مقدار null است.
            'dev_code' => $result['dev_code'] ?? null,
        ]);
    }

    /** روش ۲ (گام ۲): تایید کد پیامکی. */
    public function verifyOtp(Request $request, OtpService $otp): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']], [], ['code' => 'کد']);

        $phone = $request->session()->get('otp_phone');
        if (! $phone) {
            throw ValidationException::withMessages([
                'code' => 'درخواست کد منقضی شده است. دوباره شماره را وارد کنید.',
            ]);
        }

        if (! $otp->verify($phone, $request->input('code'))) {
            throw ValidationException::withMessages([
                'code' => 'کد واردشده نادرست یا منقضی شده است.',
            ]);
        }

        $user = User::where('phone', $phone)->first();
        if (! $user || ! $user->is_active) {
            throw ValidationException::withMessages(['code' => 'حساب کاربری در دسترس نیست.']);
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        $request->session()->forget('otp_phone');

        return response()->json(['user' => $this->userPayload($user)]);
    }

    /**
     * ثبت‌نام ساکن جدید.
     *
     * کاربر با نقش «مالک» و در وضعیت غیرفعال ساخته می‌شود: تا وقتی مدیر
     * مجتمع او را تایید و به واحدی متصل نکند، نباید به دادهٔ مجتمع دسترسی
     * داشته باشد. کد مجتمع اجباری است چون هر کاربر باید به یک مجتمع تعلق
     * داشته باشد وگرنه ComplexScope هیچ داده‌ای به او نشان نمی‌دهد.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string'],
            'complex_name' => ['required', 'string', 'exists:complexes,name'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [], [
            'name' => 'نام',
            'phone' => 'شماره تلفن',
            'complex_name' => 'نام مجتمع',
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

        $complex = \App\Models\Complex::where('name', $data['complex_name'])->firstOrFail();

        User::create([
            'complex_id' => $complex->id,
            'name' => $data['name'],
            'phone' => $phone,
            'password' => Hash::make($data['password']),
            'role' => UserRole::Owner,
            'is_active' => false,
        ]);

        return response()->json([
            'message' => 'ثبت‌نام انجام شد. پس از تایید مدیر مجتمع می‌توانید وارد شوید.',
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'خارج شدید.']);
    }

    /** شکل ثابتی از کاربر که کلاینت روی آن حساب می‌کند. */
    private function userPayload(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $complex = $user->isSuperAdmin()
            ? (session('active_complex_id') ? \App\Models\Complex::find(session('active_complex_id')) : null)
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
