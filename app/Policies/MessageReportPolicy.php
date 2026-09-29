<?php

namespace App\Policies;

use App\Models\MessageReport;
use App\Models\User;

/**
 * صفِ گزارش‌های پیام (مرحلهٔ ۶ · فاز ۱۴) — کارِ مدیریتِ مجتمع.
 *
 * جداسازیِ مجتمع را `ComplexScope` انجام می‌دهد؛ اینجا فقط نقش.
 */
class MessageReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function resolve(User $user, MessageReport $report): bool
    {
        return $user->isAdmin();
    }
}
