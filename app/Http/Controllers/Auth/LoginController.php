<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function show(Request $request)
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        // When an OTP has been requested, show the code-entry step.
        $otpPhone = $request->session()->get('otp_phone');

        return view('auth.login', ['otpPhone' => $otpPhone]);
    }

    /** Method 1: phone + password. */
    public function password(Request $request)
    {
        $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required'],
        ], [], ['phone' => 'شماره تلفن', 'password' => 'رمز عبور']);

        $phone = Phone::normalize($request->input('phone'));

        if (! Auth::attempt(['phone' => $phone, 'password' => $request->input('password')], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['phone' => 'شماره تلفن یا رمز عبور نادرست است.']);
        }

        if (! Auth::user()->is_active) {
            Auth::logout();
            throw ValidationException::withMessages(['phone' => 'حساب کاربری شما غیرفعال است.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /** Method 2 (step 1): request an SMS code. */
    public function requestOtp(Request $request, OtpService $otp)
    {
        $request->validate(['phone' => ['required', 'string']], [], ['phone' => 'شماره تلفن']);

        $phone = Phone::normalize($request->input('phone'));

        if (! Phone::isValidMobile($phone)) {
            throw ValidationException::withMessages(['phone' => 'شماره تلفن همراه معتبر نیست.']);
        }

        $user = User::where('phone', $phone)->first();
        if (! $user || ! $user->is_active) {
            throw ValidationException::withMessages(['phone' => 'کاربری با این شماره فعال نیست. با مدیر ساختمان تماس بگیرید.']);
        }

        $result = $otp->request($phone);
        if (! $result['ok']) {
            throw ValidationException::withMessages(['phone' => $result['error'] ?? 'ارسال کد ناموفق بود.']);
        }

        $request->session()->put('otp_phone', $phone);

        // In test (log) mode, surface the code so it can be entered without a real SMS.
        if ($result['dev_code']) {
            $request->session()->flash('dev_code', $result['dev_code']);
        }

        return redirect()->route('login')->with('success', 'کد ورود به شماره '.$phone.' ارسال شد.');
    }

    /** Method 2 (step 2): verify the SMS code. */
    public function verifyOtp(Request $request, OtpService $otp)
    {
        $request->validate(['code' => ['required', 'string']], [], ['code' => 'کد']);

        $phone = $request->session()->get('otp_phone');
        if (! $phone) {
            return redirect()->route('login');
        }

        if (! $otp->verify($phone, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => 'کد واردشده نادرست یا منقضی شده است.']);
        }

        $user = User::where('phone', $phone)->first();
        if (! $user || ! $user->is_active) {
            throw ValidationException::withMessages(['code' => 'حساب کاربری در دسترس نیست.']);
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        $request->session()->forget('otp_phone');

        return redirect()->intended(route('dashboard'));
    }

    public function cancelOtp(Request $request)
    {
        $request->session()->forget('otp_phone');

        return redirect()->route('login');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
