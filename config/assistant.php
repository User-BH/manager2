<?php

use App\Services\Assistant\Tools;

/*
 * دستیارِ داخلیِ داشبورد (مرحلهٔ ۱۳ · فاز ۸؛ ایرادات ۱۱، بخشِ ۴) — شرح در docs/ASSISTANT.md.
 *
 * هسته‌ی پاسخ قطعی است: خواندنِ مجوزدار از منابعِ canonical و محاسبه‌ی دقیق، بی هیچ سرویسِ بیرونی. مدلِ زبانی
 * (اگر روزی پس از سیاهه‌ی سخت‌افزار نصب شود) فقط متنِ پاسخِ ساخته‌شده را بازنویسی می‌کند و هر عددی که خودش بسازد رد
 * می‌شود (`AnswerValidator`).
 */
return [
    /*
     * ماژول‌های داده‌ایِ متصل. ماژولِ تازه = یک کلاس با توصیفِ ساختاریافته (`ToolDescriptor`) که این‌جا افزوده و
     * آزموده می‌شود؛ ثبتِ نام به‌تنهایی دسترسی نمی‌دهد — هر ابزار مجوزِ خودش را در هر پرسش می‌سنجد (بندِ ۴.۷).
     */
    'tools' => [
        Tools\ComplexInfoTool::class,
        Tools\ComplexManagersTool::class,
        Tools\UnitInfoTool::class,
        Tools\UnitPeopleTool::class,
        Tools\PersonLookupTool::class,
        Tools\PaymentsTool::class,
        Tools\ExpensesTool::class,
        Tools\IncomesTool::class,
        Tools\FundTool::class,
        Tools\DebtTool::class,
        Tools\WalletTool::class,
        Tools\NotificationsTool::class,
        Tools\MembershipRequestsTool::class,
        Tools\ServiceRequestsTool::class,
        Tools\MessengerTool::class,
        Tools\AnnouncementsTool::class,
        Tools\CalendarTool::class,
    ],

    /*
     * بازنویسیِ اختیاری با مدلِ **محلی** — پیش‌فرض خاموش (`none`). نصب و انتخابِ مدل پس از سیاهه‌ی سخت‌افزارِ سرور
     * است (تصمیمِ ۲.۳-۶)؛ نشانیِ غیرمحلی پذیرفته نمی‌شود (`LocalModelPhraser`).
     */
    'model' => [
        'driver' => env('ASSISTANT_MODEL', 'none'),
        'url' => env('ASSISTANT_MODEL_URL'),
        'name' => env('ASSISTANT_MODEL_NAME'),
        'timeout' => (int) env('ASSISTANT_MODEL_TIMEOUT', 8),
    ],

    // نگه‌داریِ تاریخچه: هر حساب و هر زمینه جدا؛ کهنه‌تر از این پاک می‌شود (`assistant:prune`)
    'history' => [
        'keep_days' => 30,
        'shown' => 40,
    ],

    // فقط داده‌ی ساختاریِ غیرحساس (مشخصاتِ مجتمع و واحد، مدیران) و فقط کوتاه؛ مالی هرگز کش نمی‌شود
    'cache_seconds' => 60,

    'max_question_length' => 300,
];
