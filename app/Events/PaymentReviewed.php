<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * رسیدِ پرداخت تعیین‌تکلیف شد (تایید یا رد).
 *
 * ─── چرا رویداد و نه صدا زدنِ مستقیم ───────────────────────────────────────
 * `PaymentService` کارش تسویه‌ی حساب است. اگر همان‌جا اعلان هم بفرستد، هر بار
 * که کانالِ تازه‌ای اضافه شود (اعلانِ درون‌برنامه‌ای، ایمیل، وب‌هوک) باید
 * سرویسِ مالی دست بخورد — سرویسی که تغییرش پرریسک‌ترین کارِ سامانه است.
 *
 * با رویداد، سرویس فقط اعلام می‌کند چه شد و نمی‌داند چه کسی گوش می‌دهد.
 */
class PaymentReviewed
{
    use Dispatchable;

    public function __construct(
        public readonly Payment $payment,
        /** `true` یعنی تایید شد، `false` یعنی رد. */
        public readonly bool $approved,
        public readonly ?string $note = null,
    ) {}
}
