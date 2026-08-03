<?php

namespace App\Policies;

use App\Models\ServiceRequest;
use App\Models\User;

/**
 * درخواست‌های ساکنین (R25).
 *
 * ─── چرا `view` به همان اسکوپِ فهرست تکیه می‌کند ────────────────────────────
 * اگر این بررسی قاعده‌ی خودش را می‌نوشت، دیر یا زود با `scopeVisibleTo`
 * واگرا می‌شد و کاربر چیزی را در فهرست می‌دید که بازکردنش ۴۰۳ می‌داد (یا
 * بدتر: برعکس). همان الگوی `AnnouncementPolicy`.
 */
class ServiceRequestPolicy
{
    /** هر کاربرِ عضوِ مجتمع فهرستِ خودش را دارد؛ محتوایش را اسکوپ تعیین می‌کند. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ServiceRequest $request): bool
    {
        return ServiceRequest::query()
            ->visibleTo($user)
            ->whereKey($request->getKey())
            ->exists();
    }

    /** ثبتِ درخواست کارِ ساکن است؛ مدیر هم می‌تواند از طرفِ واحدی ثبت کند. */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * تغییرِ وضعیت یا نوشتنِ پیام.
     *
     * همان دایره‌ی دید است: مدیر، صاحبِ درخواست، و مسئولِ آن. اینکه **کدام**
     * تغییر مجاز است کارِ `ServiceRequestService` است، نه اینجا — این فقط
     * می‌گوید طرف اصلاً حق دارد به این پرونده دست بزند.
     */
    public function update(User $user, ServiceRequest $request): bool
    {
        return $this->view($user, $request);
    }

    /** واگذاری و یادداشتِ داخلی، تصمیمِ مدیریتی‌اند. */
    public function assign(User $user, ServiceRequest $request): bool
    {
        return $user->role->isAdmin();
    }

    /** درخواست پاک نمی‌شود، رد می‌شود — تا سابقه بماند. حذف فقط دستِ مدیر. */
    public function delete(User $user, ServiceRequest $request): bool
    {
        return $user->role->isAdmin();
    }
}
