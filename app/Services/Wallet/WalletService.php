<?php

namespace App\Services\Wallet;

use App\Exceptions\DomainException;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * کیفِ پولِ **شخصی** (R22؛ مالکیت از مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶).
 *
 * ─── مالکیت: حساب، نه واحد ─────────────────────────────────────────────────
 * تصمیمِ کارفرما: کیف مالِ حسابِ شخص است. پایانِ عضویت آن را نه حذف می‌کند،
 * نه صفر، نه به کسی منتقل؛ ساکنِ تازه‌ی همان واحد هیچ راهی به آن ندارد؛ و پس
 * از پیوستن به مجتمعِ دیگر هم همان کیفِ خودش است.
 *
 * ─── جیب‌ها: (صاحب، مجتمع) ────────────────────────────────────────────────
 * هر ریالِ کیف از یک مجتمع آمده (مازادِ پرداخت به حسابِ همان مجتمع، کاهشِ قبضِ
 * همان مجتمع). پس مانده به تفکیکِ مجتمع حساب می‌شود و قبضِ هر مجتمع فقط از جیبِ
 * همان مجتمع پرداخت می‌شود: خرج‌کردنِ اعتبارِ مجتمعِ الف برای قبضِ مجتمعِ ب
 * یعنی «ادغامِ» پولی که مجتمعِ ب هرگز نگرفته — همان چیزی که تصمیمِ کارفرما منع
 * کرده. صاحب همه‌ی جیب‌هایش را می‌بیند، حتی پس از رفتن.
 *
 * ─── قاعده‌ی اول: مانده ذخیره نمی‌شود ──────────────────────────────────────
 * مانده همیشه از جمعِ دفتر حساب می‌شود (Balance ↔ Ledger).
 *
 * ─── قاعده‌ی دوم: هر نوشتن زیرِ قفلِ صاحب ──────────────────────────────────
 * «مانده را بخوان، کم کن، بنویس» در همزمانی می‌شکند؛ ردیفِ **کاربرِ صاحب** قفل
 * می‌شود چون تنها ردیفی است که قطعاً وجود دارد. ترتیبِ قفل در پرداختِ قبض:
 * کاربر ← قبض.
 */
class WalletService
{
    /** مانده‌ی جیبِ (صاحب، مجتمع) — همیشه از دفتر. */
    public function balance(User $owner, int $complexId): float
    {
        $rows = WalletTransaction::withoutGlobalScopes()
            ->where('user_id', $owner->id)
            ->where('complex_id', $complexId)
            ->selectRaw('direction, COALESCE(SUM(amount), 0) as total')
            ->groupBy('direction')
            ->pluck('total', 'direction');

        return (float) ($rows[WalletTransaction::CREDIT] ?? 0)
            - (float) ($rows[WalletTransaction::DEBIT] ?? 0);
    }

    /**
     * همه‌ی جیب‌های یک شخص — برای صفحه‌ی کیفِ خودش، حتی بی عضویت.
     *
     * @return list<array{complexId: int, complexName: string|null, balance: float}>
     */
    public function pockets(User $owner): array
    {
        $complexIds = WalletTransaction::withoutGlobalScopes()
            ->where('user_id', $owner->id)
            ->distinct()
            ->pluck('complex_id');

        $names = Complex::withoutGlobalScopes()->whereIn('id', $complexIds)->pluck('name', 'id');

        return $complexIds
            ->map(fn ($id) => [
                'complexId' => (int) $id,
                'complexName' => $names[$id] ?? null,
                'balance' => $this->balance($owner, (int) $id),
            ])
            ->sortBy('complexId')
            ->values()
            ->all();
    }

    /** افزودن به کیفِ صاحب؛ `$unit` فقط «کجا» را ثبت می‌کند. */
    public function credit(
        User $owner,
        Unit $unit,
        float $amount,
        string $source,
        ?Payment $payment = null,
        ?string $note = null,
    ): WalletTransaction {
        return $this->record($owner, $unit, WalletTransaction::CREDIT, $amount, $source, $payment, null, $note);
    }

    /**
     * برداشت از کیفِ صاحب.
     *
     * اگر موجودیِ همان جیب کافی نباشد استثنا می‌دهد و **هیچ ردیفی ثبت نمی‌شود**.
     */
    public function debit(
        User $owner,
        Unit $unit,
        float $amount,
        string $source,
        ?Bill $bill = null,
        ?string $note = null,
    ): WalletTransaction {
        return $this->record($owner, $unit, WalletTransaction::DEBIT, $amount, $source, null, $bill, $note);
    }

    /**
     * پرداختِ یک قبض از کیفِ **خودِ پرداخت‌کننده** (جیبِ همان مجتمع).
     *
     * اجازه‌ی پرداختِ این قبض را کنترلر با `BillPolicy::pay` سنجیده است.
     * سه نوشتن با هم: برداشت، `paid_amount` ِ قبض، و ردیفِ دفتر.
     *
     * @return float مبلغی که واقعاً پرداخت شد
     */
    public function payBill(User $payer, Bill $bill, ?float $amount = null): float
    {
        return DB::transaction(function () use ($payer, $bill, $amount): float {
            // ترتیبِ قفل: کاربر ← قبض
            User::withoutGlobalScopes()->lockForUpdate()->findOrFail($payer->id);

            $fresh = Bill::withoutGlobalScopes()->lockForUpdate()->findOrFail($bill->id);
            $unit = Unit::withoutGlobalScopes()->findOrFail($fresh->unit_id);

            // لایه‌ی دوم پشتِ کنترلر: فقط قبضِ دوره‌ی سکونتِ خودِ پرداخت‌کننده (`BillPolicy::pay`)
            if (! Gate::forUser($payer)->allows('pay', $fresh)) {
                throw DomainException::invalid('این قبض متعلق به واحدِ شما نیست.', 'wallet.bill_mismatch');
            }

            $remaining = $fresh->remaining();

            if ($remaining <= 0) {
                throw DomainException::invalid('این قبض بدهی باز ندارد.', 'wallet.bill_settled');
            }

            $balance = $this->balance($payer, $fresh->complex_id);

            if ($balance <= 0) {
                throw DomainException::invalid('موجودی کیف پول شما صفر است.', 'wallet.insufficient_funds');
            }

            /*
             * مبلغِ پرداخت از سه چیز کمتر است: خواسته‌ی کاربر، بدهیِ قبض، و
             * موجودی. نه بیشتر از بدهی (اعتبارِ سرگردان) و نه بیشتر از موجودی.
             */
            $pay = min($amount ?? $remaining, $remaining, $balance);

            if ($pay <= 0) {
                throw DomainException::invalid('مبلغ پرداخت معتبر نیست.', 'wallet.invalid_amount');
            }

            $this->writeRow(
                $payer,
                $unit,
                WalletTransaction::DEBIT,
                $pay,
                WalletTransaction::SOURCE_BILL_PAYMENT,
                null,
                $fresh,
                'پرداخت قبض '.$fresh->period,
            );

            $fresh->paid_amount = (float) $fresh->paid_amount + $pay;
            $fresh->syncStatus();
            $fresh->save();

            // ⚠️ در `payment_allocations` چیزی نوشته نمی‌شود: ردیفِ دفترِ کیف با
            // `bill_id` خودش می‌گوید کدام پول روی کدام قبض نشست (R15).
            return $pay;
        }, attempts: 3);
    }

    /**
     * بازگرداندنِ پولِ اضافه‌ی یک قبض (کاهشِ مبلغ پس از پرداخت) به **کسانی که
     * پرداختش کردند** — نه به «واحد» (مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶).
     *
     * سهمِ هر شخص = تخصیص‌های پرداخت‌هایش روی این قبض + برداشت‌های کیفش برای آن
     * − بازگشت‌های قبلیِ همین قبض به او. بازگشت از تازه‌ترین پرداخت‌کننده شروع
     * می‌شود و از سهمِ هیچ‌کس بیشتر نمی‌شود؛ پس هر ریال به صاحبِ خودش برمی‌گردد،
     * حتی اگر ساکن عوض شده یا چند نفر پرداخته باشند.
     *
     * @return float مبلغی که بی‌صاحب ماند (باید صفر باشد؛ اگر نه، گزارش می‌شود)
     */
    public function refundBillOverpayment(Bill $bill, float $amount, string $note): float
    {
        $unit = Unit::withoutGlobalScopes()->findOrFail($bill->unit_id);

        $shares = [];
        $latest = [];
        $add = function (?int $userId, float $value, ?string $at) use (&$shares, &$latest): void {
            if ($userId === null) {
                return;
            }
            $shares[$userId] = ($shares[$userId] ?? 0.0) + $value;
            if ($at !== null && ($latest[$userId] ?? '') < $at) {
                $latest[$userId] = $at;
            }
        };

        DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payment_allocations.bill_id', $bill->id)
            ->get(['payments.user_id', 'payment_allocations.amount', 'payment_allocations.created_at'])
            ->each(fn ($row) => $add($row->user_id !== null ? (int) $row->user_id : null, (float) $row->amount, (string) $row->created_at));

        WalletTransaction::withoutGlobalScopes()->where('bill_id', $bill->id)->get()
            ->each(function (WalletTransaction $row) use ($add): void {
                $sign = match (true) {
                    $row->direction === WalletTransaction::DEBIT && $row->source === WalletTransaction::SOURCE_BILL_PAYMENT => 1.0,
                    $row->direction === WalletTransaction::CREDIT && $row->source === WalletTransaction::SOURCE_BILL_REDUCTION => -1.0,
                    default => 0.0,
                };
                if ($sign !== 0.0) {
                    $add($row->user_id, $sign * (float) $row->amount, $sign > 0 ? (string) $row->created_at : null);
                }
            });

        arsort($latest);
        $left = round($amount, 2);

        foreach (array_keys($latest) as $userId) {
            if ($left <= 0.009) {
                break;
            }

            $refund = round(min($left, $shares[$userId] ?? 0.0), 2);
            if ($refund <= 0) {
                continue;
            }

            $owner = User::withoutGlobalScopes()->find($userId);
            if ($owner === null) {
                continue;
            }

            $this->record($owner, $unit, WalletTransaction::CREDIT, $refund, WalletTransaction::SOURCE_BILL_REDUCTION, null, $bill, $note);
            $left = round($left - $refund, 2);
        }

        if ($left > 0.009) {
            report(new \RuntimeException("بازگشتِ {$left} از قبضِ #{$bill->id} صاحبِ قطعی نداشت."));
        }

        return max(0.0, $left);
    }

    /* ── درونی ──────────────────────────────────────────────────────────── */

    private function record(
        User $owner,
        Unit $unit,
        string $direction,
        float $amount,
        string $source,
        ?Payment $payment,
        ?Bill $bill,
        ?string $note,
    ): WalletTransaction {
        if ($amount <= 0) {
            throw DomainException::invalid('مبلغ باید بزرگ‌تر از صفر باشد.', 'wallet.invalid_amount');
        }

        return DB::transaction(function () use ($owner, $unit, $direction, $amount, $source, $payment, $bill, $note) {
            User::withoutGlobalScopes()->lockForUpdate()->findOrFail($owner->id);

            if ($direction === WalletTransaction::DEBIT && $this->balance($owner, $unit->complex_id) < $amount) {
                throw DomainException::invalid('موجودی کیف پول کافی نیست.', 'wallet.insufficient_funds');
            }

            return $this->writeRow($owner, $unit, $direction, $amount, $source, $payment, $bill, $note);
        }, attempts: 3);
    }

    /**
     * نوشتنِ ردیفِ دفتر. **فقط از داخلِ تراکنشِ قفل‌دار صدا زده می‌شود.**
     *
     * `balance_after` مانده‌ی همان جیب است، با همان مانده‌ای که زیرِ قفل خوانده شده.
     */
    private function writeRow(
        User $owner,
        Unit $unit,
        string $direction,
        float $amount,
        string $source,
        ?Payment $payment,
        ?Bill $bill,
        ?string $note,
    ): WalletTransaction {
        $balance = $this->balance($owner, $unit->complex_id);

        $after = $direction === WalletTransaction::CREDIT
            ? $balance + $amount
            : $balance - $amount;

        return WalletTransaction::create([
            'complex_id' => $unit->complex_id,
            'unit_id' => $unit->id,
            'user_id' => $owner->id,
            'direction' => $direction,
            'amount' => $amount,
            'balance_after' => $after,
            'source' => $source,
            'payment_id' => $payment?->id,
            'bill_id' => $bill?->id,
            'created_by' => Auth::id(),
            'note' => $note,
        ]);
    }
}
