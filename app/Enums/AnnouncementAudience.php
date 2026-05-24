<?php

namespace App\Enums;

enum AnnouncementAudience: string
{
    case All = 'all';
    case Owners = 'owners';
    case Tenants = 'tenants';

    public function label(): string
    {
        return match ($this) {
            self::All => 'همه ساکنین',
            self::Owners => 'فقط مالکین',
            self::Tenants => 'فقط مستاجرین',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    }
}
