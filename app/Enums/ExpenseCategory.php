<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    // مالکانه: تعمیرات اساسی، نوسازی، تجهیزات دائمی، تاسیسات اصلی، عمرانی
    case Owner = 'owner';
    // مستاجرانه: شارژ جاری، نظافت، نگهبانی، مصرف آب/برق/گاز عمومی
    case Tenant = 'tenant';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'مالکانه',
            self::Tenant => 'مستاجرانه',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    }
}
