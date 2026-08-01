<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdvertisementRequest;
use App\Http\Resources\AdvertisementResource;
use App\Models\Advertisement;
use App\Support\Uploads;
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

        // اگر ذخیره در دیتابیس شکست بخورد، فایل هم نباید بماند (R19)
        $ad = Uploads::keepIf($request->file('image'), 'ads', function (string $path) use ($data) {
            $ad = new Advertisement($data);
            $ad->image_path = $path;
            $ad->save();

            return $ad;
        });

        return response()->json([
            'message' => 'بنر تبلیغاتی ثبت شد.',
            'ad' => $this->present($ad),
        ], 201);
    }

    public function update(StoreAdvertisementRequest $request, Advertisement $advertisement): JsonResponse
    {
        $data = $request->validated();

        /*
         * تصویر تازه اختیاری است؛ نبودنش یعنی تصویر فعلی بماند.
         *
         * ترتیب اینجا مهم است (R19): تصویرِ قبلی **پس از** ذخیره‌ی موفق پاک
         * می‌شود، نه پیش از آن. در حالت قبلی اگر `save()` شکست می‌خورد، فایلِ
         * قدیمی رفته بود و مسیرِ تازه هم ذخیره نشده بود — یعنی بنر بی‌تصویر
         * می‌ماند.
         */
        if ($request->hasFile('image')) {
            $previous = $advertisement->image_path;

            Uploads::keepIf($request->file('image'), 'ads', function (string $path) use ($advertisement, $data): void {
                $advertisement->image_path = $path;
                $advertisement->fill($data)->save();
            });

            if ($previous) {
                Storage::disk('local')->delete($previous);
            }
        } else {
            $advertisement->fill($data)->save();
        }

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
