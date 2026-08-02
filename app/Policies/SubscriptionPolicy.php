<?php

namespace App\Policies;

use App\Enums\AccountState;
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
        /*
         * خریدارِ «حالتِ اولیه» هنوز مدیر نیست — و دقیقاً همین خرید است که
         * مدیرش می‌کند (R21). بدونِ این شرط، تنها راهِ بیرون آمدن از آن حالت
         * برای کسی که مدیری ندارد تا دعوتش کند، بسته می‌ماند.
         */
        return $user->isAdmin() || AccountState::of($user) === AccountState::Initial;
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
