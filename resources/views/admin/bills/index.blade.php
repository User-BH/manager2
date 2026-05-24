@extends('layouts.app')
@section('title', 'مدیریت قبوض')

@php use App\Support\Jalali; @endphp

@section('content')
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold">قبوض و شارژ — {{ Jalali::periodLabel($period) }}</h1>
        <div class="flex flex-wrap items-center gap-2">
            <form method="GET" class="flex items-center gap-2">
                <select name="period" onchange="this.form.submit()"
                    class="rounded-xl border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900">
                    @foreach ($periods as $val => $label)
                        <option value="{{ $val }}" @selected($val === $period)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
            @if ($bills->isNotEmpty())
                <x-button :href="route('admin.bills.export', ['period' => $period])" variant="ghost">خروجی Excel</x-button>
                <form method="POST" action="{{ route('admin.bills.remind') }}" onsubmit="return confirm('ارسال پیامک یادآوری برای قبوض معوق این دوره؟')">
                    @csrf
                    <input type="hidden" name="period" value="{{ $period }}">
                    <x-button variant="ghost">یادآوری پیامکی</x-button>
                </form>
            @endif
            <form method="POST" action="{{ route('admin.bills.generate') }}" onsubmit="return confirm('صدور/به‌روزرسانی قبوض این دوره؟ قبوض تسویه‌شده تغییر نمی‌کنند.')">
                @csrf
                <input type="hidden" name="period" value="{{ $period }}">
                <x-button variant="primary">صدور قبوض این دوره</x-button>
            </form>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat label="تعداد قبوض" :value="Jalali::digits($bills->count())" tone="sky" />
        <x-stat label="مبلغ کل صادرشده" :value="Jalali::money($total)" tone="amber" />
        <x-stat label="وصول‌شده" :value="Jalali::money($collected)" tone="emerald" />
    </div>

    <x-card>
        @if ($bills->isEmpty())
            <p class="py-8 text-center text-sm text-slate-400">برای این دوره قبضی صادر نشده است. روی «صدور قبوض این دوره» بزنید.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-slate-400">
                        <tr>
                            <th class="pb-2 text-right">واحد</th>
                            <th class="pb-2 text-right">مالکانه</th>
                            <th class="pb-2 text-right">مستاجرانه</th>
                            <th class="pb-2 text-right">جریمه</th>
                            <th class="pb-2 text-right">کل</th>
                            <th class="pb-2 text-right">پرداخت‌شده</th>
                            <th class="pb-2 text-right">وضعیت</th>
                            <th class="pb-2 text-left">فاکتور</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        @foreach ($bills as $bill)
                            <tr>
                                <td class="py-2.5 font-medium">واحد {{ Jalali::digits($bill->unit->unit_number) }}</td>
                                <td class="py-2.5 tabular-nums">{{ Jalali::money($bill->owner_amount) }}</td>
                                <td class="py-2.5 tabular-nums">{{ Jalali::money($bill->tenant_amount) }}</td>
                                <td class="py-2.5 tabular-nums text-rose-500">{{ Jalali::money($bill->penalty_amount) }}</td>
                                <td class="py-2.5 tabular-nums font-semibold">{{ Jalali::money($bill->total_amount) }}</td>
                                <td class="py-2.5 tabular-nums text-emerald-600 dark:text-emerald-400">{{ Jalali::money($bill->paid_amount) }}</td>
                                <td class="py-2.5"><x-badge :color="$bill->status->color()">{{ $bill->status->label() }}</x-badge></td>
                                <td class="py-2.5 text-left"><a href="{{ route('bills.pdf', $bill) }}" class="text-sky-600 hover:underline dark:text-sky-400">PDF</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
</div>
@endsection
