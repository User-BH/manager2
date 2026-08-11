@php use App\Support\Jalali; @endphp
@extends('pdf.layout', ['complexName' => $complex->name, 'complexAddress' => $complex->address])

{{--
    گزارشِ مالیِ یک دوره (R28).

    اعداد از همان `ReportService` می‌آیند که داشبورد از آن می‌خواند — نه یک
    محاسبه‌ی دوم. اگر گزارشِ چاپی و صفحه‌ی داشبورد دو عدد متفاوت نشان بدهند،
    هیچ‌کدام دیگر قابلِ استناد نیستند.
--}}
@section('title', 'گزارش مالی')
@section('doc-type', 'گزارش مالی')
@section('doc-meta')
    <div class="muted">دوره: {{ Jalali::periodLabel($period) }}</div>
@endsection

@section('content')
    <table class="items">
        <tr>
            <th>درآمد دوره</th>
            <th>هزینه دوره</th>
            <th>تراز دوره</th>
            <th>موجودی صندوق</th>
            <th>مجموع بدهی</th>
        </tr>
        <tr>
            <td>{{ Jalali::money($income) }}</td>
            <td>{{ Jalali::money($expense) }}</td>
            <td>{{ Jalali::money($income - $expense) }}</td>
            <td>{{ Jalali::money($fund) }}</td>
            <td>{{ Jalali::money($totalDebt) }}</td>
        </tr>
    </table>

    <div class="section">
        <h2>هزینه‌های ثبت‌شده</h2>
        @if ($expenses->isEmpty())
            <p class="muted">هزینه‌ای برای این دوره ثبت نشده است.</p>
        @else
            <table class="items">
                <tr><th>شرح</th><th>دسته</th><th>تاریخ</th><th class="num">مبلغ</th></tr>
                @foreach ($expenses as $row)
                    <tr>
                        <td>{{ $row->title }}</td>
                        <td>{{ $row->category->label() }}</td>
                        <td>{{ $row->spend_date ? Jalali::date($row->spend_date) : '—' }}</td>
                        <td class="num">{{ Jalali::money($row->amount) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td colspan="3" style="font-weight:bold">جمع</td>
                    <td class="num" style="font-weight:bold">{{ Jalali::money($expenses->sum('amount')) }}</td>
                </tr>
            </table>
        @endif
    </div>

    <div class="section">
        <h2>واحدهای بدهکار</h2>
        @if ($debtors->isEmpty())
            <p class="muted">هیچ واحدی بدهی ندارد.</p>
        @else
            <table class="items">
                <tr><th>واحد</th><th>طبقه</th><th class="num">بدهی</th></tr>
                @foreach ($debtors as $unit)
                    <tr>
                        <td>{{ $unit->unit_number }}</td>
                        <td>{{ Jalali::digits($unit->floor) }}</td>
                        <td class="num">{{ Jalali::money($unit->balance) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
@endsection
