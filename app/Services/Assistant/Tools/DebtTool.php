<?php

namespace App\Services\Assistant\Tools;

use App\Models\Bill;
use App\Models\Unit;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolDescriptor;
use App\Services\Assistant\ToolResult;
use App\Services\Finance\ResidentDebt;
use App\Services\ReportService;
use App\Support\Jalali;
use Illuminate\Database\Eloquent\Builder;

/**
 * بدهی و قبض‌های پرداخت‌نشده (بندِ ۴.۵-د) — دو تعریفِ canonical ِ پروژه، هر کدام سرِ جای خودش:
 * - ساکن: `ResidentDebt` — فقط دوره‌های سکونتِ خودش (همان عددِ «قبض‌های من» و داشبوردِ واحد)؛
 * - مدیر: `units.balance` / `ReportService::totalDebt` — بدهیِ کلِ واحد و مجتمع.
 */
final class DebtTool extends Tool
{
    use ResolvesUnit;

    protected function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            id: 'finance.debt',
            title: 'بدهی و قبض‌ها',
            dashboards: ['unit', 'manager'],
            source: 'bills — ResidentDebt (ساکن) / ReportService::totalDebt و units.balance (مدیر)',
            fields: ['period' => 'jalali YYYY-MM', 'total_amount' => 'decimal', 'paid_amount' => 'decimal', 'status' => 'enum'],
            relations: ['unit'],
            filters: ['unit (مدیر)', 'period'],
            operations: ['read', 'aggregate'],
            derivation: 'بدهی = Σ (مبلغِ قبض − پرداخت‌شده)',
            access: 'ساکن: قبض‌های دوره‌های سکونتِ خودش؛ مدیر: همه‌ی واحدهای همین مجتمع',
            privacy: 'ساکن بدهیِ دوره‌ی ساکنِ قبلی یا واحدِ همسایه را نمی‌بیند',
            time: 'دوره‌ی قبض (period)؛ بی بازه = همه‌ی قبض‌های باز',
            limit: 10,
            presentation: 'جمعِ بدهی و فهرستِ قبض‌های باز',
            keywords: ['بدهی' => 4, 'بدهکار' => 4, 'بدهکاری' => 4, 'پرداخت نشده' => 4, 'پرداختنشده' => 4, 'مانده' => 3, 'قبض' => 3, 'قبوض' => 3,
                'صورتحساب' => 3, 'شارژ' => 2, 'نپرداخته' => 4],
        );
    }

    public function answer(AssistantContext $context, Question $question): ToolResult
    {
        return $context->isManager() ? $this->forManager($context, $question) : $this->forResident($context, $question);
    }

    private function forResident(AssistantContext $context, Question $question): ToolResult
    {
        if ($question->unit !== null) {
            $unit = $this->unitFor($context, $question);
            if ($unit instanceof ToolResult) {
                return $unit;
            }
        }

        $total = app(ResidentDebt::class)->total($context->user);
        $open = Bill::query()->visibleToResident($context->user)->whereColumn('paid_amount', '<', 'total_amount')
            ->when($question->period, fn (Builder $q, $p) => $q->whereIn('period', $p->periods))
            ->with('unit:id,unit_number');

        if ($total <= 0 && (clone $open)->count() === 0) {
            return ToolResult::notFound('قبضِ پرداخت‌نشده‌ای برای شما ثبت نیست؛ بدهی ندارید.', $question->period);
        }

        return ToolResult::found(
            'بدهیِ شما: '.$this->money($context, $total).'.',
            [ToolResult::fact('جمعِ بدهی', $this->money($context, $total))],
            basis: 'قبض‌های دوره‌های سکونتِ خودتان (همان عددِ «قبض‌های من»)',
            period: $question->period,
            columns: ['دوره', 'واحد', 'مبلغِ قبض', 'مانده'],
            rows: $this->rows($context, (clone $open)->orderBy('period')->limit(10)->get()),
            more: (clone $open)->count() > 10,
        );
    }

    private function forManager(AssistantContext $context, Question $question): ToolResult
    {
        $complexId = (int) $context->complex?->id;

        if ($question->unit !== null) {
            $unit = $this->unitFor($context, $question);
            if ($unit instanceof ToolResult) {
                return $unit;
            }
            $open = Bill::query()->where('complex_id', $complexId)->where('unit_id', $unit->id)
                ->whereColumn('paid_amount', '<', 'total_amount')->with('unit:id,unit_number');

            return ToolResult::found(
                'بدهیِ واحدِ '.Jalali::digits($unit->unit_number).': '.$this->money($context, $unit->balance).'.',
                [ToolResult::fact('مانده‌ی واحد', $this->money($context, $unit->balance))],
                basis: 'Σ (مبلغ − پرداخت‌شده) ِ همه‌ی قبض‌های همان واحد',
                columns: ['دوره', 'واحد', 'مبلغِ قبض', 'مانده'],
                rows: $this->rows($context, (clone $open)->orderBy('period')->limit(10)->get()),
                more: (clone $open)->count() > 10,
            );
        }

        if ($question->period !== null) {
            $bills = Bill::query()->where('complex_id', $complexId)->whereIn('period', $question->period->periods);
            if ((clone $bills)->count() === 0) {
                return ToolResult::notFound("برای {$question->period->label} قبضی صادر نشده است.", $question->period);
            }
            $remaining = (float) (clone $bills)->selectRaw('COALESCE(SUM(total_amount - paid_amount), 0) as d')->value('d');
            $unpaid = (clone $bills)->whereColumn('paid_amount', '<', 'total_amount')->count();

            return ToolResult::found(
                "قبض‌های {$question->period->label}: ".$this->count((clone $bills)->count()).' قبض، '.$this->count($unpaid).' قبضِ باز، مانده '.$this->money($context, $remaining).'.',
                [
                    ToolResult::fact('قبض‌ها', $this->count((clone $bills)->count())),
                    ToolResult::fact('قبض‌های باز', $this->count($unpaid)),
                    ToolResult::fact('مانده', $this->money($context, $remaining)),
                ],
                basis: 'قبض‌های صادرشده‌ی دوره‌ی همین بازه',
                period: $question->period,
            );
        }

        $total = (new ReportService($context->complex))->totalDebt();
        $debtors = Unit::query()->where('complex_id', $complexId)->where('balance', '>', 0);
        if ($total <= 0) {
            return ToolResult::notFound('هیچ واحدی بدهیِ باز ندارد.');
        }

        return ToolResult::found(
            'بدهیِ کلِ مجتمع: '.$this->money($context, $total).' در '.$this->count((clone $debtors)->count()).' واحد.',
            [ToolResult::fact('بدهیِ کل', $this->money($context, $total)), ToolResult::fact('واحدهای بدهکار', $this->count((clone $debtors)->count()))],
            basis: 'Σ (مبلغ − پرداخت‌شده) ِ همه‌ی قبض‌های مجتمع (همان عددِ داشبورد)',
            columns: ['واحد', 'مانده'],
            rows: (clone $debtors)->orderByDesc('balance')->limit(10)->get()
                ->map(fn (Unit $u) => ['واحد '.Jalali::digits($u->unit_number), $this->money($context, $u->balance)])->all(),
            more: (clone $debtors)->count() > 10,
        );
    }

    /**
     * @param  iterable<Bill>  $bills
     * @return list<list<string>>
     */
    private function rows(AssistantContext $context, iterable $bills): array
    {
        $rows = [];
        foreach ($bills as $bill) {
            $rows[] = [
                Jalali::periodLabel((string) $bill->period),
                $bill->unit ? 'واحد '.Jalali::digits($bill->unit->unit_number) : '—',
                $this->money($context, $bill->total_amount),
                $this->money($context, $bill->remaining()),
            ];
        }

        return $rows;
    }
}
