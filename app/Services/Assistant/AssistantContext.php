<?php

namespace App\Services\Assistant;

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\User;
use App\Support\DemoData;

/**
 * زمینه‌ی یک پرسش (بندهای ۴.۸ و ۴.۹): هویتِ ثابتِ حساب + زمینه‌ی فعالِ **سرور** (مجتمع و نقشِ عضویتِ همان نشست).
 *
 * هیچ‌چیزِ این‌جا از متنِ پرسش یا بدنه‌ی درخواست نمی‌آید. پس از تعویضِ مجتمع همان حساب زمینه‌ی تازه می‌گیرد و هر ابزار
 * دامنه‌اش را از همین می‌خواند — نه از مکالمه‌ی قبلی، نه از کش.
 */
final class AssistantContext
{
    public function __construct(
        public readonly User $user,
        /** مجتمعِ فعال؛ سوپر ادمینِ بی مجتمعِ برگزیده: null (زمینه‌ی سکو) */
        public readonly ?Complex $complex,
        public readonly UserRole $role,
    ) {}

    public function isPlatform(): bool
    {
        return $this->role === UserRole::SuperAdmin && $this->complex === null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    /** مدیرِ همین مجتمع، یا سوپر ادمینی که همین مجتمع را برگزیده — همان دامنه‌ی صفحه‌های مدیریت */
    public function isManager(): bool
    {
        return $this->complex !== null && ($this->role === UserRole::ComplexAdmin || $this->role === UserRole::SuperAdmin);
    }

    public function isResident(): bool
    {
        return $this->complex !== null && in_array($this->role, [UserRole::Owner, UserRole::Tenant], true);
    }

    /** کلیدِ زمینه برای تاریخچه و کش: مجتمع و نقش، نه فقط حساب */
    public function key(): string
    {
        return ($this->complex->id ?? 'platform').':'.$this->role->value;
    }

    /** داده‌ی آزمایشی فقط بیرون از Production و فقط برای حسابِ آزمایشی (همان قاعده‌ی فهرست‌های سوپر ادمین) */
    public function includesDemoData(): bool
    {
        return DemoData::allowedHere() && (bool) $this->user->is_demo;
    }

    public function currency(): string
    {
        return $this->complex?->currencyLabel() ?? 'تومان';
    }
}
