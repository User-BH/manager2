{{--
    تگ‌های PWA — مشترکِ پوسته‌ی عمومی و پوسته‌ی داشبورد (R35).

    ⚠️ پیش از این هر دو پوسته سه خطِ خودشان را داشتند و از هم جدا افتاده
    بودند؛ `apple-touch-icon` در هر دو به `/icons/icon-192.png` اشاره می‌کرد
    در حالی که فایلِ ۱۸۰×۱۸۰ که iOS می‌خواهد از قبل موجود بود و استفاده
    نمی‌شد.
--}}

<link rel="manifest" href="/manifest.webmanifest">

{{--
    رنگِ نوارِ مرورگر، به تفکیکِ تم.

    بدونِ نسخه‌ی تیره، کاربری که تمِ تیره دارد یک نوارِ سبزِ روشن بالای
    صفحه‌ی تیره می‌بیند. `media` را مرورگر خودش انتخاب می‌کند.
--}}
<meta name="theme-color" content="#0f6e56" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0f1411" media="(prefers-color-scheme: dark)">

{{-- iOS: بدونِ این‌ها برنامه‌ی نصب‌شده داخلِ سافاری با نوارِ آدرس باز می‌شود --}}
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="{{ config('brand.name') }}">
<meta name="application-name" content="{{ config('brand.name') }}">

<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<link rel="mask-icon" href="/icons/monochrome.svg" color="#0f6e56">

{{--
    صفحه‌ی آغازینِ iOS.

    ⚠️ سافاری تنها مرورگری است که splash را از خودِ manifest نمی‌سازد و
    برای هر اندازه‌ی صفحه یک فایلِ جدا با media query می‌خواهد. اگر اندازه‌ی
    دستگاه با هیچ‌کدام جور نشود، به‌جای splash یک صفحه‌ی سفید می‌بیند.
--}}
@foreach (config('pwa.splash') as $splash)
    <link
        rel="apple-touch-startup-image"
        href="{{ $splash['href'] }}"
        media="(device-width: {{ $splash['width'] }}px) and (device-height: {{ $splash['height'] }}px) and (-webkit-device-pixel-ratio: {{ $splash['ratio'] }}) and (orientation: portrait)"
    >
@endforeach
