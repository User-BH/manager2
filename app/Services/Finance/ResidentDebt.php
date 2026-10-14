<?php

namespace App\Services\Finance;

use App\Models\Bill;
use App\Models\User;

/**
 * «بدهیِ من» — یک تعریف برای هر جایی که ساکن بدهی‌اش را می‌بیند (مرحلهٔ ۱۰ · فاز ۱۳؛ بندِ ۲).
 *
 * ─── یافته‌ای که این کلاس را ساخت ──────────────────────────────────────────
 * داشبوردِ واحد، «قبض‌های من» و فهرستِ واحدهای پروفایل `units.balance` را نشان می‌دادند — بدهیِ **کلِ واحد**، با
 * دوره‌های ساکنِ قبلی — ولی فهرستِ قبض‌ها و آمارِ پروفایل فقط دوره‌های خودِ ساکن را (مرحلهٔ ۹ · فاز ۶، F4). مستأجرِ
 * تازه «بدهی: ۴۰۰ هزار» می‌دید بی هیچ قبضی؛ و ساکنِ رفته در پروفایلش بدهیِ ساکنِ بعدی را.
 *
 * بدهیِ ساکن = `Σ (total_amount − paid_amount)` ِ قبض‌هایی که `Bill::visibleToResident` به او نشان می‌دهد. بدهیِ
 * **واحد** (نمای مدیر) همان `units.balance` است و جداست.
 */
final class ResidentDebt
{
    /**
     * بدهیِ ساکن به تفکیکِ واحد.
     *
     * @return array<int, float> unit_id => بدهی
     */
    public function perUnit(User $user, bool $includePast = false): array
    {
        // `Bill::query()` با مرزِ مجتمعِ فعال — همان پرسشی که فهرستِ «قبض‌های من» می‌پرسد
        return Bill::query()
            ->visibleToResident($user, $includePast)
            ->groupBy('unit_id')
            ->selectRaw('unit_id, COALESCE(SUM(total_amount - paid_amount), 0) as debt')
            ->pluck('debt', 'unit_id')
            ->map(fn ($debt) => round((float) $debt, 2))
            ->all();
    }

    /** جمعِ بدهیِ دوره‌های جاریِ ساکن */
    public function total(User $user): float
    {
        return round(array_sum($this->perUnit($user)), 2);
    }
}
