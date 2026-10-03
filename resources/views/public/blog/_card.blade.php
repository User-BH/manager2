{{--
    کارتِ مقاله در فهرست و «مطالبِ مرتبط».

    مرحلهٔ ۷ · فاز ۷: کارتِ پهن — تصویر کنارِ متن در دسکتاپ و بالای آن در
    موبایل — به‌جای کارتِ کوچکِ شبکه‌ی سه‌ستونی. کلِ کارت کلیک‌پذیر است (پیوندِ
    عنوان با `::after` روی کارت پهن می‌شود) ولی فقط **یک** پیوند در ترتیبِ Tab
    دارد؛ تصویر `tabindex=-1` است تا صفحه‌خوان عنوان را دو بار نشنود.
--}}
<article class="blog-card{{ ($compact ?? false) ? ' blog-card-compact' : '' }}">
    @if ($item['cover'])
        <a href="{{ $item['url'] }}" class="blog-card-cover" tabindex="-1" aria-hidden="true">
            <img src="{{ $item['cover'] }}" alt="" width="1200" height="630" decoding="async"
                 @if ($eager ?? false) loading="eager" fetchpriority="high" @else loading="lazy" @endif>
        </a>
    @endif
    <div class="blog-card-body">
        @if ($item['category'])
            <a class="blog-chip blog-card-chip" href="/blog/category/{{ rawurlencode($item['category']['slug']) }}">{{ $item['category']['name'] }}</a>
        @endif
        {{-- زیرِ «مطالبِ مرتبط» (خودش h2) سطحِ عنوان یکی پایین‌تر است --}}
        <h{{ $level ?? 2 }} class="blog-card-title"><a href="{{ $item['url'] }}">{{ $item['title'] }}</a></h{{ $level ?? 2 }}>
        <p class="blog-card-excerpt">{{ $item['excerpt'] }}</p>
        <p class="blog-meta">
            @if ($item['publishedAt'])
                <time datetime="{{ $item['publishedAt'] }}">{{ $item['publishedLabel'] }}</time>
                <span aria-hidden="true">·</span>
            @endif
            <span>{{ \App\Support\Jalali::digits($item['readingMinutes']) }} دقیقه مطالعه</span>
        </p>
    </div>
</article>
