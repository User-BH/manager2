<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientErrorRequest;
use App\Services\ErrorRecorder;
use Illuminate\Http\JsonResponse;

/**
 * دریافتِ خطای رندرِ مرورگر از Error Boundary فرانت.
 *
 * ─── چرا برای مهمان هم باز است؟ ────────────────────────────────────────────
 * ارزشمندترین خطاها دقیقاً همان‌هایی‌اند که **پیش از ورود** رخ می‌دهند: کرشِ
 * صفحه‌ی فرود یا فرمِ ورود. اگر این مسیر احراز هویت می‌خواست، همان‌ها هرگز
 * گزارش نمی‌شدند.
 *
 * در عوض دو محافظ دارد: محدودیتِ نرخ (در تعریفِ مسیر) و اینکه ورودی هرگز
 * به‌صورتِ خام جایی نمایش داده نمی‌شود جز پنلِ ادمینِ کل.
 */
class ClientErrorController extends Controller
{
    public function store(StoreClientErrorRequest $request): JsonResponse
    {
        $data = $request->validated();

        ErrorRecorder::fromClient($data);

        // پاسخِ خالی: کلاینت منتظرِ چیزی نیست و نباید گزارشِ خطا خودش
        // درخواستِ سنگینی شود.
        return response()->json(null, 204);
    }
}
