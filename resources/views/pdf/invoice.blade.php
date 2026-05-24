@php use App\Support\Jalali; @endphp
@php($currency = $bill->complex->currencyLabel())
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8">
<style>
    body { font-family: vazirmatn, sans-serif; color: #1e293b; font-size: 12px; }
    .header { border-bottom: 2px solid #0284c7; padding-bottom: 10px; margin-bottom: 16px; }
    .title { font-size: 20px; font-weight: bold; color: #0c4a6e; }
    .muted { color: #64748b; font-size: 11px; }
    .meta { width: 100%; margin-bottom: 14px; }
    .meta td { padding: 4px 0; font-size: 12px; }
    .meta .label { color: #64748b; width: 90px; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
    table.items th { background: #f1f5f9; color: #475569; text-align: right; padding: 8px; font-size: 11px; }
    table.items td { padding: 8px; border-bottom: 1px solid #e2e8f0; }
    .num { text-align: left; }
    .tag { font-size: 10px; padding: 2px 6px; border-radius: 6px; background:#e2e8f0; color:#475569; }
    .tag-owner { background:#e0f2fe; color:#0369a1; }
    .totals { margin-top: 14px; width: 100%; }
    .totals td { padding: 5px 8px; font-size: 12px; }
    .grand { font-size: 15px; font-weight: bold; border-top: 2px solid #0284c7; }
    .status { display:inline-block; padding: 4px 10px; border-radius: 8px; font-size: 11px; }
    .footer { margin-top: 24px; color:#94a3b8; font-size:10px; text-align:center; }
</style>
</head>
<body>
    <div class="header">
        <table style="width:100%"><tr>
            <td>
                <div class="title">{{ $bill->complex->name }}</div>
                <div class="muted">{{ $bill->complex->address }}</div>
            </td>
            <td class="num">
                <div style="font-size:15px;font-weight:bold;">فاکتور شارژ</div>
                <div class="muted">دوره: {{ Jalali::periodLabel($bill->period) }}</div>
                <div class="muted">شماره فاکتور: {{ Jalali::digits($bill->id) }}</div>
            </td>
        </tr></table>
    </div>

    <table class="meta">
        <tr>
            <td class="label">واحد</td><td>{{ $bill->unit->label() }}</td>
            <td class="label">متراژ</td><td>{{ Jalali::digits($bill->unit->area) }} متر</td>
        </tr>
        <tr>
            <td class="label">تعداد نفرات</td><td>{{ Jalali::digits($bill->unit->residents_count) }}</td>
            <td class="label">مهلت پرداخت</td><td>{{ Jalali::date($bill->due_date) }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr><th>شرح</th><th style="width:90px">نوع</th><th class="num" style="width:120px">مبلغ ({{ $currency }})</th></tr>
        </thead>
        <tbody>
            @foreach ($bill->breakdown ?? [] as $item)
                <tr>
                    <td>{{ $item['label'] }}</td>
                    <td><span class="tag {{ $item['category'] === 'owner' ? 'tag-owner' : '' }}">{{ $item['category'] === 'owner' ? 'مالکانه' : 'مستاجرانه' }}</span></td>
                    <td class="num">{{ Jalali::money($item['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>سهم مستاجرانه</td><td class="num">{{ Jalali::money($bill->tenant_amount) }}</td></tr>
        <tr><td>سهم مالکانه</td><td class="num">{{ Jalali::money($bill->owner_amount) }}</td></tr>
        @if ($bill->penalty_amount > 0)<tr><td>جریمه دیرکرد</td><td class="num">{{ Jalali::money($bill->penalty_amount) }}</td></tr>@endif
        @if ($bill->discount_amount > 0)<tr><td>تخفیف</td><td class="num">−{{ Jalali::money($bill->discount_amount) }}</td></tr>@endif
        <tr class="grand"><td>مبلغ قابل پرداخت</td><td class="num">{{ Jalali::money($bill->total_amount) }} {{ $currency }}</td></tr>
        <tr><td>پرداخت‌شده</td><td class="num">{{ Jalali::money($bill->paid_amount) }}</td></tr>
        <tr><td>مانده</td><td class="num">{{ Jalali::money($bill->remaining()) }}</td></tr>
    </table>

    <div style="margin-top:12px">
        وضعیت:
        <span class="status" style="background:#f1f5f9">{{ $bill->status->label() }}</span>
    </div>

    <div class="footer">
        این فاکتور توسط سامانه مدیریت ساختمان صادر شده است — {{ Jalali::dateTime(now()) }}
    </div>
</body>
</html>
