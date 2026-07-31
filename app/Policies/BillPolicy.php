<?php

namespace App\Policies;

use App\Models\Bill;
use App\Models\User;

/**
 * قبض‌ها: ساکن فقط قبضِ واحدهای خودش را می‌بیند و می‌پردازد.
 *
 * این مهم‌ترین مرزِ حریمِ خصوصی در سامانه است: مبلغِ بدهیِ همسایه نباید با
 * دست‌کاریِ شناسه در URL دیده شود.
 */
class BillPolicy
{
    /** دیدن: صاحبِ واحد، یا مدیرِ مجتمع. */
    public function view(User $user, Bill $bill): bool
    {
        return $user->isAdmin() || $this->ownsUnit($user, $bill);
    }

    /**
     * پرداخت: **فقط** صاحبِ واحد.
     *
     * برخلافِ دیدن، مدیر اینجا اجازه ندارد؛ رسیدی که مدیر برای واحدِ دیگری
     * ثبت کند، ردِ حسابداری را مخدوش می‌کند.
     */
    public function pay(User $user, Bill $bill): bool
    {
        return $this->ownsUnit($user, $bill);
    }

    private function ownsUnit(User $user, Bill $bill): bool
    {
        return $user->units()->whereKey($bill->unit_id)->exists();
    }
}
