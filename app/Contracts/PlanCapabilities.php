<?php

namespace App\Contracts;

/**
 * قابلیت‌هایی که PlanGate برای اعمالِ محدودیت‌ها می‌خواند.
 *
 * هم پکیجِ دیتابیسی (`App\Models\Plan`) و هم enumِ قدیمی (`SubscriptionPlan`)
 * این را پیاده می‌کنند، پس PlanGate می‌تواند بدونِ اهمیتِ منبع، یکسان با
 * هردو کار کند.
 */
interface PlanCapabilities
{
    /** سقفِ تعدادِ واحد؛ null یعنی نامحدود. */
    public function unitLimit(): ?int;

    /** اجازه‌ی اتصالِ درگاهِ بانکیِ واقعی. */
    public function allowsRealGateway(): bool;

    /** اجازه‌ی خروجیِ Excel از قبوض. */
    public function allowsExcelExport(): bool;

    /** برچسبِ نمایشیِ پلن. */
    public function planLabel(): string;
}
