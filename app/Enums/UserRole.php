<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case ComplexAdmin = 'complex_admin';
    case Owner = 'owner';
    case Tenant = 'tenant';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'ادمین کل سیستم',
            self::ComplexAdmin => 'مدیر مجتمع',
            self::Owner => 'مالک',
            self::Tenant => 'مستاجر',
        };
    }

    public function isAdmin(): bool
    {
        return in_array($this, [self::SuperAdmin, self::ComplexAdmin], true);
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    }
}
