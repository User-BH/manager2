<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use Illuminate\Http\JsonResponse;

/**
 * فهرست بنرهای تبلیغاتی برای صفحه‌ی فرود.
 *
 * عمداً بدون احراز هویت است: صفحه‌ی فرود پیش از ورود کاربر دیده می‌شود.
 * چیزی جز عنوان و لینک و تصویر برنمی‌گرداند، پس نشت اطلاعاتی ندارد.
 */
class AdvertisementController extends Controller
{
    public function index(): JsonResponse
    {
        $ads = Advertisement::visible()->get()->map(fn (Advertisement $ad) => [
            'id' => $ad->id,
            'title' => $ad->title,
            'subtitle' => $ad->subtitle,
            'href' => $ad->href,
            'image' => $ad->displayImageUrl(),
        ])
            // بنری که تصویرش پاک شده نباید قاب خالی روی صفحه بگذارد
            ->filter(fn (array $ad) => (bool) $ad['image'])
            ->values();

        return response()->json(['ads' => $ads]);
    }
}
