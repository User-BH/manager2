<?php

namespace App\Policies;

use App\Models\Backup;
use App\Models\User;
use App\Support\ComplexResolver;

/**
 * بکاپ‌ها: حساس‌ترین فایل‌های سامانه.
 *
 * یک فایلِ بکاپ کلِ داده‌ی یک مجتمع را دارد، پس دانلودش باید سخت‌گیرانه‌ترین
 * بررسی را داشته باشد — و بکاپِ «کل سیستم» فقط برای ادمینِ کل است.
 */
class BackupPolicy
{
    public function download(User $user, Backup $backup): bool
    {
        if ($backup->type === 'full') {
            return $user->isSuperAdmin();
        }

        return $user->isAdmin() && $backup->complex_id === ComplexResolver::idFor($user);
    }
}
