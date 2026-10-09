<?php

namespace App\Services\Payment;

use App\Enums\BillStatus;
use App\Enums\PaymentStatus;
use App\Events\PaymentReviewed;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    /**
     * مازادِ پرداختی که در تخصیص جا نشد، تا **پس از** تراکنش به کیف برود.
     *
     * ⚠️ عمداً بیرون از تراکنشِ تخصیص ثبت می‌شود. `WalletService` روی
     * `Unit` قفل می‌گیرد و تخصیص روی `Bill`؛ نوشتنش داخلِ همان تراکنش
     * زنجیره‌ی `Payment → Bill → Unit` می‌ساخت که با `Unit → Bill` در
     * `WalletService::payBill` **چرخهٔ بن‌بست** درست می‌کرد. تستِ
     * ترتیبِ قفل در `ConcurrencyTest` دقیقاً همین را می‌گیرد.
     *
     * @var array{unit_id: int, amount: float}|null
     */
    private ?array $surplus = null;

    /**
     * Mark a payment successful and allocate its amount to the unit's bills,
     * starting with the linked bill (if any) then oldest unpaid bills.
     */
    public function settle(Payment $payment, ?User $actor = null, ?string $note = null): void
    {
        /*
         * آرگومانِ دومِ `transaction()` تعدادِ تلاش است.
         *
         * حالا که قبض‌ها با `lockForUpdate` قفل می‌شوند، دو تاییدِ هم‌زمان روی
         * واحدهایی با قبض‌های مشترک می‌توانند به بن‌بست (deadlock) بخورند.
         * دیتابیس یکی را قربانی می‌کند و لاراول با این عدد همان را دوباره
         * اجرا می‌کند — بی‌آنکه کاربر خطا ببیند.
         */
        $settled = DB::transaction(function () use ($payment, $actor, $note): bool {
            /*
             * وضعیت را **داخلِ قفل** دوباره می‌خوانیم.
             *
             * بدونِ این، دو درخواستِ هم‌زمانِ «تایید» هر دو وضعیتِ `pending` را
             * می‌دیدند و هر دو تخصیص می‌دادند: یک پرداخت، دو بار روی بدهی
             * می‌نشست. این همان الگوی «بررسی کن، بعد عمل کن» است که در شرایط
             * مسابقه می‌شکند.
             */
            $fresh = Payment::withoutGlobalScopes()->lockForUpdate()->find($payment->id);

            if (! $fresh || $fresh->status === PaymentStatus::Success) {
                return false;
            }

            $fresh->status = PaymentStatus::Success;
            $fresh->paid_at ??= now();
            if ($actor) {
                $fresh->reviewed_by = $actor->id;
                $fresh->reviewed_at = now();
            }
            if ($note !== null) {
                $fresh->review_note = $note;
            }
            $fresh->save();

            $this->allocate($fresh);

            $this->log($fresh, 'payment.settled', $actor, 'تایید/تسویه پرداخت');

            $payment->setRawAttributes($fresh->getAttributes(), true);

            return true;
        }, attempts: 3);

        // تاییدِ تکراری نباید اعلانِ دوباره بفرستد
        if (! $settled) {
            return;
        }

        $this->creditSurplus($payment);

        /*
         * بعد از تراکنش و نه داخلش: اگر اعلان داخلِ تراکنش می‌رفت و تراکنش
         * برمی‌گشت، کاربر اعلانِ «تایید شد» می‌گرفت برای پرداختی که ثبت نشده.
         */
        PaymentReviewed::dispatch($payment, approved: true, note: $note);
    }

    public function reject(Payment $payment, ?User $actor = null, ?string $note = null): void
    {
        /*
         * رد کردن هم باید اتمیک باشد: تغییرِ وضعیت و ثبتِ لاگ یا هر دو انجام
         * می‌شوند یا هیچ‌کدام. پیش از این بیرونِ تراکنش بود و یک شکستِ میانی
         * می‌توانست رسیدِ ردشده‌ی بی‌لاگ به جا بگذارد.
         */
        DB::transaction(function () use ($payment, $actor, $note): void {
            $payment->status = PaymentStatus::Rejected;
            $payment->reviewed_by = $actor?->id;
            $payment->reviewed_at = now();
            $payment->review_note = $note;
            $payment->save();

            $this->log($payment, 'payment.rejected', $actor, 'رد رسید پرداخت');
        }, attempts: 3);

        PaymentReviewed::dispatch($payment, approved: false, note: $note);
    }

    /** Spread a successful payment across the unit's outstanding bills. */
    /**
     * مازادِ پرداخت را به کیفِ واحد اعتبار می‌دهد.
     *
     * ⚠️ اگر این نبود، مبلغ **گم می‌شد**: نه روی قبضی می‌نشست، نه در
     * دفتری ثبت می‌شد، در حالی که از حسابِ ساکن کم شده بود.
     */
    private function creditSurplus(Payment $payment): void
    {
        $surplus = $this->surplus;
        $this->surplus = null;

        if ($surplus === null) {
            return;
        }

        $unit = Unit::withoutGlobalScopes()->find($surplus['unit_id']);
        // کیف مالِ پرداخت‌کننده است، نه واحد (مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶)
        $payer = $payment->user_id !== null ? User::withoutGlobalScopes()->find($payment->user_id) : null;

        if (! $unit || ! $payer) {
            report(new \RuntimeException("مازادِ پرداختِ #{$payment->id} صاحبِ قطعی نداشت."));

            return;
        }

        app(WalletService::class)->credit(
            $payer,
            $unit,
            $surplus['amount'],
            WalletTransaction::SOURCE_PAYMENT_SURPLUS,
            $payment,
            'مازادِ پرداخت #'.$payment->id,
        );
    }

    protected function allocate(Payment $payment): void
    {
        $remaining = (float) $payment->amount;

        $bills = collect();
        if ($payment->bill_id) {
            $linked = Bill::withoutGlobalScopes()->lockForUpdate()->find($payment->bill_id);
            if ($linked) {
                $bills->push($linked);
            }
        }

        /*
         * `lockForUpdate` اینجا حیاتی است.
         *
         * `paid_amount` خوانده، در PHP جمع، و دوباره نوشته می‌شود. دو تاییدِ
         * هم‌زمان روی یک واحد، هر دو مقدارِ قدیمی را می‌خواندند و نوشتنِ دومی
         * اولی را پاک می‌کرد — یعنی یک پرداختِ کامل بی‌صدا از حساب می‌افتاد
         * (lost update). قفل، این دو را پشتِ سرِ هم می‌کند.
         *
         * توجه: SQLite قفل را نادیده می‌گیرد، پس این محافظت فقط روی MySQLِ
         * محصول واقعی است و تستِ همزمانی نمی‌تواند اثباتش کند.
         */
        $others = Bill::withoutGlobalScopes()
            ->where('unit_id', $payment->unit_id)
            ->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial])
            ->when($payment->bill_id, fn ($q) => $q->where('id', '!=', $payment->bill_id))
            ->orderBy('due_date')
            ->orderBy('period')
            ->lockForUpdate()
            ->get();

        foreach ($bills->concat($others) as $bill) {
            if ($remaining <= 0) {
                break;
            }
            $due = $bill->remaining();
            if ($due <= 0) {
                continue;
            }
            $apply = min($due, $remaining);
            $bill->paid_amount = (float) $bill->paid_amount + $apply;
            $bill->syncStatus();
            $remaining -= $apply;

            /*
             * ثبتِ دفتر: «از این پرداخت، این مقدار روی این قبض نشست».
             *
             * بدونِ این ردیف، `paid_amount` عددی بود که کسی نمی‌دانست از کجا
             * آمده. `updateOrCreate` استفاده می‌شود تا اگر تسویه به هر دلیلی
             * دوباره اجرا شود، ردیفِ تکراری نسازد.
             */
            PaymentAllocation::updateOrCreate(
                ['payment_id' => $payment->id, 'bill_id' => $bill->id],
                ['complex_id' => $payment->complex_id, 'amount' => $apply],
            );
        }

        /*
         * ⚠️ مازاد به کیف پول می‌رود، نه به هیچ‌جا (R51).
         *
         * ─── پولی که گم می‌شد ──────────────────────────────────────────────
         * پیش از این، وقتی حلقه‌ی بالا تمام می‌شد و `$remaining > 0` می‌ماند،
         * آن مبلغ **دور ریخته می‌شد**. کامنتِ قبلی می‌گفت «مازاد از مانده‌ی
         * کش‌شده کم می‌شود» ولی `recalculateBalance()` فقط بدهیِ واحد را از
         * روی قبض‌ها بازمی‌شمارد — هیچ ربطی به کیفِ پول ندارد.
         *
         * سنجشِ واقعی: دو پرداختِ ۱۰۰٬۰۰۰ روی قبضِ ۱۰۰٬۰۰۰ → قبض درست
         * ۱۰۰٬۰۰۰ ثبت شد، ولی موجودیِ کیف **صفر** ماند. یعنی صد هزار تومان
         * از حسابِ ساکن رفته بود و در هیچ دفتری نبود.
         *
         * ─── چرا اعتبار به کیف و نه بازگشتِ خودکار ──────────────────────────
         * بازگرداندنِ پول به کارت نیازمندِ تماس با درگاه و تصمیمِ انسانی
         * است. اعتبارِ کیف بی‌درنگ، قابلِ‌ردیابی و قابلِ‌استفاده برای قبضِ
         * بعدی است — و مهم‌تر، **در دفتر ثبت می‌شود**، پس دیگر گم نمی‌شود.
         *
         * ⚠️ ترتیبِ قفل عمداً حفظ شده. `WalletService::record()` روی
         * `Unit` قفل می‌گیرد و ما اینجا از قبل `Bill` را قفل کرده‌ایم؛
         * اگر این فراخوانی **پیش از** حلقه بود، زنجیره `Unit → Bill`
         * می‌شد و با `payBill` که همان ترتیب را دارد سازگار می‌ماند — ولی
         * `settle` زنجیره‌ی `Payment → Bill` دارد و افزودنِ `Unit` پس از
         * `Bill` چرخه می‌ساخت. پس اعتبار **بیرون از تراکنشِ تخصیص** و پس
         * از آزادشدنِ قفل‌ها ثبت می‌شود.
         */
        Unit::withoutGlobalScopes()->find($payment->unit_id)?->recalculateBalance();

        if ($remaining > 0.009) {
            $this->surplus = ['unit_id' => $payment->unit_id, 'amount' => round($remaining, 2)];
        }
    }

    protected function log(Payment $payment, string $action, ?User $actor, string $description): void
    {
        AuditLog::create([
            'complex_id' => $payment->complex_id,
            'user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => Payment::class,
            'subject_id' => $payment->id,
            'description' => $description,
            'ip_address' => request()->ip(),
            'properties' => ['amount' => $payment->amount, 'method' => $payment->method->value],
            'created_at' => now(),
        ]);
    }
}
