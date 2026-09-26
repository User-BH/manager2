{{-- کارتِ مقاله در فهرست و «مطالبِ مرتبط» (مرحلهٔ ۶ · فاز ۱۱). --}}
<article class="blog-card">
    @if ($item['cover'])
        <a href="{{ $item['url'] }}" class="blog-card-cover" tabindex="-1" aria-hidden="true">
            <img src="{{ $item['cover'] }}" alt="" loading="lazy" decoding="async" width="640" height="360">
        </a>
    @endif
    <div class="blog-card-body">
        @if ($item['category'])
            <a class="blog-chip" href="/blog/category/{{ rawurlencode($item['category']['slug']) }}">{{ $item['category']['name'] }}</a>
        @endif
        {{-- زیرِ «مطالبِ مرتبط» (خودش h2) سطحِ عنوان یکی پایین‌تر است --}}
        <h{{ $level ?? 2 }} class="blog-card-title"><a href="{{ $item['url'] }}">{{ $item['title'] }}</a></h{{ $level ?? 2 }}>
        <p class="blog-card-excerpt">{{ $item['excerpt'] }}</p>
        <p class="blog-meta">
            @if ($item['publishedAt'])
                <time datetime="{{ $item['publishedAt'] }}">{{ $item['publishedLabel'] }}</time> ·
            @endif
            {{ \App\Support\Jalali::digits($item['readingMinutes']) }} دقیقه مطالعه
        </p>
    </div>
</article>
