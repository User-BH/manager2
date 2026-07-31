<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;
use App\Support\ComplexResolver;

/**
 * اطلاعیه‌ها: همه می‌بینند، فقط مدیران می‌نویسند.
 *
 * جایگزینِ چهار `abort_unless` پراکنده در `AnnouncementController`.
 */
class AnnouncementPolicy
{
    /** ساکن هم باید اطلاعیه‌های مجتمعِ خودش را ببیند. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * دیدنِ یک اطلاعیه‌ی مشخص.
     *
     * از همان اسکوپِ `visibleTo` استفاده می‌کند که فهرست را می‌سازد، تا قاعده
     * یک‌جا بماند: اگر مخاطبِ اطلاعیه عوض شود، هم فهرست و هم این بررسی با هم
     * تغییر می‌کنند.
     */
    public function view(User $user, Announcement $announcement): bool
    {
        return Announcement::query()->visibleTo($user)->whereKey($announcement->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return $user->isAdmin() && $this->sameComplex($user, $announcement);
    }

    public function delete(User $user, Announcement $announcement): bool
    {
        return $this->update($user, $announcement);
    }

    /**
     * لایه‌ی دومِ جداسازیِ مجتمع‌ها.
     *
     * `ComplexScope` قبلاً کوئری را فیلتر کرده، ولی این بررسی مستقل است تا
     * اگر روزی آن اسکوپ دور زده شود (مثلاً با `withoutGlobalScopes`)، مدیرِ
     * یک مجتمع نتواند اطلاعیه‌ی مجتمعِ دیگری را ویرایش کند.
     */
    private function sameComplex(User $user, Announcement $announcement): bool
    {
        return $announcement->complex_id === ComplexResolver::idFor($user);
    }
}
