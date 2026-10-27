<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolDescriptor;
use App\Services\Assistant\ToolResult;
use App\Support\Jalali;

/** مشخصاتِ واحد (بندِ ۴.۵-ب) — همان ردیفِ واحد و «اطلاعاتِ واحد». */
final class UnitInfoTool extends Tool
{
    use ResolvesUnit;

    protected function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            id: 'unit.info',
            title: 'مشخصاتِ واحد',
            dashboards: ['unit', 'manager'],
            source: 'units (+ building) — UnitController / UnitInformationController',
            fields: ['unit_number' => 'string', 'building' => 'string', 'floor' => 'int', 'area' => 'decimal(m²)', 'occupancy' => 'enum',
                'parking' => 'int + numbers', 'storage' => 'int + numbers', 'rooms' => 'int', 'balance' => 'decimal (فقط مدیر)'],
            relations: ['building', 'residents', 'bills'],
            filters: ['unit'],
            operations: ['read'],
            derivation: 'مانده‌ی بدهی = Σ(مبلغ − پرداخت‌شده) ِ قبض‌های واحد (کشِ units.balance)',
            access: 'مدیر: هر واحدِ همین مجتمع؛ ساکن: فقط واحدهای فعلیِ خودش',
            privacy: 'ضریب و مانده‌ی کلِ واحد فقط برای مدیر',
            time: 'وضعیتِ فعلی',
            limit: 1,
            presentation: 'فهرستِ مشخصات؛ فیلدِ خالی «ثبت نشده»',
            keywords: ['مشخصات واحد' => 6, 'اطلاعات واحد' => 6, 'متراژ' => 5, 'مساحت' => 5, 'طبقه واحد' => 4, 'پارکینگ' => 4, 'انباری' => 4,
                'وضعیت واحد' => 4, 'اتاق' => 3, 'واحد' => 1],
            cacheable: true,
        );
    }

    public function answer(AssistantContext $context, Question $question): ToolResult
    {
        $unit = $this->unitFor($context, $question);
        if ($unit instanceof ToolResult) {
            return $unit;
        }
        $unit->loadMissing('building');

        $optional = fn (mixed $value) => filled($value) ? (string) $value : 'ثبت نشده';
        $yesNo = fn (?bool $value) => $value === null ? 'ثبت نشده' : ($value ? 'دارد' : 'ندارد');

        $facts = [
            ToolResult::fact('شماره', Jalali::digits($unit->unit_number)),
            ToolResult::fact('ساختمان', $optional($unit->building?->name)),
            ToolResult::fact('بلوک', $optional($unit->block)),
            ToolResult::fact('طبقه', Jalali::digits($unit->floor)),
            ToolResult::fact('متراژ', (float) $unit->area > 0 ? Jalali::digits(rtrim(rtrim((string) $unit->area, '0'), '.')).' متر مربع' : 'ثبت نشده'),
            ToolResult::fact('وضعیتِ سکونت', $unit->occupancy_status?->label() ?? 'ثبت نشده'),
            ToolResult::fact('پارکینگ', Jalali::digits((int) $unit->parking_count).($unit->parking_numbers ? ' (شماره: '.Jalali::digits($unit->parking_numbers).')' : '')),
            ToolResult::fact('انباری', Jalali::digits((int) $unit->storage_count).($unit->storage_numbers ? ' (شماره: '.Jalali::digits($unit->storage_numbers).')' : '')),
            ToolResult::fact('اتاق / خواب', $unit->rooms_count === null ? 'ثبت نشده' : Jalali::digits($unit->rooms_count).' / '.($unit->bedrooms_count === null ? 'ثبت نشده' : Jalali::digits($unit->bedrooms_count))),
            ToolResult::fact('تراس', $yesNo($unit->has_terrace)),
            ToolResult::fact('بالکن', $yesNo($unit->has_balcony)),
        ];

        if ($context->isManager()) {
            $facts[] = ToolResult::fact('مانده‌ی بدهیِ واحد', $this->money($context, $unit->balance));
        }

        return ToolResult::found('مشخصاتِ واحدِ '.Jalali::digits($unit->unit_number).':', $facts, basis: 'ردیفِ واحد و «اطلاعاتِ واحد» در همین مجتمع');
    }
}
