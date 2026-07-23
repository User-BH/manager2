<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * تنها مرجع پاسخ به این پرسش: «این کاربر الان در کدام مجتمع کار می‌کند؟»
 *
 * پیش از این، همین منطق سه‌جا تکرار شده بود (میدل‌ور، کنترلر پایه و
 * پیام‌رسان). تکرار خودش مشکل نبود؛ مشکل این بود که هر جا در لحظه‌ی
 * متفاوتی از چرخه‌ی درخواست اجرا می‌شد و نتیجه‌شان یکی نمی‌ماند.
 */
class ComplexResolver
{
    /**
     * شناسه‌ی مجتمع فعال برای این کاربر.
     *
     * ادمین کل به مجتمعی وصل نیست و می‌تواند یکی را در نشست انتخاب کند؛
     * تا وقتی انتخاب نکرده، null برمی‌گردد یعنی «همه‌ی مجتمع‌ها». بقیه‌ی
     * نقش‌ها به مجتمع خودشان قفل‌اند.
     */
    public static function idFor(?Authenticatable $user): ?int
    {
        if (! $user instanceof User) {
            return null;
        }

        if ($user->isSuperAdmin()) {
            $selected = session('active_complex_id');

            return $selected ? (int) $selected : null;
        }

        return $user->complex_id ? (int) $user->complex_id : null;
    }

    /**
     * شناسه‌ی مجتمعی که کوئری‌ها باید به آن محدود شوند.
     *
     * اول از TenantContext خوانده می‌شود (که میدل‌ور پر می‌کند)، و اگر هنوز
     * پر نشده باشد مستقیم از کاربر واردشده. این عقب‌گرد عمدی است: اتکای
     * محض به ترتیب میدل‌ورها شکننده است و اگر روزی جابه‌جا شود، جداسازی
     * مجتمع‌ها بی‌سروصدا از کار می‌افتد.
     */
    public static function activeId(): ?int
    {
        return app(TenantContext::class)->get() ?? self::idFor(Auth::user());
    }
}
