<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Account\ComplexMembers;
use App\Support\ComplexResolver;

/**
 * دسترسی به حسابِ کاربرانِ دیگر — مدیران و ساکنان.
 *
 * جایگزینِ متدهای `guard()` در `ManagerController` و `ResidentController` که
 * هرکدام نسخه‌ی کمی متفاوتِ خودشان را از یک قاعده داشتند. همان واگرایی که
 * Policy برای جلوگیری‌اش هست: دو پیاده‌سازی از یک قاعده، دیر یا زود یکی‌شان
 * به‌روز نمی‌شود.
 *
 * ─── از عضویت، نه از زمینه‌ی فعالِ هدف (مرحلهٔ ۱۰ · فاز ۳) ─────────────────────
 * یک حساب می‌تواند در چند مجتمع عضو باشد و `complex_id` و `role` ِ او فقط مجتمعی است
 * که **خودش** الان در آن کار می‌کند. پس قاعده عضویتِ هدف را در مجتمعِ جاریِ **کنشگر**
 * می‌سنجد: مدیرِ ب که الان در الف است همچنان مدیرِ ب است و مدیرانِ ب او را می‌بینند؛
 * کسی که در الف مالک و در ب مدیر است، در الف «مدیر» نیست.
 */
class UserPolicy
{
    /**
     * مدیریتِ یک مدیرِ مجتمع: هدف باید مدیرِ فعالِ مجتمعِ جاریِ کنشگر باشد. ادمینِ کل
     * هم از مجتمعی که انتخاب کرده؛ بی مجتمعِ انتخاب‌شده چیزی برای سنجیدن نیست.
     */
    public function manageManager(User $user, User $manager): bool
    {
        $complexId = ComplexResolver::idFor($user);

        return $complexId !== null && ComplexMembers::has($manager, $complexId, [UserRole::ComplexAdmin], withSuspended: true);
    }

    /**
     * مدیریتِ یک ساکن: مالک یا مستأجرِ مجتمعِ جاری — فعال یا معلق (۱۰/۶)؛ مدیر باید بتواند عضوِ معلق را
     * دوباره فعال کند یا عضویتش را پایان دهد. عضوِ مجتمعِ دیگر هرگز.
     */
    public function manageResident(User $user, User $resident): bool
    {
        $complexId = ComplexResolver::idFor($user);

        return $complexId !== null && ComplexMembers::has($resident, $complexId, [UserRole::Owner, UserRole::Tenant], withSuspended: true);
    }
}
