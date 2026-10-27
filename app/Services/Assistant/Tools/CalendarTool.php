<?php

namespace App\Services\Assistant\Tools;

use App\Models\Reminder;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Period;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolDescriptor;
use App\Services\Assistant\ToolResult;
use App\Support\Jalali;
use Carbon\CarbonImmutable;

/**
 * تاریخ و یادآورها (بندِ ۴.۵-هـ؛ آزمون‌های ۱۲ و ۱۳ — ماژولِ تقویمِ ۱۳/۷).
 *
 * «امروز چندم است؟» از ساعتِ سرور به وقتِ تهران؛ یادآورها همان یادآورهای شخصیِ همین حساب در همین زمینه
 * (`ReminderController`): مجتمعِ فعال، یا سکو برای سوپر ادمین.
 */
final class CalendarTool extends Tool
{
    protected function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            id: 'calendar',
            title: 'تاریخ و یادآورها',
            dashboards: ['unit', 'manager', 'platform'],
            source: 'reminders — Reminder::occurrencesBetween (تقویمِ ۱۳/۷)',
            fields: ['title' => 'string', 'date' => 'date', 'remind_time' => 'HH:MM', 'repeat' => 'string'],
            relations: ['skips'],
            filters: ['period'],
            operations: ['read'],
            derivation: 'نمونه‌های یادآورهای تکرارشونده از قاعده محاسبه می‌شوند',
            access: 'فقط یادآورهای خودِ حساب در همین زمینه',
            privacy: 'یادآورِ زمینه‌ی دیگر نه',
            time: 'وقتِ تهران؛ بی بازه = هفت روزِ آینده',
            limit: 10,
            presentation: 'تاریخِ امروز و فهرستِ یادآورها',
            keywords: ['یادآور' => 5, 'یاداور' => 5, 'تقویم' => 4, 'چندم' => 4, 'امروز' => 2, 'تاریخ امروز' => 4, 'چه روزی' => 3],
        );
    }

    public function answer(AssistantContext $context, Question $question): ToolResult
    {
        $now = CarbonImmutable::now(Jalali::zone());
        $today = 'امروز '.Period::jalali($now, 'l j F Y').' است.';

        if (! $question->mentions('یادآور', 'یاداور', 'تقویم')) {
            return ToolResult::found($today, [ToolResult::fact('امروز', Period::jalali($now, 'l j F Y'))], basis: 'ساعتِ سرور به وقتِ تهران');
        }

        $from = $question->period->start ?? $now->startOfDay();
        $to = $question->period->end ?? $now->startOfDay()->addDays(7);
        $label = $question->period->label ?? 'هفت روزِ آینده';

        $occurrences = [];
        $reminders = Reminder::with('skips')->where('user_id', $context->user->id)
            ->when($context->complex, fn ($q, $c) => $q->where('complex_id', $c->id), fn ($q) => $q->whereNull('complex_id'))
            ->where('target_date', '<', $to->toDateString())
            ->get();
        foreach ($reminders as $reminder) {
            foreach ($reminder->occurrencesBetween($from->toDateString(), $to->subDay()->toDateString()) as $date) {
                $occurrences[] = [$date, $reminder->remind_time, $reminder->title];
            }
        }
        usort($occurrences, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        if ($occurrences === []) {
            return ToolResult::notFound("در {$label} یادآوری ندارید. ".$today);
        }

        return ToolResult::found(
            "یادآورهای شما در {$label}: ".$this->count(count($occurrences)).' مورد. '.$today,
            basis: 'یادآورهای همین حساب در همین زمینه',
            columns: ['تاریخ', 'ساعت', 'موضوع'],
            rows: array_map(fn (array $o) => [Jalali::date($o[0], 'l j F Y'), Jalali::digits($o[1]), $o[2]], array_slice($occurrences, 0, 10)),
            more: count($occurrences) > 10,
        );
    }
}
