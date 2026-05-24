<?php

namespace App\Enums;

enum ChargeRuleType: string
{
    // Fixed amount, identical for every unit.
    case Fixed = 'fixed';
    // Amount per resident person (config.amount * unit.residents_count).
    case PerPerson = 'per_person';
    // Amount per square meter (config.amount * unit.area).
    case PerArea = 'per_area';
    // base + per_area_rate*area + per_person_rate*persons.
    case Combined = 'combined';
    // Split a total expense pool across units, weighted by residents_count.
    case UtilityByPerson = 'utility_by_person';
    // Split a total expense pool equally across the number of units.
    case ByUnitCount = 'by_unit_count';
    // Split a total expense pool weighted by each unit's custom coefficient.
    case ByCoefficient = 'by_coefficient';
    // Split an elevator expense weighted by floor number (ground floor optionally exempt).
    case ElevatorByFloor = 'elevator_by_floor';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'مبلغ ثابت برای همه واحدها',
            self::PerPerson => 'بر اساس تعداد نفرات',
            self::PerArea => 'بر اساس متراژ',
            self::Combined => 'ترکیبی (ثابت + متراژ + نفرات)',
            self::UtilityByPerson => 'تقسیم قبض عمومی بر اساس نفرات',
            self::ByUnitCount => 'تقسیم مساوی بر تعداد واحدها',
            self::ByCoefficient => 'تقسیم بر اساس ضریب اختصاصی واحد',
            self::ElevatorByFloor => 'هزینه آسانسور بر اساس طبقه',
        };
    }

    /**
     * Whether the rule distributes a single expense pool (needs a `pool` amount)
     * rather than computing per-unit from the unit's own attributes.
     */
    public function isPoolBased(): bool
    {
        return in_array($this, [
            self::UtilityByPerson,
            self::ByUnitCount,
            self::ByCoefficient,
            self::ElevatorByFloor,
        ], true);
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    }
}
