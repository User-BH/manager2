@extends('layouts.app')
@section('title', 'صورت‌حساب‌های من')

@php use App\Support\Jalali; @endphp

@section('content')
<div class="space-y-4">
    <h1 class="text-xl font-bold">صورت‌حساب‌های من</h1>

    <x-card>
        @if ($bills->isEmpty())
            <p class="py-8 text-center text-sm text-slate-400">قبضی برای شما صادر نشده است.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-slate-400">
                        <tr>
                            <th class="pb-2 text-right">دوره</th>
                            <th class="pb-2 text-right">واحد</th>
                            <th class="pb-2 text-right">مبلغ کل</th>
                            <th class="pb-2 text-right">پرداخت‌شده</th>
                            <th class="pb-2 text-right">وضعیت</th>
                            <th class="pb-2 text-left">عملیات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        @foreach ($bills as $bill)
                            <tr>
                                <td class="py-3">{{ Jalali::periodLabel($bill->period) }}</td>
                                <td class="py-3">{{ Jalali::digits($bill->unit->unit_number) }}</td>
                                <td class="py-3 tabular-nums font-medium">{{ Jalali::money($bill->total_amount) }}</td>
                                <td class="py-3 tabular-nums text-emerald-600 dark:text-emerald-400">{{ Jalali::money($bill->paid_amount) }}</td>
                                <td class="py-3"><x-badge :color="$bill->status->color()">{{ $bill->status->label() }}</x-badge></td>
                                <td class="py-3 text-left">
                                    <a href="{{ route('bills.show', $bill) }}" class="text-sky-600 hover:underline dark:text-sky-400">جزئیات</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $bills->links() }}</div>
        @endif
    </x-card>
</div>
@endsection
