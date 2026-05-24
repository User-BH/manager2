<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ورود — {{ config('app.name') }}</title>
    <script>
        (function () {
            const t = localStorage.getItem('theme') || 'system';
            const dark = t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 antialiased dark:bg-slate-900 dark:text-slate-100">
<div class="flex min-h-screen items-center justify-center p-4">
    <div class="w-full max-w-md">
        <div class="mb-6 text-center">
            <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-sky-600 text-2xl font-bold text-white">س</div>
            <h1 class="text-xl font-bold">سامانه مدیریت ساختمان</h1>
            <p class="mt-1 text-sm text-slate-400">ورود با شماره تلفن همراه</p>
        </div>

        @if (session('success'))
            <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">{{ session('success') }}</div>
        @endif
        @if (session('dev_code'))
            <div class="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-center text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                حالت تست: کد ورود شما <span class="font-bold tabular-nums" dir="ltr">{{ session('dev_code') }}</span> است.
            </div>
        @endif

        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-800">
            @if ($otpPhone)
                {{-- Step 2: verify the SMS code --}}
                <form method="POST" action="{{ route('login.otp.verify') }}" class="space-y-4">
                    @csrf
                    <p class="text-sm text-slate-500 dark:text-slate-400">کد پیامک‌شده به شماره <span class="font-semibold" dir="ltr">{{ $otpPhone }}</span> را وارد کنید.</p>
                    <x-input name="code" label="کد تایید" required dir="ltr" inputmode="numeric" autofocus class="text-center tracking-widest" />
                    @error('code')<span class="block text-xs text-rose-500">{{ $message }}</span>@enderror
                    <x-button class="w-full">ورود</x-button>
                    <div class="flex items-center justify-between text-xs">
                        <form method="POST" action="{{ route('login.otp.request') }}">
                            @csrf
                            <input type="hidden" name="phone" value="{{ $otpPhone }}">
                            <button class="text-sky-600 hover:underline dark:text-sky-400">ارسال مجدد کد</button>
                        </form>
                        <form method="POST" action="{{ route('login.otp.cancel') }}">
                            @csrf
                            <button class="text-slate-400 hover:underline">تغییر شماره</button>
                        </form>
                    </div>
                </form>
            @else
                {{-- Step 1: choose method (vanilla JS tabs — no Alpine on guest layout) --}}
                <div>
                    <div class="mb-5 grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1 text-sm dark:bg-slate-700/50">
                        <button type="button" id="tab-password" onclick="loginMode('password')"
                            class="rounded-lg bg-white py-2 font-medium shadow-sm transition dark:bg-slate-800">ورود با رمز عبور</button>
                        <button type="button" id="tab-otp" onclick="loginMode('otp')"
                            class="rounded-lg py-2 font-medium text-slate-500 transition">ورود با کد پیامک</button>
                    </div>

                    {{-- Password method --}}
                    <form method="POST" action="{{ route('login.password') }}" class="space-y-4" id="form-password">
                        @csrf
                        <x-input name="phone" label="شماره تلفن همراه" required dir="ltr" placeholder="09xxxxxxxxx" />
                        <x-input name="password" type="password" label="رمز عبور" required dir="ltr" />
                        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <input type="checkbox" name="remember" class="rounded border-slate-300 text-sky-600 focus:ring-sky-500">
                            مرا به خاطر بسپار
                        </label>
                        <x-button class="w-full">ورود</x-button>
                    </form>

                    {{-- OTP method --}}
                    <form method="POST" action="{{ route('login.otp.request') }}" class="hidden space-y-4" id="form-otp">
                        @csrf
                        <x-input name="phone" label="شماره تلفن همراه" required dir="ltr" placeholder="09xxxxxxxxx" />
                        <p class="text-xs text-slate-400">یک کد یک‌بارمصرف به این شماره پیامک می‌شود.</p>
                        <x-button variant="success" class="w-full">ارسال کد ورود</x-button>
                    </form>

                    @if ($errors->any())
                        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-800 dark:bg-rose-900/30 dark:text-rose-300">
                            {{ $errors->first() }}
                        </div>
                    @endif
                </div>
                <script>
                    function loginMode(mode) {
                        const isPwd = mode === 'password';
                        document.getElementById('form-password').classList.toggle('hidden', !isPwd);
                        document.getElementById('form-otp').classList.toggle('hidden', isPwd);
                        document.getElementById('tab-password').className = 'rounded-lg py-2 font-medium transition ' + (isPwd ? 'bg-white shadow-sm dark:bg-slate-800' : 'text-slate-500');
                        document.getElementById('tab-otp').className = 'rounded-lg py-2 font-medium transition ' + (!isPwd ? 'bg-white shadow-sm dark:bg-slate-800' : 'text-slate-500');
                    }
                </script>
            @endif
        </div>

        <div class="mt-6 rounded-2xl border border-slate-200 bg-white/60 p-4 text-xs text-slate-500 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-400">
            <p class="mb-2 font-semibold text-slate-600 dark:text-slate-300">حساب‌های نمونه (رمز همه: <span dir="ltr">password</span>):</p>
            <ul class="space-y-1" dir="ltr">
                <li>09120000000 — مدیر مجتمع</li>
                <li>09120000001 — ادمین کل سیستم</li>
                <li>09121111101 — مالک واحد ۱</li>
                <li>09122222202 — مستاجر واحد ۲</li>
            </ul>
        </div>
    </div>
</div>
<style>[x-cloak]{display:none!important}</style>
</body>
</html>
