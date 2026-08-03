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
    /**
     * دیدنِ پرونده‌ی واحد و تاریخچه‌اش (R26).
     *
     * `resolveRouteBinding` در `BelongsToComplex` واحد را از پیش به مجتمعِ
     * جاری محدود کرده، ولی بررسی اینجا هم تکرار می‌شود: اگر روزی پرونده از
     * مسیرِ دیگری (گزارش، خروجی) خوانده شد، قاعده همراهش می‌آید.
     */
    public function view(User $user, Unit $unit): bool
    {
        return $user->isAdmin() && $unit->complex_id === ComplexResolver::idFor($user);
    }

    /** انتقالِ مالکیت و بستنِ دوره — همان دامنه، چون هر دو تغییرِ پرونده‌اند. */
    public function update(User $user, Unit $unit): bool
    {
        return $this->view($user, $unit);
    }

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
