<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- توکن CSRF: کلاینت React آن را از همین تگ می‌خواند و روی هر درخواست
         تغییردهنده به /api می‌فرستد، چون احراز هویت با نشست وب انجام می‌شود
         نه توکن bearer. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <meta name="description" content="{{ config('brand.description') }}">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0f6e56">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">

    <title>{{ config('brand.tagline') }} — {{ config('brand.name') }}</title>

    {{-- تم پیش از اولین رنگ‌آمیزی اعمال می‌شود تا صفحه هنگام بارگذاری پرش نکند --}}
    <script>
        (function () {
            var t = localStorage.getItem('theme') || 'system';
            var dark = t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/main.tsx'])
</head>
<body>
    <div id="root"></div>
</body>
</html>
