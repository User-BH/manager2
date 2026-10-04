{{--
    بدنه‌ی صفحه‌ی مقاله (مرحلهٔ ۷ · فاز ۷) — همه‌چیزِ درونِ `<main>`.

    مثلِ `_index_body`: یک قالب برای صفحه‌ی سرورساید و API ِ
    `/api/blog/posts/{slug}`.

    ⚠️ `bodyHtml` تنها خروجیِ `{!! !!}` است و فقط از `BlogRenderer` می‌آید
    (هنگامِ ذخیره) — نه از هیچ ورودیِ مستقیمی.

    ⚠️ نامِ نویسنده عمداً نیست (خواسته‌ی ممیزی): نه این‌جا، نه در JSON-LD.
--}}
@php
    $shareUrl = rawurlencode($post['canonical']);
    $shareText = rawurlencode($post['title']);
    $digits = fn ($n) => \App\Support\Jalali::digits($n);
@endphp

@if ($preview)
    <p class="blog-preview-banner" role="status">
        پیش‌نمایشِ پیش‌نویس — فقط شما این صفحه را می‌بینید و ایندکس نمی‌شود.
    </p>
@endif

<article class="blog-article" data-reading-progress>
    <header class="blog-article-header">
        <a class="blog-back" href="/blog">همه‌ی مقاله‌ها</a>
        @if ($post['category'])
            <a class="blog-chip" href="/blog/category/{{ rawurlencode($post['category']['slug']) }}">{{ $post['category']['name'] }}</a>
        @endif
        <h1 tabindex="-1" data-blog-focus>{{ $post['title'] }}</h1>
        <p class="blog-meta">
            @if ($post['publishedAt'])
                <time datetime="{{ $post['publishedAt'] }}">{{ $post['publishedLabel'] }}</time>
                <span aria-hidden="true">·</span>
            @endif
            <span>{{ $digits($post['readingMinutes']) }} دقیقه مطالعه</span>
        </p>
        @if ($post['cover'])
            {{-- مرحلهٔ ۸ · فاز ۳: alt از عنوان؛ اگر تصویر بار نشد، همین متن به‌جایش (blogImages.ts) --}}
            <img class="blog-cover" src="{{ $post['cover'] }}" alt="تصویرِ جلدِ مقاله: {{ $post['title'] }}" width="1200" height="630" loading="eager" fetchpriority="high"
                 data-fallback="{{ $post['title'] }}">
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

    {{--
        دعوت به اقدامِ پایانِ مقاله (فاز ۷): یکی، نه در هر بخش. پیوندِ هر
        قابلیت در خودِ متن است؛ این‌جا فقط دو مسیرِ واقعیِ سایت.
    --}}
    <aside class="blog-cta" aria-label="آشنایی با {{ config('brand.name') }}">
        <p class="blog-cta-title">این‌ها را در {{ config('brand.name') }} از نزدیک ببینید</p>
        <p>صفحه‌ی دمو بخش‌های اصلیِ پنل را نشان می‌دهد؛ ساختنِ حساب هم رایگان است و کارتِ بانکی نمی‌خواهد.</p>
        <div class="blog-cta-actions">
            <a class="blog-cta-primary" href="/demo">مشاهده‌ی دمو</a>
            <a class="blog-cta-secondary" href="/auth?tab=register">ساختِ حسابِ رایگان</a>
        </div>
    </aside>

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
        <h2 id="blog-related-title">{{ $relatedTitle ?? 'مقاله‌های مرتبط' }}</h2>
        <div class="blog-related-grid">
            @foreach ($related as $item)
                @include('public.blog._card', ['item' => $item, 'level' => 3, 'compact' => true])
            @endforeach
        </div>
    </section>
@endif
