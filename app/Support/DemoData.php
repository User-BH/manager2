<?php

namespace App\Support;

use App\Models\User;

/**
 * مرزِ دادهٔ آزمایشی (مرحلهٔ ۱۳ · فاز ۱؛ «داشبورد های آزمایشی» بندِ ۴.۳، پیامِ کارفرما ۲۰۲۶-۱۰-۱۹ بخش‌های ۲ و ۳).
 *
 * دادهٔ آزمایشی در پایگاه‌داده‌ی جدای Preview زندگی می‌کند (`App\Support\Preview`). این کلاس لایه‌ی دوم است: اگر روزی
 * حسابی با نشانگرِ `is_demo` روی Production پیدا شود (بازیابیِ نادرست، کپیِ دستی)، نه وارد می‌شود و نه نشستش زنده
 * می‌ماند. نشانگر را فقط seeder ِ Preview می‌نویسد؛ client نمی‌تواند خودش را آزمایشی یا واقعی کند.
 */
final class DemoData
{
    /** حسابِ آزمایشی هرگز روی Production؛ روی Preview، توسعه و آزمون مجاز */
    public static function allowedHere(): bool
    {
        return ! app()->environment('production');
    }

    public static function refuses(User $user): bool
    {
        return $user->is_demo && ! self::allowedHere();
    }
}
