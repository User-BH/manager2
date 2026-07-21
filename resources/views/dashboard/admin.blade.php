@extends('layouts.app')
@section('title', 'داشبورد مدیریت')

@php use App\Support\Jalali; @endphp

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-xl font-bold">داشبورد {{ $complex->name }}</h1>
            <p class="text-sm text-faint">دوره جاری: {{ Jalali::periodLabel($period) }}</p>
        </div>
        <x-button :href="route('admin.bills.index')" variant="ghost">مدیریت قبوض</x-button>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="درآمد این ماه" :value="Jalali::money($monthlyIncome)" :unit="$complex->currencyLabel()" tone="emerald" />
        <x-stat label="هزینه این ماه" :value="Jalali::money($monthlyExpense)" :unit="$complex->currencyLabel()" tone="rose" />
        <x-stat label="مانده صندوق" :value="Jalali::money($fundBalance)" :unit="$complex->currencyLabel()" tone="sky" />
        <x-stat label="بدهی کل ساکنین" :value="Jalali::money($totalDebt)" :unit="$complex->currencyLabel()" tone="amber" />
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <x-card title="روند درآمد و هزینه" subtitle="۶ ماه اخیر" class="lg:col-span-2">
            <canvas id="trendChart" height="120"></canvas>
        </x-card>

        <x-card title="وضعیت پرداخت دوره جاری">
            <div class="space-y-3">
                {{-- کلاس رنگ نقطه باید رشتهٔ کامل باشد، نه ساخته‌شده با درج مقدار،
                     وگرنه اسکنر Tailwind آن را پیدا نمی‌کند و نقطه بی‌رنگ می‌ماند. --}}
                @foreach ([
                    'paid' => ['تسویه‌شده', 'bg-success'],
                    'partial' => ['جزئی', 'bg-warning'],
                    'pending' => ['در انتظار تایید', 'bg-info'],
                    'unpaid' => ['پرداخت‌نشده', 'bg-danger'],
                ] as $key => $meta)
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-2 text-sm text-muted">
                            <span class="h-2.5 w-2.5 rounded-full {{ $meta[1] }}"></span>{{ $meta[0] }}
                        </span>
                        <span class="font-semibold tabular-nums">{{ Jalali::digits($statusCounts[$key]) }}</span>
                    </div>
                @endforeach
            </div>
        </x-card>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-card title="بدهکاران" subtitle="بیشترین بدهی">
            @if ($debtors->isEmpty())
                <p class="py-6 text-center text-sm text-faint">بدهکاری ثبت نشده است.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="text-xs text-faint">
                        <tr><th class="pb-2 text-right">واحد</th><th class="pb-2 text-right">طبقه</th><th class="pb-2 text-left">بدهی ({{ $complex->currencyLabel() }})</th></tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($debtors as $unit)
                            <tr>
                                <td class="py-2">واحد {{ Jalali::digits($unit->unit_number) }}</td>
                                <td class="py-2">{{ Jalali::digits($unit->floor) }}</td>
                                <td class="py-2 text-left font-semibold tabular-nums text-danger">{{ Jalali::money($unit->balance) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-card>

        <x-card title="ساکنین خوش‌حساب" subtitle="پرداخت به‌موقع">
            @if ($goodPayers->isEmpty())
                <p class="py-6 text-center text-sm text-faint">هنوز داده‌ای موجود نیست.</p>
            @else
                <div class="space-y-2">
                    @foreach ($goodPayers as $i => $row)
                        <div class="flex items-center justify-between rounded-xl bg-sunken px-3 py-2">
                            <div class="flex items-center gap-3">
                                <span class="tone-warning flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold">{{ Jalali::digits($i + 1) }}</span>
                                <span class="text-sm">واحد {{ Jalali::digits($row['unit']->unit_number) }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-badge color="emerald">{{ Jalali::digits($row['on_time']) }} پرداخت به‌موقع</x-badge>
                                <x-badge color="amber">{{ $row['tier'] }}</x-badge>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-card>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const ctx = document.getElementById('trendChart');
        if (!ctx || !window.Chart) return;

        // رنگ‌ها از همان توکن‌های طراحی خوانده می‌شوند تا نمودار با تم روشن/تاریک
        // و پالت برند هماهنگ بماند. فونت و رنگ متن در app.js تنظیم شده است.
        const token = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

        new window.Chart(ctx, {
            type: 'bar',
            data: {
                labels: @json($trend['labels']),
                datasets: [
                    { label: 'درآمد', data: @json($trend['income']), backgroundColor: token('--color-brand-400'), borderRadius: 6 },
                    { label: 'هزینه', data: @json($trend['expense']), backgroundColor: token('--color-accent-500'), borderRadius: 6 },
                ],
            },
            options: {
                responsive: true,
                plugins: { legend: { labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8 } } },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: token('--border-subtle') }, border: { display: false } },
                },
            },
        });
    });
</script>
@endsection
