<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Support\SiteContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contact.title' => ['nullable', 'string', 'max:100'],
            'contact.address' => ['nullable', 'string', 'max:500'],
            'contact.phone' => ['nullable', 'string', 'max:50'],
            'contact.email' => ['nullable', 'string', 'max:150'],
            'contact.mapEmbedUrl' => ['nullable', 'string', 'max:1500'],
            'contact.showAddress' => ['boolean'],
            'contact.showPhone' => ['boolean'],
            'contact.showEmail' => ['boolean'],
            'contact.showMap' => ['boolean'],
            'social' => ['array', 'max:5'],
            'social.*.id' => ['required', 'string', 'in:'.implode(',', SiteContent::SOCIAL_IDS)],
            'social.*.label' => ['nullable', 'string', 'max:50'],
            'social.*.href' => ['nullable', 'string', 'max:300'],
            'social.*.enabled' => ['boolean'],
        ]);

        SiteContent::save($data['contact'] ?? [], $data['social'] ?? []);

        return response()->json([
            'message' => 'تنظیمات فوتر ذخیره شد.',
            'footer' => SiteContent::footer(),
        ]);
    }
}
