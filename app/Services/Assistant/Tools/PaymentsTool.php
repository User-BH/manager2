<?php

namespace App\Services\Assistant\Tools;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolDescriptor;
use App\Services\Assistant\ToolResult;
use App\Support\Jalali;
use Illuminate\Database\Eloquent\Builder;

/**
 * «پرداخت‌های من در آبان» (بندهای ۴.۴، ۴.۱۰؛ آزمونِ ۶).
 *
 * فقط پرداختِ **موفق**؛ بازه با **تاریخِ پرداخت** به وقتِ تهران، `[آغاز، پایان)` — بی افتادنِ رکوردِ روزِ اول و آخر.
 * پرداختِ بی `paid_at` (رسیدِ تأییدشده‌ی قدیمی) با زمانِ ثبتش. ساکن فقط پرداخت‌های خودش را می‌بیند؛ مدیر همه‌ی
 * پرداخت‌های مجتمع یا یک واحد را — یا پرداخت‌های خودش اگر بگوید «من».
 */
final class PaymentsTool extends Tool
{
    protected function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            id: 'finance.payments',
            title: 'پرداخت‌ها',
            dashboards: ['unit', 'manager'],
            source: 'payments (status = success) — PaymentController / MyBillController',
            fields: ['paid_at' => 'datetime', 'amount' => 'decimal', 'unit' => 'string', 'method' => 'enum'],
            relations: ['unit', 'bill', 'user'],
            filters: ['period', 'unit (مدیر)', 'من'],
            operations: ['read', 'aggregate'],
            derivation: 'جمع = Σ مبلغِ پرداخت‌های موفقِ بازه؛ تعداد = شمارشِ همان‌ها',
            access: 'ساکن: پرداخت‌های خودش در همین مجتمع؛ مدیر: همه‌ی پرداخت‌های همین مجتمع',
            privacy: 'کدِ رهگیری، رسید و یادداشتِ بازبینی برنمی‌گردد',
            time: 'تاریخِ پرداخت (paid_at، وگرنه زمانِ ثبت) به وقتِ تهران؛ بی بازه = ماهِ جاری',
            limit: 10,
            presentation: 'تعداد و جمع، به‌علاوه‌ی ده مورد آخر',
            keywords: ['پرداخت' => 3, 'پرداختی' => 3, 'واریز' => 2, 'پرداختهای من' => 2],
        );
    }

    public function score(Question $question): int
    {
        // «پرداخت‌نشده» و «نپرداخته» پرسشِ بدهی است
        return $question->mentions('پرداخت نشده', 'پرداختنشده', 'نپرداخته', 'بدهی') ? 0 : parent::score($question);
    }

    public function answer(AssistantContext $context, Question $question): ToolResult
    {
        $period = $this->periodOf($question);
        $mine = $context->isResident() || $question->mentions('پرداختهای من', 'پرداخت من', 'خودم');

        $query = Payment::query()
            ->with('unit:id,unit_number')
            ->where('complex_id', $context->complex?->id)
            ->where('status', PaymentStatus::Success)
            ->whereRaw('COALESCE(paid_at, created_at) >= ? AND COALESCE(paid_at, created_at) < ?', [
                $period->startUtc()->format('Y-m-d H:i:s'), $period->endUtc()->format('Y-m-d H:i:s'),
            ])
            ->when($mine, fn (Builder $q) => $q->where('user_id', $context->user->id));

        $who = $mine ? 'شما' : 'مجتمع';
        if (! $mine && $question->unit !== null) {
            $query->whereHas('unit', fn (Builder $q) => $q->where('unit_number', $question->unit));
            $who = 'واحدِ '.Jalali::digits($question->unit);
        }

        $count = (clone $query)->count();
        if ($count === 0) {
            return ToolResult::notFound("در {$period->label} پرداختِ موفقی از {$who} ثبت نشده است.", $period);
        }

        $total = (float) (clone $query)->sum('amount');
        $rows = (clone $query)->orderByRaw('COALESCE(paid_at, created_at) DESC')->orderByDesc('id')->limit(10)->get()
            ->map(fn (Payment $p) => [
                Jalali::date($p->paid_at ?? $p->created_at),
                $p->unit ? 'واحد '.Jalali::digits($p->unit->unit_number) : '—',
                $this->money($context, $p->amount),
                ($method = $p->getAttribute('method')) instanceof PaymentMethod ? $method->label() : '—',
            ])->all();

        return ToolResult::found(
            "پرداخت‌های {$who} در {$period->label}: ".$this->count($count).' پرداخت، جمعاً '.$this->money($context, $total).'.',
            [ToolResult::fact('تعداد', $this->count($count)), ToolResult::fact('جمع', $this->money($context, $total))],
            basis: 'پرداخت‌های موفق با تاریخِ پرداخت در بازه (وقتِ تهران)',
            period: $period,
            columns: ['تاریخ', 'واحد', 'مبلغ', 'روش'],
            rows: $rows,
            more: $count > 10,
        );
    }
}
