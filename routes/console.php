<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// یادآوری روزانه‌ی قبوض معوق (نیازمند اجرای scheduler سرور: php artisan schedule:work)
Schedule::command('reminders:charges')->dailyAt('10:00');
