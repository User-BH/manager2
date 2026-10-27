<?php

namespace App\Services\Assistant\Tools;

use App\Enums\PaymentStatus;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Payment;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolDescriptor;
use App\Services\Assistant\ToolResult;
use App\Services\ReportService;

/**
 * «وضعیتِ صندوق» (آزمونِ ۹) — همان `ReportService::fundBalance` ِ داشبورد؛ ستونِ مرده‌ی صندوق روی ردیفِ مجتمع هرگز.
 */
final class FundTool extends Tool
{
    protected function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            id: 'finance.fund',
            title: 'صندوقِ مجتمع',
            dashboards: ['manager'],
            source: 'ReportService::fundBalance (payments success + incomes − expenses)',
            fields: ['balance' => 'decimal', 'in' => 'decimal', 'out' => 'decimal'],
            relations: ['payments', 'incomes', 'expenses'],
            filters: [],
            operations: ['aggregate'],
            derivation: 'موجودی = Σ پرداخت‌های موفق + Σ درآمدها − Σ هزینه‌ها، از آغاز',
            access: 'فقط مدیرِ همین مجتمع',
            privacy: '—',
            time: 'تجمعی تا همین لحظه',
            limit: 1,
            presentation: 'موجودی و دو جزء',
            keywords: ['صندوق' => 5, 'موجودی' => 1, 'تراز' => 3],
        );
    }

    public function answer(AssistantContext $context, Question $question): ToolResult
    {
        $complex = $context->complex;
        $id = $complex?->id;
        $balance = (new ReportService($complex))->fundBalance();
        $charges = (float) Payment::query()->where('complex_id', $id)->where('status', PaymentStatus::Success)->sum('amount');
        $incomes = (float) Income::query()->where('complex_id', $id)->sum('amount');
        $expenses = (float) Expense::query()->where('complex_id', $id)->sum('amount');

        $headline = 'موجودیِ صندوقِ مجتمع: '.$this->money($context, $balance).'.';
        if ($question->period !== null) {
            $headline .= ' موجودی تجمعی است؛ برای ورودی و خروجیِ یک بازه، درآمد یا هزینه‌ی همان بازه را بپرسید.';
        }

        return ToolResult::found($headline, [
            ToolResult::fact('ورودی (پرداخت‌های موفق)', $this->money($context, $charges)),
            ToolResult::fact('ورودی (سایرِ درآمدها)', $this->money($context, $incomes)),
            ToolResult::fact('خروجی (هزینه‌ها)', $this->money($context, $expenses)),
            ToolResult::fact('موجودی', $this->money($context, $balance)),
        ], basis: 'از آغاز: پرداخت‌های موفق + درآمدها − هزینه‌ها (همان عددِ داشبورد)');
    }
}
