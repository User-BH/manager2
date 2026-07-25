<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SiteContent;
use Illuminate\Http\JsonResponse;

/**
 * محتوای عمومیِ فوترِ صفحه‌ی فرود (تماس + شبکه‌های اجتماعی).
 *
 * بدون احراز هویت، چون صفحه‌ی فرود پیش از ورود دیده می‌شود. فقط موارد فعال
 * برگردانده می‌شوند تا فوتر خودش تصمیم به مخفی‌کردن نگیرد.
 */
class SiteContentController extends Controller
{
    public function footer(): JsonResponse
    {
        $footer = SiteContent::footer();
        $contact = $footer['contact'];

        $social = collect($footer['social'])
            ->where('enabled', true)
            ->map(fn (array $s) => ['id' => $s['id'], 'label' => $s['label'], 'href' => $s['href']])
            ->values();

        return response()->json([
            'contact' => [
                'title' => $contact['title'],
                'address' => $contact['showAddress'] ? $contact['address'] : null,
                'phone' => $contact['showPhone'] ? $contact['phone'] : null,
                'email' => $contact['showEmail'] ? $contact['email'] : null,
                'mapEmbedUrl' => $contact['showMap'] ? $contact['mapEmbedUrl'] : null,
            ],
            'social' => $social,
        ]);
    }
}
