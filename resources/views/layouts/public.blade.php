@php
    $brand = config('brand');
    $seo = array_merge(config('seo.default'), $seo ?? []);
    $base = rtrim(config('app.url'), '/');
    $current = url()->current();
    $ogImage = $base.'/images/og-cover.png';
    $sameAs = array_values(array_map(fn ($s) => $s['href'], $brand['socials'] ?? []));

    // JSON-LDِ سطحِ سازمان و محصول: به موتورهای جستجو و هوش‌مصنوعی‌ها می‌گوید
    // این سایت چیست، چه می‌کند و چطور با آن تماس بگیرند.
    $organization = [
        '@type' => 'Organization',
        '@id' => $base.'/#organization',
        'name' => $brand['name'],
        'url' => $base.'/',
        'logo' => $base.'/icons/icon-192.png',
        'description' => $brand['description'],
        'sameAs' => $sameAs,
        'contactPoint' => [
            '@type' => 'ContactPoint',
            'telephone' => $brand['contact']['phone_href'] ?? null,
            'email' => $brand['contact']['email'] ?? null,
            'contactType' => 'customer support',
            'areaServed' => 'IR',
            'availableLanguage' => ['Persian'],
        ],
    ];

    $website = [
        '@type' => 'WebSite',
        '@id' => $base.'/#website',
        'url' => $base.'/',
        'name' => $brand['name'],
        'inLanguage' => 'fa-IR',
        'publisher' => ['@id' => $base.'/#organization'],
    ];

    $product = [
        '@type' => 'SoftwareApplication',
        'name' => $brand['name'].' — '.$brand['tagline'],
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Web',
        'url' => $base.'/',
        'inLanguage' => 'fa-IR',
        'description' => $brand['description'],
        'featureList' => [
            'صدور قبض و شارژ ماهانه', 'پرداخت آنلاین و ثبت رسید', 'اطلاعیه و پیام‌رسان داخلی',
            'مدیریت واحدها و ساکنین', 'گزارش مالی و صندوق', 'ورود دومرحله‌ای امن',
        ],
        'offers' => [
            '@type' => 'Offer',
            'price' => '0',
            'priceCurrency' => 'IRR',
            'description' => 'شروع رایگان',
        ],
        'publisher' => ['@id' => $base.'/#organization'],
    ];

    $graph = ['@context' => 'https://schema.org', '@graph' => [$organization, $website, $product]];
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- توکن CSRF برای درخواست‌های تغییردهنده‌ی React به /api --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    @isset($seo['keywords'])
        <meta name="keywords" content="{{ $seo['keywords'] }}">
    @endisset
    <meta name="author" content="{{ $brand['name'] }}">
    <link rel="canonical" href="{{ $current }}">
    <meta name="robots" content="{{ $seo['robots'] ?? 'index,follow,max-image-preview:large,max-snippet:-1' }}">

    {{-- Open Graph (فیسبوک، تلگرام، لینکدین، …) --}}
    <meta property="og:type" content="{{ $seo['og_type'] ?? 'website' }}">
    <meta property="og:site_name" content="{{ $brand['name'] }}">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:url" content="{{ $current }}">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ $brand['name'] }} — {{ $brand['tagline'] }}">

    {{-- Twitter Card --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seo['title'] }}">
    <meta name="twitter:description" content="{{ $seo['description'] }}">
    <meta name="twitter:image" content="{{ $ogImage }}">

    <link rel="icon" href="/favicon-48.png" type="image/png">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0f6e56">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">

    {{-- داده‌ی ساخت‌یافته‌ی سطحِ سایت --}}
    <script type="application/ld+json">{!! json_encode($graph, $jsonFlags) !!}</script>
    @stack('jsonld')

    {{-- تم پیش از اولین رنگ‌آمیزی اعمال می‌شود تا صفحه هنگام بارگذاری پرش نکند --}}
    <script>
        (function () {
            var t = localStorage.getItem('theme') || 'system';
            var dark = t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>

    @vite(['resources/css/app.css', $entry])
</head>
<body>
    <div id="root"></div>
</body>
</html>
