<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    {{-- توکن CSRF: کلاینت React آن را از همین تگ می‌خواند و روی هر درخواست
         تغییردهنده به /api می‌فرستد، چون احراز هویت با نشست وب انجام می‌شود
         نه توکن bearer. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <meta name="description" content="{{ config('brand.description') }}">
    <link rel="icon" href="/favicon-48.png" type="image/png">
    @include('partials.pwa')

    <title>{{ config('brand.tagline') }} — {{ config('brand.name') }}</title>

    {{-- تم پیش از اولین رنگ‌آمیزی اعمال می‌شود تا صفحه هنگام بارگذاری پرش نکند --}}
    <script @csp>
        (function () {
            var t = localStorage.getItem('theme') || 'system';
            var dark = t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>

    @include('partials.observability')

    @include('partials.resource-hints')

    @vite(['resources/css/app.css', 'resources/js/app/main.tsx'])
</head>
<body>
    {{--
        پیوندِ پرش به محتوا (R37).

        ⚠️ اولین چیزِ قابلِ فوکوسِ صفحه است و تا فوکوس نگیرد دیده نمی‌شود.
        بدونِ آن، کاربرِ کیبورد و صفحه‌خوان باید در هر بار ورود به هر صفحه
        از کلِ منو و نوارِ بالا Tab بزند تا به محتوا برسد.

        در Blade است نه React: باید در HTMLِ خامِ سرور باشد تا پیش از
        بالاآمدنِ جاوااسکریپت هم کار کند.
    --}}
    <a href="#main-content" class="skip-link">پرش به محتوای اصلی</a>

    <div id="root"></div>
</body>
</html>
