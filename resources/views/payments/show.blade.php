@extends('layouts.app')
@section('title', 'پرداخت صورت‌حساب')

@php use App\Support\Jalali; @endphp
@php($currency = $bill->complex->currencyLabel())

@section('content')
<div class="mx-auto max-w-xl space-y-4">
    <h1 class="text-xl font-bold">پرداخت قبض {{ Jalali::periodLabel($bill->period) }}</h1>

    <x-card>
        <div class="flex items-center justify-between">
            <span class="text-muted">مبلغ قابل پرداخت</span>
            <span class="text-2xl font-bold tabular-nums text-success">{{ Jalali::money($bill->remaining()) }} {{ $currency }}</span>
        </div>
    </x-card>

    @if ($onlineEnabled)
        <x-card title="پرداخت آنلاین" subtitle="اتصال به درگاه بانکی">
            <form method="POST" action="{{ route('payments.online', $bill) }}">
                @csrf
                <x-button variant="primary" class="w-full">پرداخت آنلاین از طریق درگاه</x-button>
            </form>
        </x-card>
    @else
        <div class="tone-warning rounded-xl px-4 py-3 text-sm">
            درگاه پرداخت آنلاین برای این مجتمع فعال نیست. لطفا رسید واریز خود را آپلود کنید.
        </div>
    @endif

    <x-card title="آپلود رسید پرداخت" subtitle="پس از واریز، رسید را برای تایید مدیر بارگذاری کنید">
        <form method="POST" action="{{ route('payments.receipt', $bill) }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <x-input name="amount" type="number" label="مبلغ واریزی ({{ $currency }})" :value="(int) $bill->remaining()" required />
            <x-input name="paid_on" type="date" label="تاریخ واریز" />
            <x-input name="description" label="توضیحات (اختیاری)" />
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-ink">فایل رسید (تصویر یا PDF، حداکثر ۴ مگابایت) <span class="text-danger">*</span></span>
                <input type="file" name="receipt" accept=".jpg,.jpeg,.png,.pdf" required
                    class="w-full rounded-xl border border-line bg-sunken px-3 py-2 text-sm text-ink file:ml-3 file:rounded-lg file:border-0 file:bg-brand-500 file:px-3 file:py-1.5 file:text-white focus-ring" />
                @error('receipt')<span class="mt-1 block text-xs text-danger">{{ $message }}</span>@enderror
            </label>
            <x-button variant="success" class="w-full">ثبت رسید</x-button>
        </form>
    </x-card>
</div>
@endsection
