<?php

namespace App\Enums;

enum OccupancyStatus: string
{
    case OwnerOccupied = 'owner_occupied';
    case Rented = 'rented';
    case Vacant = 'vacant';

    public function label(): string
    {
        return match ($this) {
            self::OwnerOccupied => 'سکونت مالک',
            self::Rented => 'اجاره داده شده',
            self::Vacant => 'خالی',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    }
}
