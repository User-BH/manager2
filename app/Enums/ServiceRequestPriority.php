<?php

namespace App\Enums;

/**
 * فوریتِ درخواست (R25).
 *
 * ساکن فقط بین «عادی» و «فوری» انتخاب می‌کند؛ سه پله‌ی ریزتر باعث می‌شود
 * همه همیشه بالاترین را بزنند و درجه‌بندی بی‌اثر شود. `Critical` را فقط
 * مدیر می‌گذارد — برای وقتی که واقعاً ایمنیِ ساختمان درگیر است.
 */
enum ServiceRequestPriority: string
{
    case Normal = 'normal';
    case Urgent = 'urgent';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'عادی',
            self::Urgent => 'فوری',
            self::Critical => 'بحرانی',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Normal => 'slate',
            self::Urgent => 'amber',
            self::Critical => 'rose',
        };
    }

    /** ترتیبِ مرتب‌سازی؛ بحرانی بالای فهرستِ مدیر می‌نشیند. */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 3,
            self::Urgent => 2,
            self::Normal => 1,
        };
    }

    /** فوریت‌هایی که ساکن خودش می‌تواند انتخاب کند. */
    public static function residentChoices(): array
    {
        return [self::Normal, self::Urgent];
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
