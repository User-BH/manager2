{{--
    صفحه‌ی مقاله (مرحلهٔ ۶ · فاز ۱۱).

    متن، فهرستِ مطالب و پیوندهای اشتراک همه در HTMLِ سرورند و بدونِ
    جاوااسکریپت کار می‌کنند. React فقط نوارِ پیشرفت و دکمه‌ی «کپیِ پیوند» را
    اضافه می‌کند (`#blog-copy`).

    ⚠️ `bodyHtml` تنها خروجیِ `{!! !!}` است و فقط از `BlogRenderer` می‌آید
    (هنگامِ ذخیره) — نه از هیچ ورودیِ مستقیمی.
--}}
@extends('layouts.public')

@push('head')
    @if ($post['publishedAt'])
        <meta property="article:published_time" content="{{ $post['publishedAt'] }}">
    @endif
    <meta property="article:modified_time" content="{{ $post['updatedAt'] }}">
    @if ($post['category'])
        <meta property="article:section" content="{{ $post['category']['name'] }}">
    @endif
    @foreach ($post['tags'] as $tag)
        <meta property="article:tag" content="{{ $tag['name'] }}">
    @endforeach
@endpush

@push('jsonld')
    @php
        $base = \App\Support\CanonicalUrl::base();
        $article = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            '@id' => $post['canonical'].'#article',
            'headline' => $post['title'],
            'description' => $post['metaDescription'],
            'url' => $post['canonical'],
            'mainEntityOfPage' => $post['canonical'],
            'inLanguage' => 'fa-IR',
            'datePublished' => $post['publishedAt'],
            'dateModified' => $post['updatedAt'],
            'image' => $post['cover'] ? $base.$post['cover'] : null,
            'articleSection' => $post['category']['name'] ?? null,
            'keywords' => $post['tags'] !== [] ? implode('، ', array_column($post['tags'], 'name')) : null,
            'timeRequired' => 'PT'.$post['readingMinutes'].'M',
            'author' => $post['author']
                ? ['@type' => 'Person', 'name' => $post['author']]
                : ['@id' => $base.'/#organization'],
            'publisher' => ['@id' => $base.'/#organization'],
        ], fn ($value) => $value !== null);
    @endphp
    <script type="application/ld+json">{!! \App\Support\Json::forScript($article) !!}</script>
@endpush

@section('content')
    @php
        $shareUrl = rawurlencode($post['canonical']);
        $shareText = rawurlencode($post['title']);
    @endphp

    <main id="main-content" class="blog-main">
        @if ($preview)
            <p class="blog-preview-banner" role="status">
                پیش‌نمایشِ پیش‌نویس — فقط شما این صفحه را می‌بینید و ایندکس نمی‌شود.
            </p>
        @endif

        <article class="blog-article" data-reading-progress>
            <header class="blog-article-header">
                @if ($post['category'])
                    <a class="blog-chip" href="/blog/category/{{ rawurlencode($post['category']['slug']) }}">{{ $post['category']['name'] }}</a>
                @endif
                <h1>{{ $post['title'] }}</h1>
                <p class="blog-meta">
                    @if ($post['publishedAt'])
                        <time datetime="{{ $post['publishedAt'] }}">{{ $post['publishedLabel'] }}</time> ·
                    @endif
                    {{ \App\Support\Jalali::digits($post['readingMinutes']) }} دقیقه مطالعه
                    @if ($post['author'])
                        · {{ $post['author'] }}
                    @endif
                </p>
                @if ($post['cover'])
                    <img class="blog-cover" src="{{ $post['cover'] }}" alt="" width="1200" height="630" loading="eager" fetchpriority="high">
                @endif
            </header>

            @if (count($post['toc']) >= 2)
                {{-- فهرستِ مطالب: `details` تا روی موبایل جمع بماند و بدونِ JS باز شود --}}
                <nav class="blog-toc" aria-label="فهرستِ مطالب">
                    <details open>
                        <summary>فهرستِ مطالب</summary>
                        <ol>
                            @foreach ($post['toc'] as $tocItem)
                                <li class="blog-toc-level-{{ $tocItem['level'] }}">
                                    <a href="#{{ $tocItem['id'] }}">{{ $tocItem['text'] }}</a>
                                </li>
                            @endforeach
                        </ol>
                    </details>
                </nav>
            @endif

            <div class="blog-prose">
                {!! $post['bodyHtml'] !!}
            </div>

            @if ($post['tags'] !== [])
                <ul class="blog-tags" aria-label="برچسب‌ها">
                    @foreach ($post['tags'] as $tag)
                        <li><a class="blog-chip" href="/blog/tag/{{ rawurlencode($tag['slug']) }}">#{{ $tag['name'] }}</a></li>
                    @endforeach
                </ul>
            @endif

            <section class="blog-share" aria-label="اشتراک‌گذاری">
                <span>اشتراک‌گذاری:</span>
                <a href="https://t.me/share/url?url={{ $shareUrl }}&text={{ $shareText }}" target="_blank" rel="noopener noreferrer">تلگرام</a>
                <a href="https://wa.me/?text={{ $shareText }}%20{{ $shareUrl }}" target="_blank" rel="noopener noreferrer">واتساپ</a>
                <a href="https://twitter.com/intent/tweet?url={{ $shareUrl }}&text={{ $shareText }}" target="_blank" rel="noopener noreferrer">ایکس</a>
                <a href="https://www.linkedin.com/sharing/share-offsite/?url={{ $shareUrl }}" target="_blank" rel="noopener noreferrer">لینکدین</a>
                <span id="blog-copy" data-url="{{ $post['canonical'] }}" data-title="{{ $post['title'] }}"></span>
            </section>
        </article>

        @if ($related !== [])
            <section class="blog-related" aria-labelledby="blog-related-title">
                <h2 id="blog-related-title">مطالبِ مرتبط</h2>
                <div class="blog-grid">
                    @foreach ($related as $item)
                        @include('public.blog._card', ['item' => $item, 'level' => 3])
                    @endforeach
                </div>
            </section>
        @endif
    </main>

    <div id="blog-footer"></div>
@endsection
