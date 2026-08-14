@php
    $brand = config('brand');
    $seo = array_merge(config('seo.default'), $seo ?? []);
    /*
     * ⚠️ آدرس‌ها از `APP_URL` می‌آیند، نه از هدرِ درخواست.
     *
     * پیش از این `url()->current()` بود و میزبان را از `Host`ِ خودِ درخواست
     * می‌گرفت. آزمونِ واقعی:
     *   `curl -H "Host: evil.example.com" …` → canonical به evil اشاره کرد.
     */
    $base = \App\Support\CanonicalUrl::base();
    $current = \App\Support\CanonicalUrl::forRequest(request());
    $ogImage = \App\Support\CanonicalUrl::asset('images/og-cover.png');
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

    /*
     * مسیرِ راهنما (R38 · آیتم ②).
     *
     * فقط برای صفحه‌هایی که `breadcrumb` دارند؛ صفحه‌ی خانه ریشه است و
     * مسیرِ تک‌عضوی معنایی ندارد.
     *
     * ⚠️ `item` باید آدرسِ **مطلق** باشد و با `canonical` یکی؛ اگر فرق کنند
     * گوگل مسیر را نامعتبر می‌داند و اصلاً نشانش نمی‌دهد.
     */
    $breadcrumbs = isset($seo['breadcrumb'])
        ? [
            ['name' => 'خانه', 'url' => $base.'/'],
            ['name' => $seo['breadcrumb'], 'url' => $current],
        ]
        : [];

    $graph = ['@context' => 'https://schema.org', '@graph' => [$organization, $website, $product]];

    if ($breadcrumbs !== []) {
        $graph['@graph'][] = [
            '@type' => 'BreadcrumbList',
            '@id' => $current.'#breadcrumb',
            'itemListElement' => array_map(
                fn (array $crumb, int $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $crumb['name'],
                    'item' => $crumb['url'],
                ],
                $breadcrumbs,
                array_keys($breadcrumbs),
            ),
        ];
    }
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

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
    {{--
        ⚠️ `secure_url` و `type` برای پیام‌رسان‌ها (فنی-۲۱).

        واتساپ اگر نوعِ تصویر را نداند گاهی پیش‌نمایش را کلاً رها می‌کند، و
        بعضی کلاینت‌ها فقط `og:image:secure_url` را می‌خوانند و از `og:image`
        صرف‌نظر می‌کنند. عرض و ارتفاع هم اجباری‌اند: بدونشان تلگرام و واتساپ
        باید خودِ فایل را دانلود کنند تا ابعاد را بفهمند و اگر کند بود،
        پیش‌نمایش را بی‌خیال می‌شوند.
    --}}
    <meta property="og:image:secure_url" content="{{ $ogImage }}">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ $brand['name'] }} — {{ $brand['tagline'] }}">

    {{-- Twitter Card --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seo['title'] }}">
    <meta name="twitter:description" content="{{ $seo['description'] }}">
    <meta name="twitter:image" content="{{ $ogImage }}">
    <meta name="twitter:image:alt" content="{{ $brand['name'] }} — {{ $brand['tagline'] }}">

    {{--
        پیام‌رسان‌های ایرانی و بین‌المللی.

        تلگرام و واتساپ و روبیکا و بله همگی از Open Graph می‌خوانند، ولی سه
        نکته‌ی عملی دارند که اینجا رعایت شده:
        ① آدرسِ تصویر باید **مطلق** باشد (نسبی را نمی‌فهمند)،
        ② بدونِ ریدایرکت سرو شود (کراولرشان ریدایرکت را دنبال نمی‌کند)،
        ③ حجمش کم باشد — `og-cover.png` حدودِ ‎۱۵۰KB است و زیرِ سقفِ
           ~‎۳۰۰KBِ واتساپ می‌ماند.

        `msapplication-TileImage` هم برای پیش‌نمایشِ ویندوز است و هزینه‌اش
        یک خط است.
    --}}
    <meta name="msapplication-TileImage" content="{{ $ogImage }}">
    <meta name="msapplication-TileColor" content="{{ $brand['color'] ?? '#0f6e56' }}">

    <link rel="icon" href="/favicon-48.png" type="image/png">
    @include('partials.pwa')

    {{-- داده‌ی ساخت‌یافته‌ی سطحِ سایت --}}
    <script type="application/ld+json">{!! \App\Support\Json::forScript($graph) !!}</script>
    @stack('jsonld')

    {{-- تم پیش از اولین رنگ‌آمیزی اعمال می‌شود تا صفحه هنگام بارگذاری پرش نکند --}}
    <script @csp>
        (function () {
            var t = localStorage.getItem('theme') || 'system';
            var dark = t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>

    @include('partials.observability')
    @include('partials.viewer')

    @include('partials.resource-hints')

    @vite(['resources/css/app.css', $entry])
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

    @if ($breadcrumbs !== [])
        {{--
            مسیرِ راهنمای دیدنی.

            ⚠️ در Blade است نه React: هم باید در HTMLِ خامِ سرور باشد تا
            خزنده‌ای که جاوااسکریپت اجرا نمی‌کند ببیندش، و هم باید با
            JSON-LDِ بالا **یکی** باشد — داده‌ی ساخت‌یافته‌ای که چیزی بگوید
            که روی صفحه نیست، از دیدِ گوگل تخلف است.

            `sr-only` نیست: مسیرِ راهنما برای خودِ کاربر هم مفید است، پس
            دیده می‌شود و بالای محتوا می‌نشیند.
        --}}
        <nav aria-label="مسیر راهنما" class="breadcrumb-bar">
            <ol>
                @foreach ($breadcrumbs as $index => $crumb)
                    <li>
                        @if ($loop->last)
                            <span aria-current="page">{{ $crumb['name'] }}</span>
                        @else
                            <a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a>
                            <span aria-hidden="true">›</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
    @endif

    <div id="root"></div>

    {{-- جای اتصال برای چیزهایی که باید در HTMLِ سرور باشند (مثلِ نشانِ اینماد) --}}
    @stack('body_end')
</body>
</html>
