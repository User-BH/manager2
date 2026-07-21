@extends('layouts.app')
@section('title', 'جزئیات صورت‌حساب')

@php use App\Support\Jalali; @endphp
@php($currency = $bill->complex->currencyLabel())

@section('content')
<div class="mx-auto max-w-3xl space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold">قبض {{ Jalali::periodLabel($bill->period) }}</h1>
        <div class="flex items-center gap-2">
            <x-button :href="route('bills.pdf', $bill)" variant="ghost" class="!py-1.5">دانلود PDF فاکتور</x-button>
            <x-badge :color="$bill->status->color()">{{ $bill->status->label() }}</x-badge>
        </div>
    </div>

    <x-card>
        <div class="mb-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            <div><p class="text-faint">واحد</p><p class="font-medium">{{ $bill->unit->label() }}</p></div>
            <div><p class="text-faint">مهلت پرداخت</p><p class="font-medium">{{ Jalali::date($bill->due_date) }}</p></div>
            <div><p class="text-faint">مبلغ کل</p><p class="font-medium tabular-nums">{{ Jalali::money($bill->total_amount) }} {{ $currency }}</p></div>
            <div><p class="text-faint">مانده</p><p class="font-medium tabular-nums text-danger">{{ Jalali::money($bill->remaining()) }} {{ $currency }}</p></div>
        </div>

        <h3 class="mb-2 font-semibold">ریز محاسبه</h3>
        <table class="w-full text-sm">
            <thead class="text-xs text-faint">
                <tr><th class="pb-2 text-right">شرح</th><th class="pb-2 text-right">نوع</th><th class="pb-2 text-left">مبلغ ({{ $currency }})</th></tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($bill->breakdown ?? [] as $item)
                    <tr>
                        <td class="py-2">{{ $item['label'] }}</td>
                        <td class="py-2"><x-badge :color="$item['category'] === 'owner' ? 'sky' : 'slate'">{{ $item['category'] === 'owner' ? 'مالکانه' : 'مستاجرانه' }}</x-badge></td>
                        <td class="py-2 text-left tabular-nums {{ $item['amount'] < 0 ? 'text-success' : '' }}">{{ Jalali::money($item['amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-line font-bold">
                    <td class="pt-3" colspan="2">جمع کل</td>
                    <td class="pt-3 text-left tabular-nums">{{ Jalali::money($bill->total_amount) }}</td>
                </tr>
            </tfoot>
        </table>

        <div class="mt-3 flex flex-wrap gap-4 text-xs text-faint">
            <span>سهم مستاجرانه: {{ Jalali::money($bill->tenant_amount) }}</span>
            <span>سهم مالکانه: {{ Jalali::money($bill->owner_amount) }}</span>
            @if ($bill->penalty_amount > 0)<span class="text-danger">جریمه: {{ Jalali::money($bill->penalty_amount) }}</span>@endif
            @if ($bill->discount_amount > 0)<span class="text-success">تخفیف: {{ Jalali::money($bill->discount_amount) }}</span>@endif
        </div>
    </x-card>

    @if ($bill->remaining() > 0 && ! auth()->user()->isAdmin())
        <x-button :href="route('payments.show', $bill)" variant="success" class="w-full">پرداخت {{ Jalali::money($bill->remaining()) }} {{ $currency }}</x-button>
    @endif

    @if ($bill->payments->isNotEmpty())
        <x-card title="تاریخچه پرداخت این قبض">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-line">
                    @foreach ($bill->payments as $p)
                        <tr>
                            <td class="py-2">{{ $p->method->label() }}</td>
                            <td class="py-2 tabular-nums">{{ Jalali::money($p->amount) }}</td>
                            <td class="py-2">{{ Jalali::date($p->created_at) }}</td>
                            <td class="py-2 text-left"><x-badge :color="$p->status->color()">{{ $p->status->label() }}</x-badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>
    @endif
</div>
@endsection
