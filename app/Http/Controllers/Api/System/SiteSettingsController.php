<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSiteSettingsRequest;
use App\Support\SiteContent;
use Illuminate\Http\JsonResponse;

/**
 * تنظیماتِ فوترِ سایت برای ادمینِ کل: تماس (آدرس/تلفن/ایمیل/نقشه با امکانِ
 * فعال‌وغیرفعال) و لینکِ پنج شبکه‌ی اجتماعی.
 */
class SiteSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['footer' => SiteContent::footer()]);
    }

    public function update(UpdateSiteSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();

        SiteContent::save($data['contact'] ?? [], $data['social'] ?? []);

        return response()->json([
            'message' => 'تنظیمات فوتر ذخیره شد.',
            'footer' => SiteContent::footer(),
        ]);
    }
}
