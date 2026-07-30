{{--
    شناسه‌های پایش و تحلیل، برای مصرفِ فرانت.

    ─── چرا در `<head>` و نه یک درخواستِ API؟ ──────────────────────────────
    اگر فرانت می‌خواست این‌ها را با یک درخواست بگیرد، هر اسکریپتِ تحلیلی یک
    رفت‌وبرگشتِ شبکه دیرتر شروع می‌شد و بخشی از بازدیدها (کاربری که سریع
    صفحه را می‌بندد) اصلاً شمرده نمی‌شد. اینجا مقدارها همراهِ خودِ HTML
    می‌آیند و هزینه‌ی اضافه‌ای ندارند.

    مقدارها از `App\Support\Observability::clientConfig()` می‌آیند که
    اولویتش «پنلِ ادمین ⟶ .env» است — پس تغییرِ شناسه هیچ‌وقت نیاز به تغییرِ
    کد یا بیلدِ دوباره ندارد.

    **هیچ مقدارِ محرمانه‌ای اینجا نمی‌آید**؛ `clientConfig()` فقط چیزهایی را
    برمی‌گرداند که ذاتاً در مرورگر دیده می‌شوند. توکن‌ها و کلیدهای نوشتن
    (`ga4_api_secret`, `sentry_auth_token`) هرگز از سرور خارج نمی‌شوند.

    اگر هیچ سرویسی تنظیم نشده باشد، این تگ اصلاً چاپ نمی‌شود و فرانت با یک
    شرطِ ساده می‌فهمد که کاری برای انجام نیست.
--}}
@php($observabilityConfig = \App\Support\Observability::clientConfig())

@if (! empty($observabilityConfig))
    <script type="application/json" id="observability-config">
        {!! json_encode($observabilityConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
@endif
