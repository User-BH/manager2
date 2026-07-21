@extends('layouts.guest')

@section('title', 'ورود به پنل')

@section('content')
<div class="flex min-h-screen">

    {{-- ستون فرم (در RTL سمت راست) --}}
    <div class="flex w-full flex-col px-5 py-6 sm:px-10 lg:w-1/2 lg:px-16">
        <div class="flex items-center justify-between">
            <a href="{{ route('home') }}" class="flex items-center gap-1.5 text-sm font-medium text-muted transition-colors hover:text-ink">
                <x-icon name="arrow-right" :size="16" />
                بازگشت به صفحه اصلی
            </a>
            <x-theme-toggle />
        </div>

        <div class="flex flex-1 flex-col items-center justify-center py-10">
            <div class="w-full max-w-sm">
                <div class="mb-7 text-center">
                    <x-logo-mark :size="52" class="mx-auto mb-4" />
                    <h1 class="text-xl font-extrabold text-ink">ورود به پنل مدیریت</h1>
                    <p class="mt-2 text-[13px] text-faint">
                        @if ($otpPhone)
                            کد یک‌بارمصرف پیامک‌شده را وارد کنید
                        @else
                            با شماره موبایل خود وارد شوید
                        @endif
                    </p>
                </div>

                @if (session('success'))
                    <x-alert type="success" class="mb-4">{{ session('success') }}</x-alert>
                @endif

                @if (session('dev_code'))
                    <x-alert type="warning" class="mb-4">
                        حالت تست: کد ورود شما <span class="font-bold tabular-nums" dir="ltr">{{ session('dev_code') }}</span> است.
                    </x-alert>
                @endif

                @if ($errors->any())
                    <x-alert type="error" class="mb-4">{{ $errors->first() }}</x-alert>
                @endif

                @if ($otpPhone)
                    {{-- گام ۲: تایید کد پیامکی --}}
                    <div class="rounded-2xl border border-line bg-surface p-6 shadow-ambient">
                        <p class="mb-4 text-[13px] leading-6 text-muted">
                            کد پیامک‌شده به شماره
                            <span class="font-semibold text-ink" dir="ltr">{{ $otpPhone }}</span>
                            را وارد کنید.
                        </p>

                        <form method="POST" action="{{ route('login.otp.verify') }}" class="space-y-4">
                            @csrf
                            <x-input name="code" label="کد تایید" required dir="ltr" inputmode="numeric"
                                     autofocus autocomplete="one-time-code"
                                     class="text-center text-lg tracking-[0.4em] tabular-nums" />
                            <x-button class="w-full" size="lg" icon="check">ورود</x-button>
                        </form>

                        <div class="mt-4 flex items-center justify-between border-t border-line pt-4 text-xs">
                            <form method="POST" action="{{ route('login.otp.request') }}">
                                @csrf
                                <input type="hidden" name="phone" value="{{ $otpPhone }}">
                                <button class="font-medium text-brand-500 hover:underline dark:text-brand-300">ارسال مجدد کد</button>
                            </form>
                            <form method="POST" action="{{ route('login.otp.cancel') }}">
                                @csrf
                                <button class="text-faint hover:underline">تغییر شماره</button>
                            </form>
                        </div>
                    </div>
                @else
                    {{-- گام ۱: انتخاب روش ورود (JS ساده — روی لایهٔ مهمان Alpine بارگذاری نمی‌شود) --}}
                    <div class="grid grid-cols-2 gap-1 rounded-2xl border border-line bg-sunken p-1">
                        <button type="button" id="tab-password" onclick="loginMode('password')"
                                class="rounded-xl py-2.5 text-[13.5px] font-semibold transition-colors duration-200">
                            رمز عبور
                        </button>
                        <button type="button" id="tab-otp" onclick="loginMode('otp')"
                                class="rounded-xl py-2.5 text-[13.5px] font-semibold transition-colors duration-200">
                            کد پیامکی
                        </button>
                    </div>

                    <div class="mt-6 rounded-2xl border border-line bg-surface p-6 shadow-ambient">
                        {{-- روش رمز عبور --}}
                        <form method="POST" action="{{ route('login.password') }}" class="space-y-4" id="form-password">
                            @csrf
                            <x-input name="phone" label="شماره تلفن همراه" required dir="ltr"
                                     placeholder="09xxxxxxxxx" inputmode="tel" icon="sms" autocomplete="username" />

                            <x-input name="password" type="password" label="رمز عبور" required dir="ltr"
                                     icon="key" autocomplete="current-password">
                                <x-slot:trailing>
                                    <button type="button" onclick="togglePassword(this)" data-visible="false"
                                            class="flex items-center text-faint transition-colors hover:text-ink"
                                            tabindex="-1" aria-label="نمایش یا مخفی کردن رمز">
                                        <span data-eye><x-icon name="eye" :size="17" /></span>
                                        <span data-eye-off class="hidden"><x-icon name="eye-off" :size="17" /></span>
                                    </button>
                                </x-slot:trailing>
                            </x-input>

                            <label class="flex items-center gap-2 text-sm text-muted">
                                <input type="checkbox" name="remember"
                                       class="h-4 w-4 rounded border-line-strong text-brand-500 focus-ring">
                                مرا به خاطر بسپار
                            </label>

                            <x-button class="w-full" size="lg">ورود</x-button>
                        </form>

                        {{-- روش کد پیامکی --}}
                        <form method="POST" action="{{ route('login.otp.request') }}" class="hidden space-y-4" id="form-otp">
                            @csrf
                            <x-input name="phone" label="شماره تلفن همراه" required dir="ltr"
                                     placeholder="09xxxxxxxxx" inputmode="tel" icon="sms" autocomplete="username"
                                     hint="یک کد یک‌بارمصرف به این شماره پیامک می‌شود." />
                            <x-button variant="success" class="w-full" size="lg" icon="send">ارسال کد ورود</x-button>
                        </form>
                    </div>
                @endif

                {{-- حساب‌های نمونه --}}
                <details class="mt-6 rounded-2xl border border-line bg-surface/60 px-4 py-3 text-xs text-muted">
                    <summary class="cursor-pointer font-semibold text-ink marker:text-brand-500">
                        حساب‌های نمونه برای تست
                    </summary>
                    <p class="mt-2 text-faint">رمز عبور همه: <span dir="ltr" class="font-medium">password</span></p>
                    <ul class="mt-2 space-y-1 tabular-nums" dir="ltr">
                        <li>09120000000 — <span dir="rtl">مدیر مجتمع</span></li>
                        <li>09120000001 — <span dir="rtl">ادمین کل سیستم</span></li>
                        <li>09121111101 — <span dir="rtl">مالک واحد ۱</span></li>
                        <li>09122222202 — <span dir="rtl">مستاجر واحد ۲</span></li>
                    </ul>
                </details>
            </div>
        </div>
    </div>

    {{-- ستون تصویری (فقط دسکتاپ، در RTL سمت چپ) --}}
    <div class="relative hidden w-1/2 overflow-hidden lg:block">
        <x-skyline :variant="1" class="absolute inset-0" />
        <div class="absolute inset-0 bg-gradient-to-bl from-brand-800/85 to-brand-500/55"></div>

        <div class="relative flex h-full flex-col justify-between p-10 text-white">
            <div class="flex items-center gap-2.5">
                <x-logo-mark :size="34" monochrome />
                <span class="text-sm font-bold">{{ config('brand.name') }}</span>
            </div>

            <div>
                <h2 class="max-w-md text-2xl font-extrabold leading-relaxed">
                    با {{ config('brand.name') }}، مدیریت مجتمع را به ساده‌ترین شکل ممکن تجربه کنید
                </h2>
                <div class="mt-5 flex items-center gap-2 text-sm text-white/85">
                    <x-icon name="shield-check" :size="16" />
                    اطلاعات شما با بالاترین استاندارد امنیتی محافظت می‌شود
                </div>
            </div>
        </div>
    </div>
</div>

@unless ($otpPhone)
<script>
    // تب‌های انتخاب روش ورود. کلاس فعال با پیل سبز برند مشخص می‌شود.
    function loginMode(mode) {
        const isPwd = mode === 'password';
        const base = 'rounded-xl py-2.5 text-[13.5px] font-semibold transition-colors duration-200 ';
        const on = base + 'bg-brand-500 text-white shadow-sm';
        const off = base + 'text-muted hover:text-ink';

        document.getElementById('form-password').classList.toggle('hidden', !isPwd);
        document.getElementById('form-otp').classList.toggle('hidden', isPwd);
        document.getElementById('tab-password').className = isPwd ? on : off;
        document.getElementById('tab-otp').className = isPwd ? off : on;
    }

    // نمایش/مخفی کردن رمز: دو آیکون از قبل در DOM هستند و فقط جابه‌جا می‌شوند،
    // تا نیازی به ساختن SVG از داخل جاوااسکریپت نباشد.
    function togglePassword(button) {
        const input = button.closest('label').querySelector('input');
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.querySelector('[data-eye]').classList.toggle('hidden', show);
        button.querySelector('[data-eye-off]').classList.toggle('hidden', !show);
    }

    loginMode('password');
</script>
@endunless
@endsection
