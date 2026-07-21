@extends('layouts.app')
@section('title', 'داشبورد')

@php use App\Support\Jalali; @endphp

@section('content')
<div class="space-y-6">
    <h1 class="text-xl font-bold">سلام {{ auth()->user()->name }} 👋</h1>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat label="تعداد واحدهای شما" :value="Jalali::digits($units->count())" tone="sky" />
        <x-stat label="بدهی فعلی" :value="Jalali::money($totalDebt)" :unit="auth()->user()->complex?->currencyLabel() ?? 'تومان'" :tone="$totalDebt > 0 ? 'rose' : 'emerald'" />
        <x-stat label="قبوض دوره جاری" :value="Jalali::digits($currentBills->count())" tone="amber" />
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-card title="واحدها و صورت‌حساب‌ها">
            @forelse ($units as $unit)
                <div class="mb-3 rounded-xl border border-line p-3">
                    <div class="mb-2 flex items-center justify-between">
                        <span class="font-semibold">{{ $unit->label() }}</span>
                        <x-badge :color="$unit->balance > 0 ? 'rose' : 'emerald'">
                            بدهی: {{ Jalali::money($unit->balance) }}
                        </x-badge>
                    </div>
                    @php($latest = $unit->bills->first())
                    @if ($latest)
                        <div class="flex items-center justify-between text-sm text-muted">
                            <span>آخرین قبض: {{ Jalali::periodLabel($latest->period) }} — {{ Jalali::money($latest->total_amount) }}</span>
                            <a href="{{ route('bills.show', $latest) }}" class="text-brand-500 dark:text-brand-300 hover:underline">جزئیات</a>
                        </div>
                    @else
                        <p class="text-sm text-faint">قبضی صادر نشده است.</p>
                    @endif
                </div>
            @empty
                <p class="py-6 text-center text-sm text-faint">واحدی به شما اختصاص نیافته است.</p>
            @endforelse
            <x-button :href="route('bills.index')" variant="ghost" class="mt-2 w-full">مشاهده همه صورت‌حساب‌ها</x-button>
        </x-card>

        <x-card title="اطلاعیه‌ها">
            @forelse ($announcements as $a)
                <div class="mb-3 border-b border-line pb-3 last:border-0">
                    <div class="flex items-center gap-2">
                        @if ($a->is_pinned)<x-badge color="amber">سنجاق</x-badge>@endif
                        <span class="font-semibold">{{ $a->title }}</span>
                    </div>
                    <p class="mt-1 line-clamp-2 text-sm text-muted">{{ $a->body }}</p>
                    <p class="mt-1 text-xs text-faint">{{ Jalali::date($a->published_at) }}</p>
                </div>
            @empty
                <p class="py-6 text-center text-sm text-faint">اطلاعیه‌ای موجود نیست.</p>
            @endforelse
        </x-card>
    </div>
</div>
@endsection
