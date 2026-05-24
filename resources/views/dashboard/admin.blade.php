@extends('layouts.app')
@section('title', 'داشبورد مدیریت')

@php use App\Support\Jalali; @endphp

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-xl font-bold">داشبورد {{ $complex->name }}</h1>
            <p class="text-sm text-slate-400">دوره جاری: {{ Jalali::periodLabel($period) }}</p>
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
                @foreach (['paid' => ['تسویه‌شده','emerald'], 'partial' => ['جزئی','amber'], 'pending' => ['در انتظار تایید','sky'], 'unpaid' => ['پرداخت‌نشده','rose']] as $key => $meta)
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <span class="h-2.5 w-2.5 rounded-full bg-{{ $meta[1] }}-500"></span>{{ $meta[0] }}
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
                <p class="py-6 text-center text-sm text-slate-400">بدهکاری ثبت نشده است.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="text-xs text-slate-400">
                        <tr><th class="pb-2 text-right">واحد</th><th class="pb-2 text-right">طبقه</th><th class="pb-2 text-left">بدهی ({{ $complex->currencyLabel() }})</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        @foreach ($debtors as $unit)
                            <tr>
                                <td class="py-2">واحد {{ Jalali::digits($unit->unit_number) }}</td>
                                <td class="py-2">{{ Jalali::digits($unit->floor) }}</td>
                                <td class="py-2 text-left font-semibold tabular-nums text-rose-600 dark:text-rose-400">{{ Jalali::money($unit->balance) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-card>

        <x-card title="ساکنین خوش‌حساب" subtitle="پرداخت به‌موقع">
            @if ($goodPayers->isEmpty())
                <p class="py-6 text-center text-sm text-slate-400">هنوز داده‌ای موجود نیست.</p>
            @else
                <div class="space-y-2">
                    @foreach ($goodPayers as $i => $row)
                        <div class="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-700/40">
                            <div class="flex items-center gap-3">
                                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-amber-100 text-xs font-bold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ Jalali::digits($i + 1) }}</span>
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
        new window.Chart(ctx, {
            type: 'bar',
            data: {
                labels: @json($trend['labels']),
                datasets: [
                    { label: 'درآمد', data: @json($trend['income']), backgroundColor: '#10b981', borderRadius: 6 },
                    { label: 'هزینه', data: @json($trend['expense']), backgroundColor: '#f43f5e', borderRadius: 6 },
                ],
            },
            options: {
                responsive: true,
                plugins: { legend: { labels: { font: { family: 'Vazirmatn' } } } },
                scales: {
                    x: { ticks: { font: { family: 'Vazirmatn' } }, grid: { display: false } },
                    y: { ticks: { font: { family: 'Vazirmatn' } } },
                },
            },
        });
    });
</script>
@endsection
