{{--
    صفحه‌ی مقاله (مرحلهٔ ۶ · فاز ۱۱).

    متن، فهرستِ مطالب و پیوندهای اشتراک همه در HTMLِ سرورند و بدونِ
    جاوااسکریپت کار می‌کنند. بدنه از `_show_body` می‌آید — همان که API برای
    بازکردنِ مقاله بی‌بارگذاریِ دوباره برمی‌گرداند (مرحلهٔ ۷ · فاز ۷). React فقط نوارِ پیشرفت و دکمه‌ی «کپیِ پیوند» را
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
            // نامِ نویسنده نمایش داده نمی‌شود (مرحلهٔ ۷ · فاز ۷)؛ نویسنده و ناشر خودِ ساکناست
            'author' => ['@id' => $base.'/#organization'],
            'publisher' => ['@id' => $base.'/#organization'],
        ], fn ($value) => $value !== null);
    @endphp
    <script type="application/ld+json">{!! \App\Support\Json::forScript($article) !!}</script>
@endpush

@section('content')
    <main id="main-content" class="blog-main" data-blog-view="article">
        @include('public.blog._show_body')
    </main>
    {{-- بی‌فوتر (مرحلهٔ ۸ · فاز ۱): فوترِ صفحه‌ی اصلی این‌جا حذف شد --}}
@endsection
