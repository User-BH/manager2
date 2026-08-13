<?php

return [

    /*
    |--------------------------------------------------------------------------
    | صفحه‌های آغازینِ iOS
    |--------------------------------------------------------------------------
    |
    | سافاری splash را از manifest نمی‌سازد و برای هر اندازه‌ی صفحه یک فایلِ
    | جدا با media query می‌خواهد. اندازه‌ها بر حسبِ **پیکسلِ CSS**اند (نه
    | پیکسلِ فیزیکی) و `ratio` همان `devicePixelRatio` است؛ ابعادِ فایل
    | حاصل‌ضربِ این دو است.
    |
    | ⚠️ فهرست عمداً کوتاه است. برای هر ابعادِ تازه فقط یک سطر اینجا و یک
    | اجرای `php artisan pwa:splash` لازم است؛ اگر دستگاهی جور نشود، iOS
    | صفحه‌ی سفید نشان می‌دهد نه خطا — یعنی بی‌صدا خراب می‌شود.
    |
    */

    'splash' => [
        // iPhone SE / 8 / 7 / 6s
        ['width' => 375, 'height' => 667, 'ratio' => 2, 'href' => '/icons/splash/750x1334.png'],
        // iPhone 8 Plus
        ['width' => 414, 'height' => 736, 'ratio' => 3, 'href' => '/icons/splash/1242x2208.png'],
        // iPhone X / XS / 11 Pro / 12 mini / 13 mini
        ['width' => 375, 'height' => 812, 'ratio' => 3, 'href' => '/icons/splash/1125x2436.png'],
        // iPhone XR / 11
        ['width' => 414, 'height' => 896, 'ratio' => 2, 'href' => '/icons/splash/828x1792.png'],
        // iPhone XS Max / 11 Pro Max
        ['width' => 414, 'height' => 896, 'ratio' => 3, 'href' => '/icons/splash/1242x2688.png'],
        // iPhone 12 / 13 / 14 / 16e
        ['width' => 390, 'height' => 844, 'ratio' => 3, 'href' => '/icons/splash/1170x2532.png'],
        // iPhone 14 Pro / 15 / 16
        ['width' => 393, 'height' => 852, 'ratio' => 3, 'href' => '/icons/splash/1179x2556.png'],
        // iPhone 12/13/14 Pro Max
        ['width' => 428, 'height' => 926, 'ratio' => 3, 'href' => '/icons/splash/1284x2778.png'],
        // iPhone 14 Pro Max / 15 Plus / 16 Plus
        ['width' => 430, 'height' => 932, 'ratio' => 3, 'href' => '/icons/splash/1290x2796.png'],
        // iPad / iPad mini
        ['width' => 768, 'height' => 1024, 'ratio' => 2, 'href' => '/icons/splash/1536x2048.png'],
        // iPad Pro 11"
        ['width' => 834, 'height' => 1194, 'ratio' => 2, 'href' => '/icons/splash/1668x2388.png'],
        // iPad Pro 12.9"
        ['width' => 1024, 'height' => 1366, 'ratio' => 2, 'href' => '/icons/splash/2048x2732.png'],
    ],

    /*
    |--------------------------------------------------------------------------
    | رنگ‌های صفحه‌ی آغازین
    |--------------------------------------------------------------------------
    |
    | باید با `background_color`ِ manifest یکی باشد، وگرنه لحظه‌ی گذار از
    | splash به خودِ برنامه یک پرشِ رنگی دیده می‌شود.
    |
    */

    'splash_background' => '#0f1411',

];
