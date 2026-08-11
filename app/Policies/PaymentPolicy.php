<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Support\ComplexResolver;

/**
 * پرداخت‌ها: ساکن ثبت می‌کند، مدیر بررسی می‌کند.
 *
 * تفکیکِ مهم: «بررسیِ رسید» کارِ مدیر است و «ثبتِ رسید» کارِ ساکن. اگر این دو
 * یکی می‌شدند، مدیر می‌توانست برای واحدی که مالکش نیست رسید بسازد.
 */
class PaymentPolicy
{
    public function review(User $user, Payment $payment): bool
    {
        return $user->isAdmin() && $payment->complex_id === ComplexResolver::idFor($user);
    }

    /**
     * دیدنِ **سندِ** پرداخت (R28).
     *
     * همان دامنه‌ی `viewReceipt` است و عمداً به آن تکیه می‌کند: اگر روزی
     * قاعده عوض شود، رسیدِ PDF و فایلِ آپلودشده نباید از هم واگرا شوند.
     */
    public function view(User $user, Payment $payment): bool
    {
        return $this->viewReceipt($user, $payment);
    }

    /** دیدنِ فایلِ رسید: یا صاحبش، یا مدیرِ همان مجتمع. */
    public function viewReceipt(User $user, Payment $payment): bool
    {
        if ($payment->user_id === $user->id) {
            return true;
        }

        return $this->review($user, $payment);
    }
}
