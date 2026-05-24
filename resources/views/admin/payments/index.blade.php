@extends('layouts.app')
@section('title', 'بررسی پرداخت‌ها')

@php use App\Support\Jalali; @endphp

@section('content')
<div class="space-y-4">
    <h1 class="text-xl font-bold">بررسی پرداخت‌ها</h1>

    <x-card title="در انتظار تایید" subtitle="رسیدهای آپلودشده توسط ساکنین">
        @if ($pending->isEmpty())
            <p class="py-8 text-center text-sm text-slate-400">پرداخت در انتظار تاییدی وجود ندارد.</p>
        @else
            <div class="space-y-3">
                @foreach ($pending as $p)
                    <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-medium">واحد {{ Jalali::digits($p->unit->unit_number) }} — {{ $p->user?->name }}</p>
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                    مبلغ: <span class="font-semibold tabular-nums">{{ Jalali::money($p->amount) }}</span>
                                    · روش: {{ $p->method->label() }}
                                    · دوره: {{ $p->period ? Jalali::periodLabel($p->period) : '-' }}
                                </p>
                                @if ($p->description)<p class="mt-1 text-xs text-slate-400">توضیح: {{ $p->description }}</p>@endif
                                @if ($p->receipt_paid_on)<p class="mt-1 text-xs text-slate-400">تاریخ واریز: {{ Jalali::date($p->receipt_paid_on) }}</p>@endif
                            </div>
                            <div class="flex items-center gap-2">
                                @if ($p->receipt_path)
                                    <x-button :href="route('admin.payments.receipt', $p)" variant="ghost" type="button" class="!py-1.5" target="_blank">مشاهده رسید</x-button>
                                @endif
                                <form method="POST" action="{{ route('admin.payments.approve', $p) }}">@csrf
                                    <x-button variant="success" class="!py-1.5">تایید</x-button>
                                </form>
                                <form method="POST" action="{{ route('admin.payments.reject', $p) }}" onsubmit="return confirm('رد این رسید؟')">@csrf
                                    <x-button variant="danger" class="!py-1.5">رد</x-button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-card>

    <x-card title="پرداخت‌های اخیر">
        @if ($recent->isEmpty())
            <p class="py-6 text-center text-sm text-slate-400">موردی نیست.</p>
        @else
            <table class="w-full text-sm">
                <thead class="text-xs text-slate-400">
                    <tr><th class="pb-2 text-right">واحد</th><th class="pb-2 text-right">مبلغ</th><th class="pb-2 text-right">روش</th><th class="pb-2 text-right">تاریخ</th><th class="pb-2 text-left">وضعیت</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                    @foreach ($recent as $p)
                        <tr>
                            <td class="py-2.5">واحد {{ Jalali::digits($p->unit->unit_number) }}</td>
                            <td class="py-2.5 tabular-nums">{{ Jalali::money($p->amount) }}</td>
                            <td class="py-2.5">{{ $p->method->label() }}</td>
                            <td class="py-2.5">{{ Jalali::date($p->paid_at ?? $p->created_at) }}</td>
                            <td class="py-2.5 text-left"><x-badge :color="$p->status->color()">{{ $p->status->label() }}</x-badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-card>
</div>
@endsection
