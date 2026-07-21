<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="@yield('description', config('brand.description'))">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0f6e56">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <title>@yield('title', config('brand.tagline')) — {{ config('brand.name') }}</title>

    {{-- تم روشن/تاریک پیش از اولین رنگ‌آمیزی تا صفحه پرش نداشته باشد.
         کلاس js هم اینجا ست می‌شود؛ انیمیشن ظاهرشدن بخش‌ها به آن وابسته است تا
         اگر جاوااسکریپت غیرفعال بود، محتوا به‌جای نامرئی ماندن نمایش داده شود. --}}
    <script>
        (function () {
            const t = localStorage.getItem('theme') || 'system';
            const dark = t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
            document.documentElement.classList.add('js');
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas text-ink antialiased">
    @yield('content')
</body>
</html>
