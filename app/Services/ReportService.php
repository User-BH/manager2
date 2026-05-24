<?php

namespace App\Services;

use App\Enums\BillStatus;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Payment;
use App\Models\Unit;
use App\Support\Jalali;
use Illuminate\Support\Collection;

class ReportService
{
    public function __construct(protected Complex $complex) {}

    public function monthlyIncome(string $period): float
    {
        $charges = Payment::where('complex_id', $this->complex->id)
            ->where('period', $period)
            ->where('status', PaymentStatus::Success)
            ->sum('amount');

        $other = Income::where('complex_id', $this->complex->id)
            ->where('period', $period)
            ->sum('amount');

        return (float) $charges + (float) $other;
    }

    public function monthlyExpense(string $period): float
    {
        return (float) Expense::where('complex_id', $this->complex->id)
            ->where('period', $period)
            ->sum('amount');
    }

    /** Lifetime fund balance: all money in minus all money out. */
    public function fundBalance(): float
    {
        $in = (float) Payment::where('complex_id', $this->complex->id)
            ->where('status', PaymentStatus::Success)->sum('amount');
        $in += (float) Income::where('complex_id', $this->complex->id)->sum('amount');
        $out = (float) Expense::where('complex_id', $this->complex->id)->sum('amount');

        return $in - $out;
    }

    public function totalDebt(): float
    {
        return (float) Bill::where('complex_id', $this->complex->id)
            ->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial])
            ->selectRaw('COALESCE(SUM(total_amount - paid_amount),0) as debt')
            ->value('debt');
    }

    /** @return Collection<int,Unit> top debtor units */
    public function debtors(int $limit = 10): Collection
    {
        return Unit::where('complex_id', $this->complex->id)
            ->where('balance', '>', 0)
            ->orderByDesc('balance')
            ->limit($limit)
            ->get();
    }

    /**
     * Good payers ranked by on-time paid bills then total paid.
     *
     * @return Collection<int,array{unit:Unit,on_time:int,total_paid:float,tier:string}>
     */
    public function goodPayers(int $limit = 10): Collection
    {
        $units = Unit::where('complex_id', $this->complex->id)->with('bills')->get();

        return $units->map(function (Unit $unit) {
            $paid = $unit->bills->where('status', BillStatus::Paid);
            $onTime = $paid->filter(fn (Bill $b) => $b->paid_at && $b->due_date && $b->paid_at->lte($b->due_date))->count();
            $totalPaid = (float) $unit->bills->sum('paid_amount');

            return [
                'unit' => $unit,
                'on_time' => $onTime,
                'total_paid' => $totalPaid,
                'tier' => $this->tier($onTime),
            ];
        })
            ->filter(fn ($r) => $r['on_time'] > 0 || $r['total_paid'] > 0)
            ->sortByDesc(fn ($r) => [$r['on_time'], $r['total_paid']])
            ->take($limit)
            ->values();
    }

    protected function tier(int $onTime): string
    {
        return match (true) {
            $onTime >= 6 => 'طلایی',
            $onTime >= 3 => 'نقره‌ای',
            $onTime >= 1 => 'برنزی',
            default => '-',
        };
    }

    /** Income vs expense series for the last N periods (oldest first). */
    public function trend(string $period, int $months = 6): array
    {
        $labels = [];
        $income = [];
        $expense = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $p = Jalali::shiftPeriod($period, -$i);
            $labels[] = Jalali::periodLabel($p);
            $income[] = $this->monthlyIncome($p);
            $expense[] = $this->monthlyExpense($p);
        }

        return compact('labels', 'income', 'expense');
    }

    public function paymentStatusCounts(string $period): array
    {
        $bills = Bill::where('complex_id', $this->complex->id)->where('period', $period)->get();

        return [
            'paid' => $bills->where('status', BillStatus::Paid)->count(),
            'partial' => $bills->where('status', BillStatus::Partial)->count(),
            'pending' => $bills->where('status', BillStatus::Pending)->count(),
            'unpaid' => $bills->where('status', BillStatus::Unpaid)->count(),
        ];
    }
}
