<?php

namespace App\Services\Finance;

use App\Models\Unit;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * ناوردایی‌های مالی — منبعِ حقیقت و کش‌هایش (مرحلهٔ ۱۰ · فاز ۱۳؛ بندهای ۲ و ۱۰۵).
 *
 * ─── منبعِ حقیقت ───────────────────────────────────────────────────────────
 * بدهی را **قبض** تعریف می‌کند (`total_amount`) و پرداخت را **دفتر**:
 *
 *     bills.paid_amount = Σ payment_allocations(قبض)
 *                       + Σ wallet_transactions(قبض، برداشت، پرداختِ قبض)
 *                       − Σ wallet_transactions(قبض، واریز، بازگشت از کاهشِ قبض)
 *
 * و `units.balance` فقط کشِ آن است: `Σ (total_amount − paid_amount)` ِ قبض‌های واحد. همان فرمولی که
 * `WalletService::refundBillOverpayment` برای سهمِ هر پرداخت‌کننده به کار می‌برد.
 *
 * این کلاس فقط می‌سنجد. ترمیم فقط برای کش است (`repairUnitBalances`)؛ ناهمخوانیِ قبض با دفتر یعنی پولی که
 * ردش گم شده و تصمیمِ انسان می‌خواهد، پس هرگز خودکار «درست» نمی‌شود.
 */
final class FinancialInvariants
{
    /** ریالِ گردکردنِ decimal ِ دو رقمی */
    public const TOLERANCE = 0.01;

    /**
     * @return list<array{kind: string, id: int, complexId: int, expected: float, actual: float}>
     */
    public function violations(?int $complexId = null): array
    {
        return [...$this->unitBalanceDrift($complexId), ...$this->billLedgerDrift($complexId), ...$this->overpaidBills($complexId)];
    }

    /**
     * کشِ مانده‌ی واحد که با قبض‌هایش نمی‌خواند — حتی واحدِ حذف‌شده (قبض‌هایش می‌مانند).
     *
     * @return list<array{kind: string, id: int, complexId: int, expected: float, actual: float}>
     */
    public function unitBalanceDrift(?int $complexId = null): array
    {
        return DB::table('units')
            ->leftJoin('bills', 'bills.unit_id', '=', 'units.id')
            ->when($complexId, fn ($q) => $q->where('units.complex_id', $complexId))
            ->groupBy('units.id', 'units.complex_id', 'units.balance')
            ->select('units.id', 'units.complex_id', 'units.balance')
            ->selectRaw('COALESCE(SUM(bills.total_amount - bills.paid_amount), 0) as debt')
            ->get()
            ->filter(fn ($row) => abs((float) $row->balance - (float) $row->debt) > self::TOLERANCE)
            ->map(fn ($row) => [
                'kind' => 'unit_balance',
                'id' => (int) $row->id,
                'complexId' => (int) $row->complex_id,
                'expected' => round((float) $row->debt, 2),
                'actual' => round((float) $row->balance, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * قبضی که پرداخت‌شده‌اش با دفتر نمی‌خواند.
     *
     * @return list<array{kind: string, id: int, complexId: int, expected: float, actual: float}>
     */
    public function billLedgerDrift(?int $complexId = null): array
    {
        $allocated = DB::table('payment_allocations')
            ->whereNotNull('bill_id')
            ->groupBy('bill_id')
            ->selectRaw('bill_id, SUM(amount) as total')
            ->pluck('total', 'bill_id');

        $wallet = DB::table('wallet_transactions')
            ->whereNotNull('bill_id')
            ->groupBy('bill_id')
            ->selectRaw(
                'bill_id, SUM(CASE WHEN direction = ? AND source = ? THEN amount WHEN direction = ? AND source = ? THEN -amount ELSE 0 END) as total',
                [
                    WalletTransaction::DEBIT, WalletTransaction::SOURCE_BILL_PAYMENT,
                    WalletTransaction::CREDIT, WalletTransaction::SOURCE_BILL_REDUCTION,
                ],
            )
            ->pluck('total', 'bill_id');

        $drift = [];

        DB::table('bills')
            ->when($complexId, fn ($q) => $q->where('complex_id', $complexId))
            ->select('id', 'complex_id', 'paid_amount')
            ->orderBy('id')
            ->chunk(1000, function ($bills) use ($allocated, $wallet, &$drift): void {
                foreach ($bills as $bill) {
                    $ledger = (float) ($allocated[$bill->id] ?? 0) + (float) ($wallet[$bill->id] ?? 0);

                    if (abs($ledger - (float) $bill->paid_amount) > self::TOLERANCE) {
                        $drift[] = [
                            'kind' => 'bill_ledger',
                            'id' => (int) $bill->id,
                            'complexId' => (int) $bill->complex_id,
                            'expected' => round($ledger, 2),
                            'actual' => round((float) $bill->paid_amount, 2),
                        ];
                    }
                }
            });

        return $drift;
    }

    /**
     * قبضی که بیش از مبلغش یا منفی پرداخت شده.
     *
     * @return list<array{kind: string, id: int, complexId: int, expected: float, actual: float}>
     */
    public function overpaidBills(?int $complexId = null): array
    {
        return DB::table('bills')
            ->when($complexId, fn ($q) => $q->where('complex_id', $complexId))
            ->where(fn ($q) => $q->whereColumn('paid_amount', '>', 'total_amount')->orWhere('paid_amount', '<', 0))
            ->get(['id', 'complex_id', 'paid_amount', 'total_amount'])
            ->map(fn ($row) => [
                'kind' => 'bill_overpaid',
                'id' => (int) $row->id,
                'complexId' => (int) $row->complex_id,
                'expected' => round((float) $row->total_amount, 2),
                'actual' => round((float) $row->paid_amount, 2),
            ])
            ->all();
    }

    /** کشِ واحدهای ناهمخوان را از قبض‌ها دوباره می‌شمارد؛ شمارِ ترمیم‌شده‌ها */
    public function repairUnitBalances(?int $complexId = null): int
    {
        $drift = $this->unitBalanceDrift($complexId);

        foreach ($drift as $row) {
            Unit::withoutGlobalScopes()->withTrashed()->find($row['id'])?->recalculateBalance();
        }

        return count($drift);
    }
}
