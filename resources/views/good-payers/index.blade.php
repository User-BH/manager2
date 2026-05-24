@extends('layouts.app')
@section('title', 'ساکنین خوش‌حساب')

@php use App\Support\Jalali; @endphp

@section('content')
<div class="mx-auto max-w-3xl space-y-4">
    <h1 class="text-xl font-bold">🏆 ساکنین خوش‌حساب</h1>

    @if (! $enabled)
        <x-card><p class="py-8 text-center text-sm text-slate-400">این بخش توسط مدیر غیرفعال شده است.</p></x-card>
    @elseif ($payers->isEmpty())
        <x-card><p class="py-8 text-center text-sm text-slate-400">هنوز رتبه‌بندی‌ای ثبت نشده است.</p></x-card>
    @else
        <p class="text-sm text-slate-400">رتبه‌بندی بر اساس تعداد پرداخت‌های به‌موقع</p>
        @foreach ($payers as $i => $row)
            <x-card>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 items-center justify-center rounded-full text-lg font-bold
                            {{ $i === 0 ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' }}">
                            {{ Jalali::digits($i + 1) }}
                        </span>
                        <div>
                            <p class="font-semibold">واحد {{ Jalali::digits($row['unit']->unit_number) }}</p>
                            <p class="text-xs text-slate-400">طبقه {{ Jalali::digits($row['unit']->floor) }}</p>
                        </div>
                    </div>
                    <div class="text-left">
                        <x-badge color="emerald">{{ $row['tier'] }}</x-badge>
                        <p class="mt-1 text-xs text-slate-400">{{ Jalali::digits($row['on_time']) }} پرداخت به‌موقع</p>
                    </div>
                </div>
            </x-card>
        @endforeach
    @endif
</div>
@endsection
