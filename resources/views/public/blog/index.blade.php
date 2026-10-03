{{--
    فهرستِ بلاگ، دسته، برچسب و نتیجه‌ی جست‌وجو (مرحلهٔ ۶ · فاز ۱۱).

    همه‌چیز سرورساید است؛ صفحه‌بندی و جست‌وجو پیوند و فرمِ معمولی‌اند و بدونِ
    جاوااسکریپت کار می‌کنند. با جاوااسکریپت (مرحلهٔ ۷ · فاز ۷) همان‌ها بی‌بارگذاریِ
    دوباره انجام می‌شوند و بدنه از `_index_body` ِ همین پوشه می‌آید.
--}}
@extends('layouts.public')

@push('jsonld')
    @php
        $collection = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $heading,
            'url' => $seo['canonical'],
            'inLanguage' => 'fa-IR',
            'isPartOf' => ['@id' => \App\Support\CanonicalUrl::base().'/#website'],
            'mainEntity' => [
                '@type' => 'ItemList',
                'itemListElement' => array_map(fn ($item, $i) => [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'url' => \App\Support\CanonicalUrl::base().$item['url'],
                    'name' => $item['title'],
                ], $result['items'], array_keys($result['items'])),
            ],
        ];
    @endphp
    <script type="application/ld+json">{!! \App\Support\Json::forScript($collection) !!}</script>
@endpush

@section('content')
    {{--
        `data-blog-view` و محتوای `<main>` را ناوبریِ بی‌بارگذاریِ بلاگ عوض
        می‌کند (`features/landing/blog/blogNavigation.ts`)؛ سرصفحه و پاصفحه
        بیرونِ آن‌اند و دست نمی‌خورند.
    --}}
    <main id="main-content" class="blog-main" data-blog-view="listing">
        @include('public.blog._index_body')
    </main>

    <div id="blog-footer"></div>
@endsection
