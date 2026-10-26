<?php

namespace App\Policies;

use App\Enums\AccountState;
use App\Enums\UserRole;
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
        /*
         * 🔴 ۱۳/۶ (ایرادات ۱۰، بندِ ۵): سوپر ادمین هیچ مسیرِ خرید، تمدید یا پرداختی ندارد. پیش از این `isAdmin()` او را هم
         * می‌پذیرفت و سوپر ادمینی که مجتمعی را برگزیده بود تمدیدِ همان مجتمع را تا درگاه می‌برد.
         */
        return $user->role === UserRole::ComplexAdmin || AccountState::of($user) === AccountState::Initial;
    }

    /**
     * «تنظیماتِ حساب» و تاریخچه‌ی پرداخت (بندِ ۲): مدیرِ مجتمعِ فعال و حسابِ اولیه. **نه** واحد — داشبوردِ واحد مسئولِ
     * خرید و اشتراک نیست — و نه سوپر ادمین، که تنظیماتِ حسابش مدیریتِ پلن‌هاست (`System\PlanController`).
     */
    public function viewAccount(User $user): bool
    {
        return $this->purchase($user);
    }

    /**
     * خریدِ **مجتمعِ تازه** (مرحلهٔ ۱۰ · فاز ۵؛ تصمیمِ کارفرما): هر حسابِ فعالِ غیرِ سکو — اولیه، یا عضوِ
     * مجتمع‌های دیگر با هر نقشی. جدا از `purchase` (تمدیدِ اشتراکِ مجتمعِ فعال) که کارِ مدیرِ همان
     * مجتمع است: مالکِ A حق ندارد اشتراکِ A را بخرد، ولی حق دارد مجتمعِ C را بخرد.
     */
    public function purchaseComplex(User $user): bool
    {
        return $user->is_active && AccountState::of($user) !== AccountState::Platform;
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
