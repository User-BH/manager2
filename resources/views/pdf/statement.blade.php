@php use App\Support\Jalali; @endphp
@php($currency = $complex->currencyLabel())
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8">
<style>
    body { font-family: vazirmatn, sans-serif; color: #1e293b; font-size: 12px; }
    .header { border-bottom: 2px solid #0284c7; padding-bottom: 10px; margin-bottom: 16px; }
    .title { font-size: 18px; font-weight: bold; color: #0c4a6e; }
    .muted { color: #64748b; font-size: 11px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th { background: #f1f5f9; color: #475569; text-align: right; padding: 7px; font-size: 11px; }
    td { padding: 7px; border-bottom: 1px solid #e2e8f0; }
    .num { text-align: left; }
    .summary { margin-top: 14px; font-size: 14px; font-weight: bold; }
    .debt { color: #be123c; }
    .footer { margin-top: 24px; color:#94a3b8; font-size:10px; text-align:center; }
</style></head>
<body>
    <div class="header">
        <div class="title">گزارش تسویه‌حساب واحد {{ $unit->unit_number }}</div>
        <div class="muted">{{ $complex->name }} — طبقه {{ $unit->floor }} — متراژ {{ $unit->area }} متر</div>
        <div class="muted">
            مالک: {{ $unit->owners->pluck('name')->join('، ') ?: '-' }}
            | مستاجر: {{ $unit->tenants->pluck('name')->join('، ') ?: '-' }}
        </div>
    </div>

    <h3>صورت‌حساب‌ها</h3>
    <table>
        <thead><tr><th>دوره</th><th class="num">مبلغ کل</th><th class="num">پرداخت‌شده</th><th class="num">مانده</th><th>وضعیت</th></tr></thead>
        <tbody>
            @foreach ($bills as $bill)
                <tr>
                    <td>{{ Jalali::periodLabel($bill->period) }}</td>
                    <td class="num">{{ Jalali::money($bill->total_amount) }}</td>
                    <td class="num">{{ Jalali::money($bill->paid_amount) }}</td>
                    <td class="num">{{ Jalali::money($bill->remaining()) }}</td>
                    <td>{{ $bill->status->label() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="summary">بدهی کل قابل تسویه: <span class="debt">{{ Jalali::money($totalDebt) }} {{ $currency }}</span></p>

    <div class="footer">صادرشده توسط سامانه مدیریت ساختمان — {{ Jalali::dateTime(now()) }}</div>
</body>
</html>
