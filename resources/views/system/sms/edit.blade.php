@extends('layouts.app')
@section('title', 'تنظیمات پنل پیامک')

@section('content')
<div class="mx-auto max-w-2xl space-y-4">
    <h1 class="text-xl font-bold">پنل پیامک (ورود با کد)</h1>
    <p class="text-sm text-faint">سامانه‌ی پیامکی خود را انتخاب و اطلاعات آن را وارد کنید. این تنظیمات برای ارسال کد ورود کاربران استفاده می‌شود.</p>

    <x-card>
        <form method="POST" action="{{ route('system.sms.update') }}" class="space-y-4" x-data="{ driver: '{{ $driver }}' }">
            @csrf @method('PUT')

            <x-select name="sms_driver" label="سامانه پیامکی" :options="$drivers" :selected="$driver" x-model="driver" required />

            <template x-if="driver === 'log'">
                <div class="tone-warning rounded-xl px-4 py-3 text-sm">
                    در حالت تست، پیامک واقعی ارسال نمی‌شود و کد ورود در فایل لاگ (و صفحه‌ی ورود) نمایش داده می‌شود. برای محیط واقعی یکی از سامانه‌ها را انتخاب کنید.
                </div>
            </template>

            <template x-if="['kavenegar','ippanel'].includes(driver)">
                <div class="space-y-4">
                    <x-input name="apikey" label="کلید API (API Key)" :value="$config['apikey'] ?? ''" dir="ltr" />
                    <x-input name="sender" label="شماره خط ارسال (Sender)" :value="$config['sender'] ?? ''" dir="ltr" />
                </div>
            </template>

            <template x-if="driver === 'melipayamak'">
                <div class="space-y-4">
                    <x-input name="username" label="نام کاربری" :value="$config['username'] ?? ''" dir="ltr" />
                    <x-input name="password" type="password" label="رمز عبور وب‌سرویس" :value="$config['password'] ?? ''" dir="ltr" />
                    <x-input name="sender" label="شماره خط ارسال" :value="$config['sender'] ?? ''" dir="ltr" />
                </div>
            </template>

            <x-button variant="primary">ذخیره تنظیمات</x-button>
        </form>
    </x-card>

    <x-card title="ارسال پیام آزمایشی" subtitle="برای اطمینان از اتصال پنل">
        <form method="POST" action="{{ route('system.sms.test') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div class="flex-1 min-w-48">
                <x-input name="phone" label="شماره تلفن مقصد" dir="ltr" placeholder="09xxxxxxxxx" required />
            </div>
            <x-button variant="ghost">ارسال آزمایشی</x-button>
        </form>
    </x-card>
</div>
@endsection
