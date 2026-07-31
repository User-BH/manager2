<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdvertisementRequest;
use App\Http\Resources\AdvertisementResource;
use App\Models\Advertisement;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * مدیریت بنرهای تبلیغاتی صفحه‌ی فرود (ویژه‌ی ادمین کل).
 *
 * پیش از این، تبلیغات آرایه‌ای ثابت داخل کد فرانت‌اند بودند و عوض کردن یک
 * لینک هم نیازمند ویرایش فایل و بیلد و استقرار دوباره بود.
 */
class AdvertisementController extends Controller
{
    public function index(): JsonResponse
    {
        $ads = Advertisement::orderBy('sort_order')->orderBy('id')->get();

        return response()->json([
            'ads' => $ads->map(fn (Advertisement $ad) => $this->present($ad)),
        ]);
    }

    public function store(StoreAdvertisementRequest $request): JsonResponse
    {
        $data = $request->validated();

        $ad = new Advertisement($data);
        $ad->image_path = $request->file('image')->store('ads', 'local');
        $ad->save();

        return response()->json([
            'message' => 'بنر تبلیغاتی ثبت شد.',
            'ad' => $this->present($ad),
        ], 201);
    }

    public function update(StoreAdvertisementRequest $request, Advertisement $advertisement): JsonResponse
    {
        $data = $request->validated();

        // تصویر تازه اختیاری است؛ نبودنش یعنی تصویر فعلی بماند
        if ($request->hasFile('image')) {
            $previous = $advertisement->image_path;
            $advertisement->image_path = $request->file('image')->store('ads', 'local');

            if ($previous) {
                Storage::disk('local')->delete($previous);
            }
        }

        $advertisement->fill($data)->save();

        return response()->json([
            'message' => 'بنر تبلیغاتی به‌روزرسانی شد.',
            'ad' => $this->present($advertisement->fresh()),
        ]);
    }

    public function toggle(Advertisement $advertisement): JsonResponse
    {
        $advertisement->update(['is_active' => ! $advertisement->is_active]);

        return response()->json([
            'message' => $advertisement->is_active ? 'بنر فعال شد.' : 'بنر غیرفعال شد.',
            'ad' => $this->present($advertisement),
        ]);
    }

    public function destroy(Advertisement $advertisement): JsonResponse
    {
        // رویداد deleting مدل، فایل تصویر را هم پاک می‌کند
        $advertisement->delete();

        return response()->json(['message' => 'بنر تبلیغاتی حذف شد.']);
    }

    /**
     * شکلِ خروجی حالا در `AdvertisementResource` است.
     *
     * این متد یک پلِ کوتاه است تا فراخوانی‌های موجود دست‌نخورده بمانند؛
     * نقطه‌ی حقیقتِ ساختار یکی شد.
     */
    private function present(Advertisement $ad): array
    {
        return (new AdvertisementResource($ad))->toArray(request());
    }
}
