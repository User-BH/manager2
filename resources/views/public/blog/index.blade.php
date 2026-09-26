{{--
    فهرستِ بلاگ، دسته، برچسب و نتیجه‌ی جست‌وجو (مرحلهٔ ۶ · فاز ۱۱).

    همه‌چیز سرورساید است؛ صفحه‌بندی و جست‌وجو پیوند و فرمِ معمولی‌اند و بدونِ
    جاوااسکریپت کار می‌کنند.
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
    <main id="main-content" class="blog-main">
        <header class="blog-header">
            <h1>{{ $heading }}</h1>
            @if ($description)
                <p>{{ $description }}</p>
            @endif

            <form action="/blog" method="get" role="search" class="blog-search">
                <label for="blog-q" class="sr-only">جست‌وجو در بلاگ</label>
                <input id="blog-q" type="search" name="q" value="{{ $search }}" maxlength="80"
                       placeholder="جست‌وجو در مقاله‌ها…" autocomplete="off">
                <button type="submit">جست‌وجو</button>
            </form>

            @if ($categories !== [])
                <nav aria-label="دسته‌ها" class="blog-categories">
                    @foreach ($categories as $cat)
                        <a class="blog-chip" href="/blog/category/{{ rawurlencode($cat['slug']) }}">
                            {{ $cat['name'] }} <span>({{ \App\Support\Jalali::digits($cat['count']) }})</span>
                        </a>
                    @endforeach
                </nav>
            @endif
        </header>

        @if ($search !== null)
            <p class="blog-result-count" role="status">
                {{ \App\Support\Jalali::digits($result['total']) }} مقاله برای «{{ $search }}»
            </p>
        @endif

        @if ($result['items'] === [])
            <div class="blog-empty">
                <p>{{ $search !== null ? 'مقاله‌ای با این عبارت پیدا نشد.' : 'هنوز مقاله‌ای منتشر نشده است.' }}</p>
                @if ($search !== null)
                    <a href="/blog">همه‌ی مقاله‌ها</a>
                @endif
            </div>
        @else
            <div class="blog-grid">
                @foreach ($result['items'] as $item)
                    @include('public.blog._card', ['item' => $item])
                @endforeach
            </div>
        @endif

        @if ($result['lastPage'] > 1)
            @php
                $query = $search !== null ? '&q='.rawurlencode($search) : '';
                $link = fn (int $p) => $search !== null
                    ? '/blog?page='.$p.$query
                    : parse_url($pageUrl($p), PHP_URL_PATH).($p > 1 ? '?page='.$p : '');
            @endphp
            <nav aria-label="صفحه‌بندی" class="blog-pager">
                @if ($result['page'] > 1)
                    <a rel="prev" href="{{ $link($result['page'] - 1) }}">قبلی</a>
                @endif
                <span>صفحه‌ی {{ \App\Support\Jalali::digits($result['page']) }} از {{ \App\Support\Jalali::digits($result['lastPage']) }}</span>
                @if ($result['page'] < $result['lastPage'])
                    <a rel="next" href="{{ $link($result['page'] + 1) }}">بعدی</a>
                @endif
            </nav>
        @endif
    </main>

    <div id="blog-footer"></div>
@endsection
