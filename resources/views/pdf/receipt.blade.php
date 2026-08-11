@php use App\Support\Jalali; @endphp
@extends('pdf.layout', [
    'complexName' => $payment->complex->name,
    'complexAddress' => $payment->complex->address,
])

{{--
    رسیدِ پرداخت (R28).

    ─── چرا رسید جدا از فاکتور لازم بود ───────────────────────────────────────
    فاکتور می‌گوید «چقدر باید بدهی» و رسید می‌گوید «چقدر دادی و کِی». ساکنی
    که واحد را تخلیه می‌کند یا با مالک حساب می‌کند، به دومی نیاز دارد نه
    اولی — و تا امروز هیچ سندی برای آن وجود نداشت؛ فقط یک ردیف روی صفحه.
--}}
@section('title', 'رسید پرداخت')
@section('doc-type', 'رسید پرداخت')

@section('doc-meta')
    <div class="muted">شماره رسید: {{ Jalali::digits($payment->id) }}</div>
    @if ($payment->period)
        <div class="muted">دوره: {{ Jalali::periodLabel($payment->period) }}</div>
    @endif
@endsection

@section('content')
    <table class="meta">
        <tr>
            <td class="label">واحد</td>
            <td>{{ $payment->unit?->label() ?? '—' }}</td>
            <td class="label">پرداخت‌کننده</td>
            <td>{{ $payment->user?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">تاریخ پرداخت</td>
            <td>{{ $payment->paid_at ? Jalali::dateTime($payment->paid_at) : '—' }}</td>
            <td class="label">روش</td>
            <td>{{ $payment->method->label() }}</td>
        </tr>
        <tr>
            <td class="label">وضعیت</td>
            <td>
                <span class="tag {{ $payment->status->value === 'success' ? 'tag-ok' : 'tag-warn' }}">
                    {{ $payment->status->label() }}
                </span>
            </td>
            <td class="label">شناسه پیگیری</td>
            {{--
                کدِ پیگیری با رقمِ لاتین می‌ماند: کاربر آن را در سامانه‌ی بانک
                یا در تماس با پشتیبانی وارد می‌کند و رقمِ فارسی آنجا پذیرفته
                نمی‌شود.
            --}}
            <td dir="ltr" style="text-align:right">{{ $payment->tracking_code ?? $payment->ref_id ?? '—' }}</td>
        </tr>
    </table>

    <table class="totals">
        <tr class="grand">
            <td>مبلغ پرداخت‌شده</td>
            <td class="num">{{ Jalali::money($payment->amount) }} {{ $currency }}</td>
        </tr>
    </table>

    @if ($bill)
        <div class="section">
            <h2>قبض مرتبط</h2>
            <table class="items">
                <tr>
                    <th>دوره</th>
                    <th>مبلغ کل</th>
                    <th>پرداخت‌شده</th>
                    <th>مانده</th>
                </tr>
                <tr>
                    <td>{{ Jalali::periodLabel($bill->period) }}</td>
                    <td>{{ Jalali::money($bill->total_amount) }}</td>
                    <td>{{ Jalali::money($bill->paid_amount) }}</td>
                    <td>{{ Jalali::money($bill->remaining()) }}</td>
                </tr>
            </table>
        </div>
    @endif

    @if ($payment->description)
        <div class="section">
            <h2>توضیحات</h2>
            <p>{{ $payment->description }}</p>
        </div>
    @endif

    <div class="section muted">
        این رسید به‌صورت خودکار تولید شده و نیازی به مهر و امضا ندارد.
    </div>
@endsection
