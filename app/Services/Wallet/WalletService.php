<?php

namespace App\Services\Wallet;

use App\Exceptions\DomainException;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * کیفِ پولِ **شخصی** (R22؛ مالکیت از مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶؛ مدلِ نهایی
 * با تصمیمِ کارفرما در ۱۸ مهر).
 *
 * ─── یک کیف برای هر حساب ──────────────────────────────────────────────────
 * کیفِ canonical خودِ **حساب** است: دفتر با `user_id` ِ اجباری، و مانده جمعِ
 * **همه‌ی** ردیف‌های همان حساب — در هر مجتمعی که ثبت شده باشند. هر داشبوردی که
 * کاربر در آن است همین یک عدد را نشان می‌دهد و خرج در یکی، در همه کم می‌شود.
 * جدولِ «کیف» ِ جدایی نیست، پس دو کیف برای یک حساب اصلاً قابلِ ساختن نیست.
 *
 * `complex_id` و `unit_id` ِ هر ردیف فقط «کجا» را ثبت می‌کنند (مازادِ کدام
 * پرداخت، پرداختِ کدام قبض)؛ در مانده نقشی ندارند. پایانِ عضویت، پیوستن به
 * مجتمعِ دیگر و تغییرِ شماره صاحب را عوض نمی‌کنند.
 *
 * ⚠️ مدلِ پیشین (`00ac40a`): «جیبِ هر مجتمع» — قبضِ مجتمعِ ب از اعتبارِ مجتمعِ
 * الف پرداخت نمی‌شد. کارفرما آن را رد کرد. داده عوض نشد: مالکیت از پیش با
 * `user_id` بود و جمعِ جیب‌ها دقیقاً همین مانده‌ی تازه است.
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
    /** مانده‌ی کیفِ حساب — همیشه از دفتر، در همه‌ی مجتمع‌ها. */
    public function balance(User $owner): float
    {
        $rows = WalletTransaction::withoutGlobalScopes()
            ->where('user_id', $owner->id)
            ->selectRaw('direction, COALESCE(SUM(amount), 0) as total')
            ->groupBy('direction')
            ->pluck('total', 'direction');

        return (float) ($rows[WalletTransaction::CREDIT] ?? 0)
            - (float) ($rows[WalletTransaction::DEBIT] ?? 0);
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
     * اگر موجودیِ کیف کافی نباشد استثنا می‌دهد و **هیچ ردیفی ثبت نمی‌شود**.
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
     * پرداختِ یک قبض از کیفِ **خودِ پرداخت‌کننده** — در هر مجتمعی که قبضِ مجازش باشد.
     *
     * اجازه‌ی پرداختِ این قبض را کنترلر با `BillPolicy::pay` سنجیده است و همین‌جا
     * زیرِ قفل دوباره سنجیده می‌شود. سه نوشتن با هم: برداشت، `paid_amount` ِ قبض،
     * و ردیفِ دفتر (با `complex_id` و `bill_id` ِ همان قبض).
     *
     * ─── تکرارِ درخواست (idempotency) ───────────────────────────────────────
     * با `$idempotencyKey` ِ یکسان (کلیکِ دوباره، retry ِ شبکه) ردیفِ دوم ساخته
     * نمی‌شود و همان پرداختِ قبلی برگردانده می‌شود. کلید زیرِ همان قفلِ حساب
     * خوانده می‌شود؛ ایندکسِ یکتای `(user_id, idempotency_key)` پشتوانه‌ی دیتابیسی است.
     *
     * @return float مبلغی که واقعاً پرداخت شد
     */
    public function payBill(User $payer, Bill $bill, ?float $amount = null, ?string $idempotencyKey = null): float
    {
        return DB::transaction(function () use ($payer, $bill, $amount, $idempotencyKey): float {
            // ترتیبِ قفل: کاربر ← قبض
            User::withoutGlobalScopes()->lockForUpdate()->findOrFail($payer->id);

            if ($idempotencyKey !== null) {
                $previous = WalletTransaction::withoutGlobalScopes()
                    ->where('user_id', $payer->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($previous !== null) {
                    // همان کلید برای قبضِ دیگر یعنی استفاده‌ی نادرست، نه تکرار
                    if ($previous->bill_id !== $bill->id) {
                        throw DomainException::invalid('این شناسه‌ی درخواست برای پرداختِ دیگری به کار رفته است.', 'wallet.idempotency_conflict');
                    }

                    return (float) $previous->amount;
                }
            }

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

            $balance = $this->balance($payer);

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
                $idempotencyKey,
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

            if ($direction === WalletTransaction::DEBIT && $this->balance($owner) < $amount) {
                throw DomainException::invalid('موجودی کیف پول کافی نیست.', 'wallet.insufficient_funds');
            }

            return $this->writeRow($owner, $unit, $direction, $amount, $source, $payment, $bill, $note);
        }, attempts: 3);
    }

    /**
     * نوشتنِ ردیفِ دفتر. **فقط از داخلِ تراکنشِ قفل‌دار صدا زده می‌شود.**
     *
     * `balance_after` مانده‌ی **کلِ کیفِ حساب** است، با همان مانده‌ای که زیرِ قفل
     * خوانده شده. (ردیف‌های پیش از مدلِ یک‌کیفی عکسِ جیبِ خودشان‌اند و دست
     * نمی‌خورند؛ صورت‌حساب مانده‌ی هر ردیف را از خودِ دفتر می‌سازد.)
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
        ?string $idempotencyKey = null,
    ): WalletTransaction {
        $balance = $this->balance($owner);

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
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
