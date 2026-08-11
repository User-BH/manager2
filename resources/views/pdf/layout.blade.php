@php use App\Support\Jalali; @endphp
{{--
    قالبِ مشترکِ همه‌ی PDFها (R28).

    ─── چرا این قالب لازم شد ──────────────────────────────────────────────────
    دو PDFِ موجود (فاکتور و تسویه‌حساب) هر کدام ~۲۰ خط CSSِ یکسان را جدا
    داشتند. با افزودنِ چهار سندِ تازه در این مرحله، همان CSS شش بار تکرار
    می‌شد و اولین تغییرِ برند یعنی شش فایل — که یکی‌شان همیشه جا می‌ماند.

    ─── چرا CSS درون‌خطی و نه فایلِ جدا ───────────────────────────────────────
    mPDF فایلِ CSSِ بیرونی را از دیسک می‌خواند و مسیرها در استقرار فرق
    می‌کنند. یک سبکِ درون‌خطی در قالبِ مشترک، هم قابلِ اتکاست و هم همچنان یک
    نقطه‌ی حقیقت دارد.

    ─── شماره‌ی صفحه ──────────────────────────────────────────────────────────
    گزارش‌های چندصفحه‌ای بدونِ «صفحه‌ی ۲ از ۵» عملاً غیرقابلِ بایگانی‌اند؛
    `{PAGENO}` و `{nbpg}` را خودِ mPDF در فوتر جایگزین می‌کند.
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @page { margin: 22mm 14mm 20mm 14mm; }

        body { font-family: vazirmatn, sans-serif; color: #1e293b; font-size: 12px; }

        .header { border-bottom: 2px solid #0284c7; padding-bottom: 10px; margin-bottom: 16px; }
        .title { font-size: 20px; font-weight: bold; color: #0c4a6e; }
        .doc-type { font-size: 15px; font-weight: bold; }
        .muted { color: #64748b; font-size: 11px; }
        .num { text-align: left; }

        table.meta { width: 100%; margin-bottom: 14px; }
        table.meta td { padding: 4px 0; font-size: 12px; }
        table.meta .label { color: #64748b; width: 90px; }

        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.items th { background: #f1f5f9; color: #475569; text-align: right; padding: 8px; font-size: 11px; }
        table.items td { padding: 8px; border-bottom: 1px solid #e2e8f0; }

        /* ردیفِ جمع همیشه باید از بقیه جدا دیده شود، حتی در چاپِ سیاه‌وسفید */
        .totals { margin-top: 14px; width: 100%; }
        .totals td { padding: 5px 8px; font-size: 12px; }
        .grand { font-size: 15px; font-weight: bold; border-top: 2px solid #0284c7; }

        .tag { font-size: 10px; padding: 2px 6px; border-radius: 6px; background: #e2e8f0; color: #475569; }
        .tag-owner { background: #e0f2fe; color: #0369a1; }
        .tag-ok { background: #dcfce7; color: #15803d; }
        .tag-warn { background: #fef3c7; color: #b45309; }
        .tag-bad { background: #fee2e2; color: #b91c1c; }

        .section { margin-top: 18px; }
        .section h2 { font-size: 13px; color: #0c4a6e; margin: 0 0 6px; }

        .footer { color: #94a3b8; font-size: 10px; text-align: center; }
    </style>
</head>
<body>
    {{-- فوترِ تکرارشونده در همه‌ی صفحه‌ها --}}
    <htmlpagefooter name="page">
        <div class="footer">
            {{ $complexName ?? config('brand.name') }}
            — تاریخ تولید: {{ Jalali::dateTime(now()) }}
            — صفحه {{ '{PAGENO}' }} از {{ '{nbpg}' }}
        </div>
    </htmlpagefooter>
    <sethtmlpagefooter name="page" value="on" />

    <div class="header">
        <table style="width:100%"><tr>
            <td>
                <div class="title">{{ $complexName ?? config('brand.name') }}</div>
                @isset($complexAddress)
                    <div class="muted">{{ $complexAddress }}</div>
                @endisset
            </td>
            <td class="num">
                <div class="doc-type">@yield('doc-type')</div>
                @yield('doc-meta')
            </td>
        </tr></table>
    </div>

    @yield('content')
</body>
</html>
