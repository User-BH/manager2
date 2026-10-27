<?php

namespace App\Services\Assistant\Tools;

use App\Enums\ServiceRequestStatus;
use App\Models\ServiceRequest;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolDescriptor;
use App\Services\Assistant\ToolResult;
use App\Support\Jalali;

/**
 * درخواست‌های خدمات و تعمیرات (بندِ ۴.۵-ح) — همان `ServiceRequest::visibleTo`: مدیر همه‌ی درخواست‌های مجتمع، ساکن
 * فقط درخواست‌های واحدهای فعلی و دوره‌ی خودش. عددِ پاسخ همان است که فهرست نشان می‌دهد.
 */
final class ServiceRequestsTool extends Tool
{
    protected function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            id: 'requests.service',
            title: 'درخواست‌های خدمات',
            dashboards: ['unit', 'manager'],
            source: 'service_requests — ServiceRequest::visibleTo',
            fields: ['title' => 'string', 'status' => 'enum', 'priority' => 'enum', 'created_at' => 'datetime'],
            relations: ['unit', 'user', 'comments'],
            filters: ['period', 'باز'],
            operations: ['read', 'aggregate'],
            derivation: 'شمارش به تفکیکِ وضعیت',
            access: 'همان دیدِ صفحه‌ی درخواست‌ها',
            privacy: 'متنِ کامل، پیوست و گفت‌وگوی درخواست برنمی‌گردد — فقط عنوان و وضعیت',
            time: 'زمانِ ثبت',
            limit: 10,
            presentation: 'شمار به تفکیکِ وضعیت و فهرستِ باز‌ها',
            keywords: ['درخواست خدمات' => 6, 'تعمیر' => 4, 'خرابی' => 4, 'تیکت' => 4, 'درخواستهای باز' => 4, 'درخواست' => 1],
        );
    }

    public function answer(AssistantContext $context, Question $question): ToolResult
    {
        $visible = ServiceRequest::query()->visibleTo($context->user)->where('complex_id', $context->complex?->id)
            ->when($question->period, fn ($q, $p) => $q->where('created_at', '>=', $p->startUtc())->where('created_at', '<', $p->endUtc()));

        $total = (clone $visible)->count();
        if ($total === 0) {
            return ToolResult::notFound('درخواستِ خدماتی ثبت نشده است.', $question->period);
        }

        $byStatus = (clone $visible)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $facts = [];
        foreach (ServiceRequestStatus::cases() as $status) {
            if (($byStatus[$status->value] ?? 0) > 0) {
                $facts[] = ToolResult::fact($status->label(), $this->count((int) $byStatus[$status->value]));
            }
        }
        $open = (clone $visible)->open();

        return ToolResult::found(
            'درخواست‌های خدمات: '.$this->count($total).' مورد، '.$this->count((clone $open)->count()).' باز.',
            $facts,
            basis: 'درخواست‌هایی که در صفحه‌ی «درخواست‌ها» می‌بینید',
            period: $question->period,
            columns: ['عنوان', 'وضعیت', 'زمان'],
            rows: (clone $open)->latest()->limit(10)->get()->map(fn (ServiceRequest $r) => [
                $r->title, $r->status->label(), Jalali::dateTime($r->created_at),
            ])->all(),
            more: (clone $open)->count() > 10,
        );
    }
}
