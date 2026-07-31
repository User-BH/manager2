<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\ComplexResolver;

/**
 * دسترسی به حسابِ کاربرانِ دیگر — مدیران و ساکنان.
 *
 * جایگزینِ متدهای `guard()` در `ManagerController` و `ResidentController` که
 * هرکدام نسخه‌ی کمی متفاوتِ خودشان را از یک قاعده داشتند. همان واگرایی که
 * Policy برای جلوگیری‌اش هست: دو پیاده‌سازی از یک قاعده، دیر یا زود یکی‌شان
 * به‌روز نمی‌شود.
 */
class UserPolicy
{
    /**
     * مدیریتِ یک مدیرِ مجتمع.
     *
     * ادمینِ کل به همه دسترسی دارد؛ مدیرِ مجتمع فقط به مدیرانِ مجتمعِ خودش.
     */
    public function manageManager(User $user, User $manager): bool
    {
        if ($manager->role !== UserRole::ComplexAdmin) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $manager->complex_id === $user->complex_id;
    }

    /** مدیریتِ یک ساکن: باید در همان مجتمعِ جاری باشد. */
    public function manageResident(User $user, User $resident): bool
    {
        return $resident->complex_id === ComplexResolver::idFor($user);
    }
}
