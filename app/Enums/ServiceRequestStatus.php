<?php

namespace App\Enums;

/**
 * چرخه‌ی زندگیِ یک درخواستِ ساکن (R25).
 *
 * ─── چرا «انجام شد» پایانِ کار نیست ─────────────────────────────────────────
 * متداول‌ترین شکستِ این‌جور سامانه‌ها این است: مسئول درخواست را «انجام شد»
 * می‌زند، ساکن می‌بیند مشکل هنوز هست، و چون راهی برای اعتراض ندارد یک
 * درخواستِ تازه می‌سازد. آن‌وقت آمارِ مدیر پر از «انجام‌شده»هایی است که هیچ
 * کدام حل نشده‌اند.
 *
 * پس `Resolved` یعنی «مسئول می‌گوید انجام شد» و `Closed` یعنی «ساکن تایید
 * کرد». ساکن می‌تواند به‌جای تایید، درخواست را دوباره باز کند.
 */
enum ServiceRequestStatus: string
{
    case New = 'new';                 // ثبت شده، هنوز کسی برنداشته
    case InProgress = 'in_progress';  // در حال پیگیری
    case Resolved = 'resolved';       // مسئول می‌گوید انجام شد
    case Closed = 'closed';           // ساکن تایید کرد
    case Rejected = 'rejected';       // رد شد، با دلیل

    public function label(): string
    {
        return match ($this) {
            self::New => 'ثبت‌شده',
            self::InProgress => 'در حال پیگیری',
            self::Resolved => 'انجام شد (در انتظار تایید)',
            self::Closed => 'بسته‌شده',
            self::Rejected => 'رد شده',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'sky',
            self::InProgress => 'amber',
            self::Resolved => 'violet',
            self::Closed => 'emerald',
            self::Rejected => 'rose',
        };
    }

    /** درخواستِ باز یعنی هنوز کاری مانده — شمارنده‌ی داشبورد از همین می‌خواند. */
    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::InProgress, self::Resolved], true);
    }

    /** پس از بسته یا ردشدن، درخواست بایگانی است و پیام تازه نمی‌گیرد. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Closed, self::Rejected], true);
    }

    /** @return array<int, array{value: string, label: string, color: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => [
            'value' => $c->value,
            'label' => $c->label(),
            'color' => $c->color(),
        ], self::cases());
    }
}
