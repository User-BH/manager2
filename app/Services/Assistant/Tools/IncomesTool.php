<?php

namespace App\Services\Assistant\Tools;

use App\Enums\PaymentStatus;
use App\Models\Income;
use App\Models\Payment;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolDescriptor;
use App\Services\Assistant\ToolResult;
use App\Support\Jalali;

/**
 * «درآمدهای ثبت‌شده در این دوره» (آزمونِ ۸) — همان تعریفِ داشبورد (`ReportService::monthlyIncome`): پرداخت‌های موفقِ
 * شارژِ دوره + سایرِ درآمدهای ثبت‌شده؛ هر دو جزء جدا گفته می‌شود تا عدد قابلِ بازبینی باشد.
 */
final class IncomesTool extends Tool
{
    protected function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            id: 'finance.incomes',
            title: 'درآمدها',
            dashboards: ['manager'],
            source: 'payments (success) + incomes — ReportService::monthlyIncome',
            fields: ['charges' => 'decimal', 'other' => 'decimal', 'title' => 'string', 'received_date' => 'date'],
            relations: ['complex'],
            filters: ['period'],
            operations: ['read', 'aggregate'],
            derivation: 'درآمد = Σ پرداخت‌های موفقِ شارژ + Σ سایرِ درآمدها (همان عددِ داشبورد)',
            access: 'فقط مدیرِ همین مجتمع',
            privacy: 'پرداخت‌کننده‌ی هر شارژ برنمی‌گردد — فقط جمع',
            time: 'ماه/سال: دوره‌ی مالی (period)؛ روز/هفته: تاریخِ پرداخت و دریافت؛ بی بازه = ماهِ جاری',
            limit: 10,
            presentation: 'دو جزء و جمع، به‌علاوه‌ی فهرستِ سایرِ درآمدها',
            keywords: ['درآمد' => 4, 'درامد' => 4, 'درآمدها' => 4, 'دریافتی' => 3, 'عواید' => 3],
        );
    }

    public function answer(AssistantContext $context, Question $question): ToolResult
    {
        $period = $this->periodOf($question);
        $complexId = $context->complex?->id;

        $charges = Payment::query()->where('complex_id', $complexId)->where('status', PaymentStatus::Success)
            ->when(
                $period->monthAligned(),
                fn ($q) => $q->whereIn('period', $period->periods),
                fn ($q) => $q->whereRaw('COALESCE(paid_at, created_at) >= ? AND COALESCE(paid_at, created_at) < ?', [
                    $period->startUtc()->format('Y-m-d H:i:s'), $period->endUtc()->format('Y-m-d H:i:s'),
                ]),
            );
        $other = Income::query()->where('complex_id', $complexId)
            ->when(
                $period->monthAligned(),
                fn ($q) => $q->whereIn('period', $period->periods),
                fn ($q) => $q->whereBetween('received_date', [$period->firstDay(), $period->lastDay()]),
            );

        $chargeCount = (clone $charges)->count();
        $otherCount = (clone $other)->count();
        if ($chargeCount + $otherCount === 0) {
            return ToolResult::notFound("برای {$period->label} درآمدی ثبت نشده است.", $period);
        }

        $chargeSum = (float) (clone $charges)->sum('amount');
        $otherSum = (float) (clone $other)->sum('amount');
        $rows = (clone $other)->orderByDesc('received_date')->orderByDesc('id')->limit(10)->get()
            ->map(fn (Income $i) => [$i->title, $i->received_date ? Jalali::date($i->received_date) : '—', $this->money($context, $i->amount)])
            ->all();

        return ToolResult::found(
            "درآمدِ مجتمع در {$period->label}: جمعاً ".$this->money($context, $chargeSum + $otherSum).'.',
            [
                ToolResult::fact('پرداخت‌های شارژ', $this->count($chargeCount).' مورد، '.$this->money($context, $chargeSum)),
                ToolResult::fact('سایرِ درآمدها', $this->count($otherCount).' مورد، '.$this->money($context, $otherSum)),
                ToolResult::fact('جمع', $this->money($context, $chargeSum + $otherSum)),
            ],
            basis: $period->monthAligned()
                ? 'همان فرمولِ داشبورد: پرداخت‌های موفقِ قبض‌های این دوره + درآمدهای ثبت‌شده‌ی این دوره'
                : 'پرداخت‌های موفق با تاریخِ پرداخت و درآمدها با تاریخِ دریافت در بازه',
            period: $period,
            columns: $rows === [] ? [] : ['سایرِ درآمدها', 'تاریخ', 'مبلغ'],
            rows: $rows,
            more: $otherCount > 10,
        );
    }
}
