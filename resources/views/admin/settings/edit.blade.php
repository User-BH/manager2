@extends('layouts.app')
@section('title', 'تنظیمات مجتمع')

@section('content')
<div class="mx-auto max-w-2xl space-y-4">
    <h1 class="text-xl font-bold">تنظیمات مجتمع</h1>

    <x-card>
        <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-5">
            @csrf @method('PUT')

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-input name="name" label="نام مجتمع" :value="$complex->name" required />
                <x-input name="phone" label="تلفن" :value="$complex->phone" dir="ltr" />
                <div class="sm:col-span-2"><x-input name="address" label="آدرس" :value="$complex->address" /></div>
                <x-select name="currency" label="واحد پول" :options="['toman' => 'تومان', 'rial' => 'ریال']" :selected="$complex->currency" required />
                <x-input name="charge_due_day" type="number" label="روز مهلت پرداخت (در ماه)" :value="$complex->charge_due_day" required />
            </div>

            <hr class="border-slate-200 dark:border-slate-700">
            <h3 class="font-semibold">درگاه پرداخت</h3>
            <div x-data="{ gw: '{{ $complex->payment_gateway }}' }">
                <x-select name="payment_gateway" label="درگاه فعال"
                    :options="['none' => 'غیرفعال (فقط آپلود رسید)', 'fake' => 'تستی / سندباکس', 'mellat' => 'به‌پرداخت ملت', 'saman' => 'سامان / سپ']"
                    :selected="$complex->payment_gateway" x-model="gw" required />

                @php($gw = $complex->gateway_config ?? [])
                <div class="mt-4 space-y-4" x-show="['mellat','saman'].includes(gw)" x-cloak>
                    <x-input name="gw_terminal_id" label="شناسه ترمینال (Terminal ID)" :value="$gw['terminal_id'] ?? ''" dir="ltr" />
                    <template x-if="gw === 'mellat'">
                        <div class="space-y-4">
                            <x-input name="gw_username" label="نام کاربری درگاه" :value="$gw['username'] ?? ''" dir="ltr" />
                            <x-input name="gw_password" type="password" label="رمز عبور درگاه" :value="$gw['password'] ?? ''" dir="ltr" />
                        </div>
                    </template>
                </div>
            </div>

            <hr class="border-slate-200 dark:border-slate-700">
            <h3 class="font-semibold">جریمه دیرکرد</h3>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="penalty_enabled" value="1" @checked($complex->penalty_enabled) class="rounded border-slate-300 text-sky-600">
                محاسبه جریمه دیرکرد فعال باشد
            </label>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-select name="penalty_type" label="نوع جریمه"
                    :options="['fixed' => 'مبلغ ثابت', 'percent' => 'درصد یک‌باره', 'percent_per_day' => 'درصد روزانه']"
                    :selected="$complex->penalty_type" />
                <x-input name="penalty_value" type="number" step="0.01" label="مقدار جریمه" :value="$complex->penalty_value" required />
                <x-input name="penalty_grace_days" type="number" label="روزهای مهلت (بدون جریمه)" :value="$complex->penalty_grace_days" required />
            </div>

            <hr class="border-slate-200 dark:border-slate-700">
            <h3 class="font-semibold">امکانات</h3>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="messenger_enabled" value="1" @checked($complex->messenger_enabled) class="rounded border-slate-300 text-sky-600">
                پیام‌رسان داخلی فعال باشد
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="good_payer_enabled" value="1" @checked($complex->good_payer_enabled) class="rounded border-slate-300 text-sky-600">
                بخش ساکنین خوش‌حساب فعال باشد
            </label>

            <x-button variant="primary">ذخیره تنظیمات</x-button>
        </form>
    </x-card>
</div>
@endsection
