@extends('layouts.app')
@section('title', 'تسویه‌حساب واحد')

@php use App\Support\Jalali; @endphp
@php($currency = $complex->currencyLabel())

@section('content')
<div class="mx-auto max-w-4xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-xl font-bold">تسویه‌حساب {{ $unit->label() }}</h1>
        <x-button :href="route('admin.units.statement.pdf', $unit)" variant="ghost">دانلود PDF</x-button>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat label="مالک" :value="$unit->owners->pluck('name')->join('، ') ?: '-'" />
        <x-stat label="مستاجر" :value="$unit->tenants->pluck('name')->join('، ') ?: '-'" />
        <x-stat label="بدهی کل" :value="Jalali::money($totalDebt)" :unit="$currency" :tone="$totalDebt > 0 ? 'rose' : 'emerald'" />
    </div>

    <x-card title="صورت‌حساب‌ها">
        <table class="w-full text-sm">
            <thead class="text-xs text-faint">
                <tr><th class="pb-2 text-right">دوره</th><th class="pb-2 text-right">مبلغ کل</th><th class="pb-2 text-right">پرداخت‌شده</th><th class="pb-2 text-right">مانده</th><th class="pb-2 text-left">وضعیت</th></tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($bills as $bill)
                    <tr>
                        <td class="py-2.5">{{ Jalali::periodLabel($bill->period) }}</td>
                        <td class="py-2.5 tabular-nums">{{ Jalali::money($bill->total_amount) }}</td>
                        <td class="py-2.5 tabular-nums text-success">{{ Jalali::money($bill->paid_amount) }}</td>
                        <td class="py-2.5 tabular-nums text-danger">{{ Jalali::money($bill->remaining()) }}</td>
                        <td class="py-2.5 text-left"><x-badge :color="$bill->status->color()">{{ $bill->status->label() }}</x-badge></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-card>

    <x-card title="پرداخت‌های موفق">
        @if ($payments->isEmpty())
            <p class="py-6 text-center text-sm text-faint">پرداختی ثبت نشده است.</p>
        @else
            <table class="w-full text-sm">
                <tbody class="divide-y divide-line">
                    @foreach ($payments as $p)
                        <tr>
                            <td class="py-2">{{ Jalali::date($p->paid_at ?? $p->created_at) }}</td>
                            <td class="py-2">{{ $p->method->label() }}</td>
                            <td class="py-2 tabular-nums">{{ Jalali::money($p->amount) }}</td>
                            <td class="py-2 text-left text-xs text-faint" dir="ltr">{{ $p->tracking_code ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-card>
</div>
@endsection
