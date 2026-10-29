{{-- صفحه‌ی ورود/ثبت‌نام (island). noindex از config('seo.auth') می‌آید. --}}
@extends('layouts.public')

{{-- Preview یک درِ ورود دارد: نام کاربریِ حساب‌های نمونه (۲۰۲۶-۱۰-۲۹، بندِ ۴)؛ سرور هم ورود با شماره را آن‌جا رد می‌کند --}}
@if (\App\Support\Preview::active())
    @push('head')
        <meta name="login-mode" content="username">
    @endpush
@endif
