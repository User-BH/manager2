<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * مسیرهای بازنشسته — یک چرخه‌ی استقرار، طبقِ رویه‌ی Expand→Contract ِ `API_COMPATIBILITY.md`.
 *
 * تبِ بازِ نسخه‌ی پیشین که هنوز این‌ها را صدا می‌زند ۴۱۰ با پیامِ روشن می‌گیرد، نه ۴۰۴ ِ مبهم؛ و هیچ‌کدام کاری
 * نمی‌کند. پس از یک چرخه‌ی استقرار خودِ مسیرها برداشته می‌شوند.
 */
class RetiredEndpointController extends Controller
{
    /**
     * ۱۲/۲ (تصمیمِ قطعیِ کارفرما): خرید و تمدیدِ اشتراک فقط با پرداختِ آنلاین و تأییدِ خودکارِ سرور — نه رسیدِ واریز،
     * نه تأیید یا ردِ دستیِ ادمینِ کل.
     */
    public function onlineOnly(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'خرید و تمدیدِ اشتراک فقط با پرداختِ آنلاین انجام می‌شود و پس از تأییدِ درگاه خودکار فعال می‌شود.',
            'code' => 'subscription.online_only',
        ], 410);
    }
}
