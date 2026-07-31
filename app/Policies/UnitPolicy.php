<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;
use App\Support\ComplexResolver;

/**
 * واحدها و خروجی‌های مربوط به آن‌ها (تسویه‌حساب، اکسلِ قبض‌ها).
 *
 * این‌ها فایلِ قابلِ دانلودند و محتوایشان مالیِ حساس است، پس بررسیِ مجتمع
 * جدی‌تر از یک صفحه‌ی معمولی است.
 */
class UnitPolicy
{
    public function viewStatement(User $user, Unit $unit): bool
    {
        return $user->isAdmin() && $unit->complex_id === ComplexResolver::idFor($user);
    }

    /** خروجیِ گروهیِ قبض‌ها — فقط مدیر. */
    public function export(User $user): bool
    {
        return $user->isAdmin();
    }
}
