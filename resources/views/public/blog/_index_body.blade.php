{{--
    بدنه‌ی فهرستِ بلاگ (مرحلهٔ ۷ · فاز ۷) — همه‌چیزِ درونِ `<main>`.

    ⚠️ یک قالب، دو مصرف: صفحه‌ی سرورساید (`index.blade.php`) و API ِ
    `/api/blog` که همین را برای جابه‌جاییِ بی‌بارگذاریِ دوباره برمی‌گرداند.
    پس ظاهرِ فهرست هرگز دو نسخه نمی‌شود که از هم جدا بیفتند.

    چیدمان: یک ستونِ کارت‌های پهن (نه شبکه‌ی فشرده) و کنارش در دسکتاپ
    دسته‌ها و دعوت به دمو. روی موبایل ستونِ کنار زیرِ فهرست می‌رود.
--}}
@php
    $query = $search !== null ? '&q='.rawurlencode($search) : '';
    $link = fn (int $p) => $search !== null
        ? '/blog?page='.$p.$query
        : parse_url($pageUrl($p), PHP_URL_PATH).($p > 1 ? '?page='.$p : '');
    $digits = fn ($n) => \App\Support\Jalali::digits($n);
@endphp

<div class="blog-layout">
    <div class="blog-column">
        <header class="blog-header">
            {{-- روی صفحه‌ی اصلیِ بلاگ عنوان خودش «بلاگ ساکنا» است؛ تکرارش لازم نیست --}}
            @if ($heading !== 'بلاگ '.config('brand.name'))
                <p class="blog-eyebrow">بلاگِ {{ config('brand.name') }}</p>
            @endif
            <h1 tabindex="-1" data-blog-focus>{{ $heading }}</h1>
            @if ($description)
                <p>{{ $description }}</p>
            @else
                <p>راهنمای کاربردیِ مدیریتِ مجتمع: از محاسبه‌ی شارژ و پیگیریِ بدهی تا ارتباط با ساکنین.</p>
            @endif

            <form action="/blog" method="get" role="search" class="blog-search" data-blog-search>
                <label for="blog-q" class="sr-only">جست‌وجو در بلاگ</label>
                <input id="blog-q" class="field" type="search" name="q" value="{{ $search }}" maxlength="80"
                       placeholder="مثلاً «شارژ» یا «پیام‌رسان»" autocomplete="off" enterkeyhint="search">
                <button type="submit">جست‌وجو</button>
            </form>
            <p class="blog-search-hint" data-blog-search-hint role="status" aria-live="polite"></p>
        </header>

        @if ($search !== null)
            <p class="blog-result-count" role="status">
                @if (! empty($result['tooShort']))
                    عبارتِ جست‌وجو دست‌کم {{ $digits(\App\Services\Blog\PublicBlog::MIN_SEARCH) }} حرف باشد.
                @elseif (! empty($result['partial']))
                    {{-- مرحلهٔ ۸ · فاز ۴: هیچ مقاله‌ای همه‌ی واژه‌ها را نداشت --}}
                    مقاله‌ای با همه‌ی واژه‌های «{{ $search }}» نبود؛ {{ $digits($result['total']) }} مقاله‌ی نزدیک:
                @else
                    {{ $digits($result['total']) }} مقاله برای «{{ $search }}»
                @endif
            </p>
        @endif

        @if ($result['items'] === [])
            <div class="blog-empty">
                <svg viewBox="0 0 24 24" width="40" height="40" aria-hidden="true" fill="none" stroke="currentColor"
                     stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>
                </svg>
                @if ($search !== null)
                    <p class="blog-empty-title">مقاله‌ای برای جست‌وجوی شما پیدا نشد.</p>
                    <p>عبارتِ کوتاه‌تر یا واژه‌ی دیگری امتحان کنید؛ مثلاً «قبض»، «پرداخت» یا «ساکنین».</p>
                    <a href="/blog">همه‌ی مقاله‌ها</a>
                @else
                    <p class="blog-empty-title">هنوز مقاله‌ای اینجا منتشر نشده است.</p>
                    <p>به‌زودی راهنماهای تازه‌ای درباره‌ی مدیریتِ مجتمع می‌خوانید.</p>
                    @if ($heading !== 'بلاگ '.config('brand.name'))
                        <a href="/blog">همه‌ی مقاله‌ها</a>
                    @endif
                @endif
            </div>
        @else
            <div class="blog-list">
                @foreach ($result['items'] as $item)
                    @include('public.blog._card', ['item' => $item, 'eager' => $loop->first && $result['page'] === 1])
                @endforeach
            </div>
        @endif

        @if ($result['lastPage'] > 1)
            @php
                $current = $result['page'];
                $last = $result['lastPage'];
                // همه‌ی شماره‌ها تا هفت صفحه؛ بیشتر از آن اول، آخر و همسایه‌های صفحه‌ی جاری
                $numbers = $last <= 7
                    ? range(1, $last)
                    : array_values(array_unique(array_filter(
                        [1, $current - 1, $current, $current + 1, $last],
                        fn ($n) => $n >= 1 && $n <= $last,
                    )));
            @endphp
            <nav aria-label="صفحه‌بندی" class="blog-pager">
                @if ($current > 1)
                    <a rel="prev" href="{{ $link($current - 1) }}" aria-label="صفحه‌ی قبل">→</a>
                @endif
                @foreach ($numbers as $i => $n)
                    @if ($i > 0 && $n - $numbers[$i - 1] > 1)
                        <span class="blog-pager-gap" aria-hidden="true">…</span>
                    @endif
                    @if ($n === $current)
                        <span aria-current="page">{{ $digits($n) }}</span>
                    @else
                        <a href="{{ $link($n) }}" aria-label="صفحه‌ی {{ $digits($n) }}">{{ $digits($n) }}</a>
                    @endif
                @endforeach
                @if ($current < $last)
                    <a rel="next" href="{{ $link($current + 1) }}" aria-label="صفحه‌ی بعد">←</a>
                @endif
            </nav>
        @endif
    </div>

    <aside class="blog-sidebar" aria-label="دسته‌ها و معرفی">
        @if ($categories !== [])
            <nav aria-label="دسته‌ها" class="blog-side-card">
                <p class="blog-side-title">دسته‌ها</p>
                <ul class="blog-categories">
                    @foreach ($categories as $cat)
                        <li>
                            <a class="blog-chip" href="/blog/category/{{ rawurlencode($cat['slug']) }}">
                                {{ $cat['name'] }} <span>{{ $digits($cat['count']) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif

        <div class="blog-side-card blog-side-cta">
            <p class="blog-side-title">{{ config('brand.name') }} را از نزدیک ببینید</p>
            <p>صفحه‌ی دمو بخش‌های اصلیِ پنل را یکی‌یکی نشان می‌دهد — بدونِ ساختنِ حساب.</p>
            <a href="/demo">مشاهده‌ی دمو</a>
        </div>
    </aside>
</div>
