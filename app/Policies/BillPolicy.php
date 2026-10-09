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

    /**
     * قبضِ دوره‌ای که خودِ این کاربر ساکنِ واحد بوده (مرحلهٔ ۹ · فاز ۶، F4).
     *
     * پیش از این «یک بار ساکنِ این واحد بوده» کافی بود — یعنی ساکنِ تازه با
     * شناسه‌ی قبض، قبض و پرداخت‌های ساکنِ قبلی را هم باز می‌کرد.
     */
    private function ownsUnit(User $user, Bill $bill): bool
    {
        return Bill::query()->visibleToResident($user, includePast: true)->whereKey($bill->id)->exists();
    }
}
