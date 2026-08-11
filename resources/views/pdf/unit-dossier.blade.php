@php use App\Support\Jalali; @endphp
@extends('pdf.layout', ['complexName' => $complex->name, 'complexAddress' => $complex->address])

{{--
    پرونده‌ی واحد با تاریخچه‌ی مالکیت و سکونت (R28 روی R26).

    این همان سندی است که هنگامِ فروشِ واحد یا تحویل به مالکِ تازه لازم
    می‌شود: چه کسی از کِی تا کِی، و چه مقدار بدهی روی پرونده مانده.
--}}
@section('title', 'پرونده واحد')
@section('doc-type', 'پرونده واحد')
@section('doc-meta')
    <div class="muted">{{ $unit->label() }}</div>
@endsection

@section('content')
    <table class="meta">
        <tr>
            <td class="label">متراژ</td><td>{{ Jalali::digits($unit->area) }} متر</td>
            <td class="label">پارکینگ</td><td>{{ Jalali::digits($unit->parking_count) }}</td>
        </tr>
        <tr>
            <td class="label">انباری</td><td>{{ Jalali::digits($unit->storage_count) }}</td>
            <td class="label">وضعیت</td><td>{{ $unit->occupancy_status->label() }}</td>
        </tr>
        <tr>
            <td class="label">مانده</td><td>{{ Jalali::money($unit->balance) }} {{ $currency }}</td>
            <td class="label">ضریب شارژ</td><td>{{ Jalali::digits($unit->coefficient) }}</td>
        </tr>
    </table>

    <div class="section">
        <h2>مالکیت و سکونت</h2>
        @if ($tenures->isEmpty())
            <p class="muted">هیچ دوره‌ای ثبت نشده است.</p>
        @else
            <table class="items">
                <tr><th>نام</th><th>نسبت</th><th>سهم</th><th>از</th><th>تا</th></tr>
                @foreach ($tenures as $tenure)
                    <tr>
                        <td>{{ $tenure->user?->name ?? 'کاربر حذف‌شده' }}</td>
                        <td>
                            <span class="tag {{ $tenure->relation->value === 'owner' ? 'tag-owner' : '' }}">
                                {{ $tenure->relation->label() }}
                            </span>
                        </td>
                        <td>{{ Jalali::digits($tenure->share_percent) }}٪</td>
                        <td>{{ $tenure->start_date ? Jalali::date($tenure->start_date) : '—' }}</td>
                        {{-- دوره‌ی باز «تا کنون» است، نه تاریخِ نامعلوم --}}
                        <td>{{ $tenure->end_date ? Jalali::date($tenure->end_date) : ($tenure->is_current ? 'کنون' : '—') }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    <div class="section">
        <h2>قبض‌ها</h2>
        @if ($bills->isEmpty())
            <p class="muted">قبضی برای این واحد صادر نشده است.</p>
        @else
            <table class="items">
                <tr><th>دوره</th><th>مبلغ</th><th>پرداخت‌شده</th><th class="num">مانده</th></tr>
                @foreach ($bills as $bill)
                    <tr>
                        <td>{{ Jalali::periodLabel($bill->period) }}</td>
                        <td>{{ Jalali::money($bill->total_amount) }}</td>
                        <td>{{ Jalali::money($bill->paid_amount) }}</td>
                        <td class="num">{{ Jalali::money($bill->remaining()) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
@endsection
