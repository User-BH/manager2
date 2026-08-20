<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateFeatureFlagRequest;
use App\Services\Features\FeatureFlags;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * خواندن و تغییرِ پرچم‌های قابلیت (R44).
 */
class FeatureFlagController extends Controller
{
    /**
     * وضعیتِ پرچم‌ها برای فرانت.
     *
     * ⚠️ عمداً بدونِ احراز هویت است و فقط `{کلید: بولین}` می‌دهد.
     *
     * صفحه‌ی ثبت‌نام و صفحه‌ی فرود پیش از ورودِ کاربر رندر می‌شوند و باید
     * بدانند `public_registration` روشن است یا نه. اگر این مسیر پشتِ
     * احراز هویت بود، دقیقاً همان صفحه‌هایی که به پرچم نیاز دارند
     * نمی‌توانستند بخوانندش.
     *
     * شرح و مقدارِ پیش‌فرض اینجا نمی‌آید؛ آن‌ها متنِ داخلیِ تیم‌اند و در
     * مسیرِ سوپرادمین برمی‌گردند.
     */
    public function index(FeatureFlags $features): JsonResponse
    {
        return response()->json(['data' => $features->all()]);
    }

    /** فهرستِ کامل با شرح و وضعیت — فقط سوپرادمین. */
    public function catalogue(FeatureFlags $features): JsonResponse
    {
        return response()->json(['data' => $features->catalogue()]);
    }

    /** روشن یا خاموش‌کردنِ یک پرچم — فقط سوپرادمین. */
    public function update(UpdateFeatureFlagRequest $request, string $flag, FeatureFlags $features): JsonResponse
    {
        $validated = $request->validated();

        try {
            $features->set($flag, (bool) $validated['enabled']);
        } catch (InvalidArgumentException $e) {
            // پرچمِ تعریف‌نشده خطای کاربر است، نه خطای سرور
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $validated['enabled']
                ? 'قابلیت روشن شد.'
                : 'قابلیت خاموش شد.',
            'data' => $features->catalogue(),
        ]);
    }
}
