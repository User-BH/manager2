<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>در حال انتقال به درگاه پرداخت…</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-100 text-slate-700 dark:bg-slate-900 dark:text-slate-200">
    <div class="text-center">
        <div class="mx-auto mb-4 h-10 w-10 animate-spin rounded-full border-4 border-sky-200 border-t-sky-600"></div>
        <p>در حال انتقال به درگاه بانکی…</p>
        <p class="mt-1 text-sm text-slate-400">در صورت عدم انتقال خودکار، روی دکمه‌ی زیر کلیک کنید.</p>

        <form method="POST" action="{{ $action }}" id="gw">
            @foreach ($fields as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <button type="submit" class="mt-4 rounded-xl bg-sky-600 px-5 py-2 text-white">انتقال به درگاه</button>
        </form>
    </div>
    <script>document.getElementById('gw').submit();</script>
</body>
</html>
