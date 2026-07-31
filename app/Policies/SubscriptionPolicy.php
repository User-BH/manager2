<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;
use App\Support\ComplexResolver;

/**
 * اشتراک‌ها: خرید کارِ مدیرِ مجتمع است، تاییدِ رسید کارِ ادمینِ کل.
 *
 * دلیلِ این تفکیک تجاری است، نه فنی: پولِ اشتراک به حسابِ سرویس‌دهنده می‌رود،
 * پس مدیری که خودش پرداخت کرده نباید بتواند رسیدِ خودش را تایید کند.
 */
class SubscriptionPolicy
{
    public function purchase(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Subscription $subscription): bool
    {
        return $user->isAdmin() && $subscription->complex_id === ComplexResolver::idFor($user);
    }

    /** تایید/ردِ رسید — فقط ادمینِ کل. */
    public function review(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
