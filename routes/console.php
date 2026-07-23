<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// یادآوری روزانه‌ی قبوض معوق (نیازمند اجرای scheduler سرور: php artisan schedule:work)
Schedule::command('reminders:charges')->dailyAt('10:00');

// اشتراک‌های سررسیدشده را منقضی و تراکنش‌های آنلاینِ رهاشده را می‌بندد
Schedule::command('subscriptions:maintain')->dailyAt('02:00');

// فایل‌های رسیدی که رکوردشان حذف شده (مثلاً با حذف آبشاریِ واحد) جمع می‌شوند
Schedule::command('receipts:prune')->weeklyOn(5, '03:00');

/*
| بکاپ‌ها سیاست نگه‌داری نداشتند و برای همیشه روی دیسک می‌ماندند. هر فایل یک
| کپی کامل از داده‌ی ساکنین است، پس انباشتنشان هم مسئله‌ی فضاست و هم حریم
| خصوصی. ده نسخه‌ی آخرِ هر مجتمع (و ده نسخه‌ی سیستمی) نگه داشته می‌شود.
*/
Schedule::command('backups:prune --keep=10')->weeklyOn(5, '03:30');

// دستگاه‌های مورداعتمادِ منقضی‌شده‌ی «مرا به خاطر بسپار»
Schedule::command('trusted-devices:prune')->dailyAt('03:45');
