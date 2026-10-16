<?php

return [

    /*
    |--------------------------------------------------------------------------
    | درگاه پرداخت اشتراک
    |--------------------------------------------------------------------------
    |
    | این درگاه با درگاه هر مجتمع فرق دارد و عمداً جداست: پول شارژ به حساب
    | مجتمع می‌رود، ولی پول اشتراک به حساب سرویس‌دهنده. اگر همان درگاه مجتمع
    | استفاده می‌شد، مدیر عملاً به حساب خودش واریز می‌کرد.
    |
    | مقادیر ممکن: sandbox (تست، بدون بانک واقعی) یا خالی/null یعنی خرید
    | آنلاین غیرفعال است و کاربر پیام روشن می‌بیند به‌جای خطای مبهم.
    |
    */

    'gateway' => env('SUBSCRIPTION_GATEWAY'),

    'config' => [
        'terminal_id' => env('SUBSCRIPTION_GATEWAY_TERMINAL'),
        'username' => env('SUBSCRIPTION_GATEWAY_USERNAME'),
        'password' => env('SUBSCRIPTION_GATEWAY_PASSWORD'),
        // زرین‌پال (مرحلهٔ ۹ · فاز ۴): شناسه‌ی پذیرنده و حالتِ sandbox ِ خودِ زرین‌پال
        'merchant_id' => env('SUBSCRIPTION_GATEWAY_MERCHANT'),
        'sandbox' => (bool) env('SUBSCRIPTION_GATEWAY_SANDBOX', false),
    ],

    /** شماره‌ی پشتیبانی که در پیام «درگاه فعال نیست» نشان داده می‌شود. */
    'support_phone' => env('SUBSCRIPTION_SUPPORT_PHONE', '۰۲۱-۹۱۰۰۰۰۰۰'),

];
