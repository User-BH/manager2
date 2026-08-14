<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebVitalsRequest;
use App\Models\WebVital;
use Illuminate\Http\JsonResponse;

/**
 * دریافتِ دادهٔ میدانیِ Core Web Vitals (R38).
 *
 * ─── چرا برای مهمان هم باز است ─────────────────────────────────────────────
 * مهم‌ترین سنجه‌ها دقیقاً مالِ صفحه‌ی فرودند — همان صفحه‌ای که بازدیدکننده‌ی
 * واردنشده می‌بیند و رتبه‌ی جستجو از رویش تعیین می‌شود. اگر این مسیر احراز
 * هویت می‌خواست، دقیقاً همان داده‌ای که به‌خاطرش ساخته شده هرگز نمی‌رسید.
 *
 * محافظش محدودیتِ نرخ است و اینکه ورودی به فهرستِ بسته‌ی سنجه‌ها محدود شده.
 */
class WebVitalsController extends Controller
{
    public function store(StoreWebVitalsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $now = now();

        /*
         * درجِ دسته‌ای: یک بازدید معمولاً پنج سنجه می‌فرستد و پنج رفت‌وبرگشتِ
         * جدا به دیتابیس برای داده‌ای که فقط تحلیلی است، اسراف است.
         */
        WebVital::insert(array_map(fn (array $metric) => [
            'metric' => $metric['name'],
            'value' => $metric['value'],
            'rating' => $metric['rating'],
            'path' => $data['path'],
            'device' => $data['device'],
            'created_at' => $now,
        ], $data['metrics']));

        // پاسخِ خالی: کلاینت هنگامِ ترکِ صفحه می‌فرستد و منتظرِ چیزی نیست.
        return response()->json(null, 204);
    }
}
