<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ثبت رویدادهای حساس در لاگ فعالیت.
 *
 * جدول `audit_logs` از ابتدا وجود داشت ولی فقط از یک نقطه (تایید پرداخت)
 * نوشته می‌شد؛ یعنی حذف ساکن، غیرفعال‌کردن حساب، تغییر درگاه بانکی و تایید
 * اشتراک هیچ ردی به جا نمی‌گذاشتند. این کلاس صدا زدنش را به یک خط کاهش
 * می‌دهد تا اضافه‌کردنش در نقاط تازه فراموش نشود.
 *
 * لاگ عمداً هرگز استثنا پرتاب نمی‌کند: شکستِ ثبت رویداد نباید خودِ عملیات را
 * از کار بیندازد.
 */
class Audit
{
    /**
     * @param  array<string,mixed>  $properties
     */
    public static function log(
        string $action,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        ?int $complexId = null,
    ): void {
        try {
            $user = Auth::user();

            AuditLog::create([
                'complex_id' => $complexId ?? ComplexResolver::idFor($user),
                'user_id' => $user?->id,
                'action' => $action,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'description' => $description,
                'ip_address' => request()->ip(),
                'properties' => $properties ?: null,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // ثبت نشدنِ لاگ نباید عملیات اصلی را بشکند
        }
    }
}
