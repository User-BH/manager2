<?php

namespace App\Services\Assistant\Tools;

use App\Enums\ResidentRelation;
use App\Models\User;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolDescriptor;
use App\Services\Assistant\ToolResult;
use App\Support\Jalali;

/**
 * مالک و مستأجرِ **فعلیِ** یک واحد (بندِ ۴.۵-ب و ج؛ آزمون‌های ۳ تا ۵).
 *
 * دوره‌ی بسته‌شده شمرده نمی‌شود: مستأجرِ رفته «مستأجرِ واحد» نیست. شماره‌ی تماس فقط برای مدیرِ همین مجتمع.
 */
final class UnitPeopleTool extends Tool
{
    use ResolvesUnit;

    protected function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            id: 'unit.people',
            title: 'مالک و مستأجرِ واحد',
            dashboards: ['unit', 'manager'],
            source: 'unit_user (is_current) + users — Unit::owners / Unit::tenants',
            fields: ['owner' => 'string', 'tenant' => 'string', 'phone' => 'string (حساس، فقط مدیر)', 'since' => 'date'],
            relations: ['unit', 'user'],
            filters: ['unit', 'relation (مالک/مستأجر)'],
            operations: ['read'],
            derivation: '—',
            access: 'مدیر: هر واحدِ همین مجتمع؛ ساکن: واحدِ خودش، بی شماره‌ی تماس',
            privacy: 'شماره‌ی تماس فقط برای مدیر؛ دوره‌ی پایان‌یافته نه',
            time: 'سکونتِ فعلی (is_current)',
            limit: 10,
            presentation: 'جدولِ نام، نسبت، از تاریخ، و برای مدیر تلفن',
            keywords: ['مالک' => 4, 'مستاجر' => 4, 'مستاجرین' => 4, 'ساکن واحد' => 4, 'ساکنین واحد' => 4, 'ساکنان واحد' => 4, 'صاحب واحد' => 4, 'چه کسی' => 1, 'کیست' => 1],
            sensitive: true,
        );
    }

    public function answer(AssistantContext $context, Question $question): ToolResult
    {
        if ($context->isResident() && $question->wantsContact) {
            return ToolResult::forbidden('شماره‌ی تماسِ ساکنان فقط در داشبوردِ مدیرِ مجتمع دیده می‌شود.');
        }

        $unit = $this->unitFor($context, $question);
        if ($unit instanceof ToolResult) {
            return $unit;
        }

        $relation = match (true) {
            $question->mentions('مستاجر') && ! $question->mentions('مالک') => ResidentRelation::Tenant,
            $question->mentions('مالک') && ! $question->mentions('مستاجر') => ResidentRelation::Owner,
            default => null,
        };

        // ⚠️ نه `when()`: روی رابطه، بستار سازنده‌ی Eloquent را می‌گیرد و `wherePivot` آن‌جا «where pivot = …» می‌شود
        $residents = $unit->residents()->wherePivot('is_current', true);
        if ($relation !== null) {
            $residents->wherePivot('relation', $relation->value);
        }
        $people = $residents->orderBy('users.name')->limit(10)->get(['users.id', 'users.name', 'users.phone']);

        $label = 'واحدِ '.Jalali::digits($unit->unit_number);
        $who = $relation?->label() ?? 'ساکن';
        if ($people->isEmpty()) {
            return ToolResult::notFound("برای {$label} {$who}ِ فعلی‌ای ثبت نشده است.");
        }

        $withPhone = $context->isManager();
        $rows = $people->map(function (User $u) use ($withPhone) {
            $pivot = $u->getRelation('pivot');
            $row = [
                $u->name,
                ResidentRelation::tryFrom((string) $pivot->getAttribute('relation'))?->label() ?? 'ساکن',
                $pivot->getAttribute('start_date') ? Jalali::date((string) $pivot->getAttribute('start_date')) : 'ثبت نشده',
            ];

            return $withPhone ? [...$row, $u->phone ? Jalali::digits($u->phone) : 'ثبت نشده'] : $row;
        })->all();

        return ToolResult::found(
            "{$who}ِ فعلیِ {$label}:",
            columns: $withPhone ? ['نام', 'نسبت', 'از', 'تلفن'] : ['نام', 'نسبت', 'از'],
            rows: $rows,
            basis: 'دوره‌های سکونت و مالکیتِ جاریِ همان واحد',
        );
    }
}
