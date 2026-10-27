<?php

namespace App\Services\Assistant\Phrasing;

/**
 * لایه‌ی تولیدِ پاسخ به زبانِ طبیعی (بندِ ۴.۶، لایه‌ی ۷) — جای اتصالِ مدلِ زبانیِ **محلی**.
 *
 * ورودی پاسخِ کاملی است که از داده ساخته شده؛ خروجی فقط بازنویسیِ روان‌ترِ همان، یا null (همان پاسخ). مدل هرگز به
 * داده یا پایگاه‌داده دسترسی ندارد و هیچ تصمیمِ مجوزی نمی‌گیرد (بندِ ۴.۱۱).
 */
interface Phraser
{
    public function rephrase(string $answer): ?string;

    /** نامِ موتور برای پاسخ و سنجش («none» یعنی بی مدل) */
    public function name(): string;
}
