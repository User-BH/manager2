<?php

namespace App\Enums;

/**
 * انواعِ اعلانی که کاربر می‌تواند خاموش کند (R27).
 *
 * ─── چرا فهرستِ بسته و نه رشته‌ی آزاد ───────────────────────────────────────
 * تنظیماتِ کاربر با کلیدِ رشته‌ای ذخیره می‌شود؛ اگر کلید از enum نیاید، یک
 * غلطِ تایپی در یک صداکردن یعنی «این کاربر خاموشش کرده» به‌جای «روشن است»
 * — و کسی خبردار نمی‌شود. با enum، همان غلط خطای زمانِ اجرا می‌دهد.
 */
enum NotificationChannelKey: string
{
    case BillDue = 'bill.due';
    case PaymentReviewed = 'payment.reviewed';
    case ServiceRequest = 'service_request';
    case Announcement = 'announcement';

    /**
     * یادآوریِ پیامکیِ شارژ.
     *
     * جدا از `BillDue` است چون کانالش فرق دارد: آن اعلانِ درون‌برنامه‌ای
     * است و این پیامکِ واقعی که برای ساکن هزینه‌ی مزاحمت دارد. کسی که
     * زنگوله را خاموش می‌کند لزوماً نمی‌خواهد پیامک هم قطع شود، و برعکس.
     */
    case SmsReminder = 'sms.reminder';

    public function label(): string
    {
        return match ($this) {
            self::BillDue => 'یادآوری سررسید قبض',
            self::PaymentReviewed => 'نتیجه‌ی بررسی رسید پرداخت',
            self::ServiceRequest => 'به‌روزرسانی درخواست‌ها',
            self::Announcement => 'اطلاعیه‌های مجتمع',
            self::SmsReminder => 'پیامک یادآوری شارژ',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::BillDue => 'وقتی مهلت پرداخت قبض نزدیک می‌شود.',
            self::PaymentReviewed => 'وقتی مدیر رسید شما را تایید یا رد می‌کند.',
            self::ServiceRequest => 'وقتی وضعیت درخواست شما عوض می‌شود یا پیامی می‌آید.',
            self::Announcement => 'اطلاعیه‌های عمومی و شمارنده‌ی زنگوله.',
            self::SmsReminder => 'حداکثر ماهی یک پیامک از مدیر مجتمع، فقط اگر بدهی داشته باشید.',
        };
    }

    /** آیا این کانال پیامک است؟ بقیه همه درون‌برنامه‌ای‌اند. */
    public function isSms(): bool
    {
        return $this === self::SmsReminder;
    }

    /** @return array<int, array{value: string, label: string, description: string, isSms: bool}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => [
            'value' => $c->value,
            'label' => $c->label(),
            'description' => $c->description(),
            'isSms' => $c->isSms(),
        ], self::cases());
    }
}
