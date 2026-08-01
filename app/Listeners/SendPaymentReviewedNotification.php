<?php

namespace App\Listeners;

use App\Events\PaymentReviewed;
use App\Notifications\PaymentReviewedNotification;

/**
 * رساندنِ نتیجه‌ی بررسیِ رسید به ساکن.
 *
 * پیش از این، ساکن هیچ خبری نمی‌شد: باید خودش صفحه‌ی پرداخت‌ها را باز می‌کرد
 * و حدس می‌زد که مدیر بررسی کرده یا نه. برای رسیدِ **رد‌شده** این بدتر بود،
 * چون کاربر تا وقتی سراغش نمی‌رفت نمی‌دانست باید دوباره اقدام کند.
 */
class SendPaymentReviewedNotification
{
    public function handle(PaymentReviewed $event): void
    {
        // رسیدی که کاربرش حذف شده (یا پرداختِ سیستمی) گیرنده‌ای ندارد
        $event->payment->user?->notify(
            new PaymentReviewedNotification($event->payment, $event->approved, $event->note),
        );
    }
}
