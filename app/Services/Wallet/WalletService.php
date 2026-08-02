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

/**
 * کیفِ پولِ واحد (R22).
 *
 * ─── قاعده‌ی اول: مانده ذخیره نمی‌شود ──────────────────────────────────────
 * مانده همیشه از جمعِ دفتر حساب می‌شود. ستونِ مانده — هر جا که باشد — دیر یا
 * زود با دفتر فرق می‌کند و آن‌وقت هیچ‌کس نمی‌داند کدام درست است.
 *
 * ─── قاعده‌ی دوم: هر نوشتن زیرِ قفلِ واحد ──────────────────────────────────
 * برداشت باید «مانده را بخوان، کم کن، بنویس» انجام دهد و این دقیقاً همان
 * الگویی است که در همزمانی می‌شکند: دو برداشتِ هم‌زمان هر دو مانده‌ی قدیمی
 * را می‌بینند و کیف منفی می‌شود. ردیفِ **واحد** قفل می‌شود چون تنها ردیفی
 * است که قطعاً وجود دارد؛ روی جمعِ یک جدول نمی‌شود قفل گرفت.
 */
class WalletService
{
    /** مانده‌ی کیف — همیشه از دفتر، هرگز از یک ستونِ ذخیره‌شده. */
    public function balance(Unit $unit): float
    {
        $rows = WalletTransaction::withoutGlobalScopes()
            ->where('unit_id', $unit->id)
            ->selectRaw('direction, COALESCE(SUM(amount), 0) as total')
            ->groupBy('direction')
            ->pluck('total', 'direction');

        return (float) ($rows[WalletTransaction::CREDIT] ?? 0)
            - (float) ($rows[WalletTransaction::DEBIT] ?? 0);
    }

    /** افزودن به کیف. */
    public function credit(
        Unit $unit,
        float $amount,
        string $source,
        ?Payment $payment = null,
        ?string $note = null,
    ): WalletTransaction {
        return $this->record($unit, WalletTransaction::CREDIT, $amount, $source, $payment, null, $note);
    }

    /**
     * برداشت از کیف.
     *
     * اگر موجودی کافی نباشد استثنا می‌دهد و **هیچ ردیفی ثبت نمی‌شود** —
     * کیفِ منفی یعنی اعتبارِ ساختگی که هیچ‌کس پشتش پول نگذاشته.
     */
    public function debit(
        Unit $unit,
        float $amount,
        string $source,
        ?Bill $bill = null,
        ?string $note = null,
    ): WalletTransaction {
        return $this->record($unit, WalletTransaction::DEBIT, $amount, $source, null, $bill, $note);
    }

    /**
     * پرداختِ یک قبض از موجودیِ کیف.
     *
     * سه نوشتن دارد که **باید با هم انجام شوند**: برداشت از کیف، بالا بردنِ
     * `paid_amount` قبض، و ردیفِ دفترِ تخصیص (R15). اگر یکی انجام شود و
     * بقیه نه، یا پول گم می‌شود یا قبضِ پرداخت‌نشده پرداخت‌شده به نظر می‌رسد.
     *
     * @return float مبلغی که واقعاً پرداخت شد
     */
    public function payBill(Unit $unit, Bill $bill, ?float $amount = null): float
    {
        if ($bill->unit_id !== $unit->id) {
            throw DomainException::invalid(
                'این قبض متعلق به این واحد نیست.',
                'wallet.bill_mismatch',
            );
        }

        return DB::transaction(function () use ($unit, $bill, $amount): float {
            // قفلِ واحد، همه‌ی عملیاتِ کیفِ این واحد را پشتِ سرِ هم می‌کند
            Unit::withoutGlobalScopes()->lockForUpdate()->findOrFail($unit->id);

            $fresh = Bill::withoutGlobalScopes()->lockForUpdate()->findOrFail($bill->id);

            $remaining = $fresh->remaining();

            if ($remaining <= 0) {
                throw DomainException::invalid(
                    'این قبض بدهی باز ندارد.',
                    'wallet.bill_settled',
                );
            }

            $balance = $this->balance($unit);

            if ($balance <= 0) {
                throw DomainException::invalid(
                    'موجودی کیف پول شما صفر است.',
                    'wallet.insufficient_funds',
                );
            }

            /*
             * مبلغِ پرداخت از سه چیز کمتر است: خواسته‌ی کاربر، بدهیِ قبض، و
             * موجودی. این‌طور نه بیشتر از بدهی پرداخت می‌شود (که اعتبارِ
             * سرگردان می‌سازد) و نه بیشتر از موجودی (که کیف را منفی می‌کند).
             */
            $pay = min($amount ?? $remaining, $remaining, $balance);

            if ($pay <= 0) {
                throw DomainException::invalid('مبلغ پرداخت معتبر نیست.', 'wallet.invalid_amount');
            }

            $this->writeRow(
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

            /*
             * ⚠️ در `payment_allocations` (دفترِ R15) چیزی نوشته نمی‌شود.
             *
             * آن جدول کلیدِ یکتای `(payment_id, bill_id)` دارد و برای پرداختِ
             * کیفی اصلاً ردیفِ `Payment` وجود ندارد؛ با `payment_id = null`
             * یکتایی در بیشترِ دیتابیس‌ها اعمال نمی‌شود و ردیف‌های تکراری
             * ساخته می‌شد.
             *
             * لازم هم نیست: همین ردیفِ دفترِ کیف که `bill_id` دارد، **خودش**
             * می‌گوید کدام پول روی کدام قبض نشست. هر دو دفتر جمعشان با
             * `paid_amount` می‌خواند و تست همین را می‌سنجد.
             */

            return $pay;
        }, attempts: 3);
    }

    /* ── درونی ──────────────────────────────────────────────────────────── */

    private function record(
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

        return DB::transaction(function () use ($unit, $direction, $amount, $source, $payment, $bill, $note) {
            Unit::withoutGlobalScopes()->lockForUpdate()->findOrFail($unit->id);

            if ($direction === WalletTransaction::DEBIT && $this->balance($unit) < $amount) {
                throw DomainException::invalid(
                    'موجودی کیف پول کافی نیست.',
                    'wallet.insufficient_funds',
                );
            }

            return $this->writeRow($unit, $direction, $amount, $source, $payment, $bill, $note);
        }, attempts: 3);
    }

    /**
     * نوشتنِ ردیفِ دفتر. **فقط از داخلِ تراکنشِ قفل‌دار صدا زده می‌شود.**
     *
     * `balance_after` اینجا و با همان مانده‌ای که زیرِ قفل خوانده شده حساب
     * می‌شود، وگرنه عکسِ لحظه‌ای با دفتر نمی‌خواند.
     */
    private function writeRow(
        Unit $unit,
        string $direction,
        float $amount,
        string $source,
        ?Payment $payment,
        ?Bill $bill,
        ?string $note,
    ): WalletTransaction {
        $balance = $this->balance($unit);

        $after = $direction === WalletTransaction::CREDIT
            ? $balance + $amount
            : $balance - $amount;

        return WalletTransaction::create([
            'complex_id' => $unit->complex_id,
            'unit_id' => $unit->id,
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

    /** آیا این کاربر اجازه‌ی کارکردن با کیفِ این واحد را دارد؟ */
    public function isAccessibleBy(Unit $unit, User $user): bool
    {
        if ($user->role->isAdmin()) {
            return $unit->complex_id === $user->complex_id;
        }

        return $user->units()->whereKey($unit->id)->exists();
    }
}
