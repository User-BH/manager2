<?php

namespace App\Policies;

use App\Enums\AccountState;
use App\Models\Subscription;
use App\Models\User;
use App\Support\ComplexResolver;
use Illuminate\Auth\Access\Response;

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

    /**
     * رسید فقط برای اشتراکِ **مجتمعِ موجود** (مرحلهٔ ۹ · فاز ۵؛ F1، F2).
     *
     * پیش از این آپلودِ رسید حسابِ اولیه را همان لحظه مدیرِ مجتمعی تازه می‌کرد —
     * پیش از هر تأییدِ پرداخت، و با پلنِ نامعتبر حتی بی‌اشتراک. تصمیمِ قطعیِ
     * کارفرما: ساختنِ مجتمع و مدیر شدن **فقط** با پرداختِ آنلاینِ تأییدشده.
     */
    public function uploadReceipt(User $user): Response
    {
        return $user->isAdmin()
            ? Response::allow()
            : Response::deny('ساختنِ مجتمع فقط با پرداختِ آنلاین است؛ رسید برای اشتراکِ مجتمعی است که از قبل دارید.');
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
