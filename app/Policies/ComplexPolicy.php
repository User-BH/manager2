<?php

namespace App\Policies;

use App\Models\Complex;
use App\Models\User;
use App\Support\ComplexResolver;

/**
 * عملیاتِ سطحِ مجتمع (R27).
 *
 * فعلاً فقط یک مورد دارد و آن هم عمداً اینجاست و نه در کنترلر: قاعده‌ی
 * معماریِ پروژه می‌گوید ۴۰۳ باید از Policy بیاید
 * (`ArchitectureTest::test_authorization_uses_policies_not_scattered_aborts`).
 */
class ComplexPolicy
{
    /**
     * ارسالِ پیامکِ یادآوریِ شارژ.
     *
     * فقط مدیرِ **همین** مجتمع. ادمینِ کل عمداً بیرون است: سهمیه‌ی ماهانه
     * مالِ مجتمع است و کسی که مسئولِ ساختمان نیست نباید مصرفش کند.
     * محدودیت‌های دیگر (سهمیه، ثبتِ هزینه) قاعده‌ی کسب‌وکارند و در
     * `ChargeReminderCampaign` اعمال می‌شوند، نه اینجا.
     */
    public function sendSmsCampaign(User $user, Complex $complex): bool
    {
        return $user->isAdmin() && $complex->id === ComplexResolver::idFor($user);
    }
}
