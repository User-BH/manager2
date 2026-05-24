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
            <p class="mt-1 text-sm text-slate-400">برای ورود، اطلاعات حساب خود را وارد کنید</p>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-800">
            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf
                <x-input name="email" type="email" label="ایمیل" required dir="ltr" autofocus />
                <x-input name="password" type="password" label="رمز عبور" required dir="ltr" />

                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="remember" class="rounded border-slate-300 text-sky-600 focus:ring-sky-500">
                    مرا به خاطر بسپار
                </label>

                @if ($errors->any())
                    <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-800 dark:bg-rose-900/30 dark:text-rose-300">
                        {{ $errors->first() }}
                    </div>
                @endif

                <x-button class="w-full">ورود به سامانه</x-button>
            </form>
        </div>

        <div class="mt-6 rounded-2xl border border-slate-200 bg-white/60 p-4 text-xs text-slate-500 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-400">
            <p class="mb-2 font-semibold text-slate-600 dark:text-slate-300">حساب‌های نمونه (رمز همه: <span dir="ltr">password</span>):</p>
            <ul class="space-y-1" dir="ltr">
                <li>admin@system.test — ادمین کل</li>
                <li>manager@aftab.test — مدیر مجتمع</li>
                <li>owner1@aftab.test — مالک</li>
                <li>tenant2@aftab.test — مستاجر</li>
            </ul>
        </div>
    </div>
</div>
</body>
</html>
