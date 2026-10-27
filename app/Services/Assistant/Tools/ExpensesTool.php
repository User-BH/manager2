<?php

namespace App\Services\Assistant\Tools;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolDescriptor;
use App\Services\Assistant\ToolResult;
use App\Support\Jalali;

/**
 * «مجموعِ هزینه‌های ماهِ گذشته» (آزمونِ ۷) — همان دفترِ «هزینه‌ها و درآمدها» و همان جمعِ داشبورد
 * (`ReportService::monthlyExpense`): ماه و سال با **دوره‌ی مالی** (`period`)، روز و هفته با تاریخِ هزینه.
 */
final class ExpensesTool extends Tool
{
    protected function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            id: 'finance.expenses',
            title: 'هزینه‌ها',
            dashboards: ['manager'],
            source: 'expenses — FinanceController::index / ReportService::monthlyExpense',
            fields: ['title' => 'string', 'amount' => 'decimal', 'period' => 'jalali YYYY-MM', 'spend_date' => 'date', 'category' => 'enum'],
            relations: ['complex'],
            filters: ['period'],
            operations: ['read', 'aggregate'],
            derivation: 'جمع = Σ مبلغِ هزینه‌های دوره (همان عددِ داشبورد)',
            access: 'فقط مدیرِ همین مجتمع (صفحه‌ی «مالی» مالِ مدیر است)',
            privacy: 'سازنده‌ی ردیف و پیوست برنمی‌گردد',
            time: 'ماه/سال: دوره‌ی مالیِ ثبت‌شده؛ روز/هفته: تاریخِ هزینه؛ بی بازه = ماهِ جاری',
            limit: 10,
            presentation: 'تعداد و جمع، به‌علاوه‌ی ده مورد آخر',
            keywords: ['هزینه' => 4, 'هزینهها' => 4, 'خرج' => 4, 'مخارج' => 4],
        );
    }

    public function answer(AssistantContext $context, Question $question): ToolResult
    {
        $period = $this->periodOf($question);
        $query = Expense::query()->where('complex_id', $context->complex?->id)
            ->when(
                $period->monthAligned(),
                fn ($q) => $q->whereIn('period', $period->periods),
                fn ($q) => $q->whereBetween('spend_date', [$period->firstDay(), $period->lastDay()]),
            );

        $count = (clone $query)->count();
        if ($count === 0) {
            return ToolResult::notFound("برای {$period->label} هزینه‌ای ثبت نشده است.", $period);
        }

        $total = (float) (clone $query)->sum('amount');
        $rows = (clone $query)->orderByDesc('spend_date')->orderByDesc('id')->limit(10)->get()
            ->map(fn (Expense $e) => [
                $e->title,
                $e->spend_date ? Jalali::date($e->spend_date) : Jalali::periodLabel((string) $e->period),
                $this->money($context, $e->amount),
                $e->getAttribute('category') === ExpenseCategory::Owner ? 'مالکانه' : 'مستاجرانه',
            ])->all();

        return ToolResult::found(
            "هزینه‌های مجتمع در {$period->label}: ".$this->count($count).' مورد، جمعاً '.$this->money($context, $total).'.',
            [ToolResult::fact('تعداد', $this->count($count)), ToolResult::fact('جمع', $this->money($context, $total))],
            basis: $period->monthAligned() ? 'هزینه‌های ثبت‌شده در دوره‌ی مالیِ همین بازه' : 'هزینه‌ها با تاریخِ هزینه در بازه',
            period: $period,
            columns: ['عنوان', 'تاریخ', 'مبلغ', 'دسته'],
            rows: $rows,
            more: $count > 10,
        );
    }
}
