<?php

namespace App\Services\Assistant\Tools;

use App\Models\Unit;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolResult;
use App\Support\Jalali;

/**
 * «واحدِ ۳» → ردیفِ واحدِ **همین مجتمع** (بندهای ۴.۴ و ۱۶ ِ آزمون‌ها: تغییرِ شناسه).
 *
 * شماره‌ی واحد فقط درونِ مجتمعِ فعالِ سرور جست‌وجو می‌شود — شناسه‌ی پایگاه‌داده هرگز از متن خوانده نمی‌شود. ساکن فقط
 * به واحدهای **فعلیِ** خودش می‌رسد؛ واحدِ همسایه برای او «اجازه نیست» است، بی گفتنِ اینکه آن واحد هست یا نه.
 */
trait ResolvesUnit
{
    protected function unitFor(AssistantContext $context, Question $question): Unit|ToolResult
    {
        $complexId = (int) $context->complex?->id;
        $own = $context->isResident()
            ? $context->user->currentUnits()->where('units.complex_id', $complexId)->orderBy('units.unit_number')->get()
            : collect();

        if ($question->unit === null) {
            if ($context->isManager()) {
                return ToolResult::clarify('شماره‌ی واحد را هم بنویسید؛ مثلاً «مشخصات واحد ۳».');
            }

            return match ($own->count()) {
                0 => ToolResult::notFound('در این مجتمع واحدِ فعلی‌ای به نامِ شما ثبت نیست.'),
                1 => $own->first(),
                default => ToolResult::clarify('چند واحد دارید؛ کدام؟', $own->map(fn (Unit $u) => 'واحد '.Jalali::digits($u->unit_number))->all()),
            };
        }

        if ($context->isResident()) {
            $mine = $own->first(fn (Unit $u) => mb_strtolower((string) $u->unit_number) === mb_strtolower($question->unit));

            return $mine ?? ToolResult::forbidden('در داشبوردِ واحد فقط اطلاعاتِ واحدِ خودتان را می‌بینید.');
        }

        $unit = Unit::query()->where('complex_id', $complexId)
            ->where('unit_number', $question->unit)->first();

        return $unit ?? ToolResult::notFound('واحدِ '.Jalali::digits($question->unit).' در این مجتمع ثبت نشده است.');
    }
}
