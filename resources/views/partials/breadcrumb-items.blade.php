{{--
    فهرستِ مسیرِ راهنما (`<ol>`) — مرحلهٔ ۷ · فاز ۷.

    جدا از قالب چون دو جا می‌سازندش: خودِ قالبِ صفحه‌های عمومی، و API ِ
    بلاگ که پس از جابه‌جاییِ بی‌بارگذاری همین را برمی‌گرداند تا مسیرِ راهنما
    کهنه نماند (پیش از این، روی مقاله‌ای که بی‌بارگذاری باز شده بود هنوز
    مسیرِ فهرست دیده می‌شد).
--}}
<ol>
    @foreach ($breadcrumbs as $crumb)
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
