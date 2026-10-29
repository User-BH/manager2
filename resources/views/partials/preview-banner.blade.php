{{--
    نوارِ «Preview» (مرحلهٔ ۹ · فاز ۸) — روی هر صفحه‌ی محیطِ آزمایشی، هیچ‌جای محصول.

    در Blade است نه React: باید پیش از بالاآمدنِ جاوااسکریپت و روی صفحه‌های بی‌React
    (خطا، آفلاین، هدایت به درگاه) هم دیده شود. `pointer-events: none` تا روی چیزی
    کلیک‌پذیر نیفتد؛ رنگ‌ها ثابت‌اند تا با هیچ تمی با محصول اشتباه نشود.
--}}
@if (\App\Support\Preview::active())
    <div id="preview-banner" role="status"
         style="position:fixed;inset-inline:0;bottom:0;z-index:2147483647;pointer-events:none;
                padding:4px 12px;text-align:center;font:600 12px/1.6 Vazirmatn,Tahoma,sans-serif;
                color:#1f1300;background:repeating-linear-gradient(135deg,#fbbf24 0 14px,#f59e0b 14px 28px);
                box-shadow:0 -1px 0 rgba(0,0,0,.25)">
        محیطِ آزمایشیِ Preview — داده‌ها نمونه‌اند، پیامک و پرداختِ واقعی خاموش است.
        @if ($commit = \App\Support\Preview::deployedCommit())
            <span dir="ltr" data-testid="preview-commit">· {{ $commit }}</span>
        @endif
    </div>
@endif
