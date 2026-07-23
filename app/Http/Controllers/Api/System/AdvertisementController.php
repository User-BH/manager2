<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

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

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, creating: true);

        $ad = new Advertisement($data);
        $ad->image_path = $request->file('image')->store('ads', 'local');
        $ad->save();

        return response()->json([
            'message' => 'بنر تبلیغاتی ثبت شد.',
            'ad' => $this->present($ad),
        ], 201);
    }

    public function update(Request $request, Advertisement $advertisement): JsonResponse
    {
        $data = $this->validated($request, creating: false);

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
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            /*
             * فقط http/https پذیرفته می‌شود. بدون این شرط، مقداری مثل
             * `javascript:...` در href می‌نشست و صفحه‌ی فرودِ عمومی به
             * ناقل XSS تبدیل می‌شد.
             */
            'href' => ['required', 'string', 'max:500', 'url', 'starts_with:http://,https://'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'image' => [
                $creating ? 'required' : 'nullable',
                'image', Rule::file()->types(['jpg', 'jpeg', 'png', 'webp'])->max(3 * 1024),
            ],
        ], [
            'href.url' => 'لینک مقصد باید یک آدرس کامل و معتبر باشد.',
            'href.starts_with' => 'لینک مقصد باید با http:// یا https:// شروع شود.',
            'ends_at.after' => 'تاریخ پایان باید بعد از تاریخ شروع باشد.',
            'image.required' => 'انتخاب تصویر بنر الزامی است.',
        ], [
            'title' => 'عنوان',
            'subtitle' => 'توضیح کوتاه',
            'href' => 'لینک مقصد',
            'sort_order' => 'ترتیب نمایش',
            'starts_at' => 'تاریخ شروع',
            'ends_at' => 'تاریخ پایان',
            'image' => 'تصویر بنر',
        ]);

        // چک‌باکس خاموش اصلاً ارسال نمی‌شود؛ نبودِ کلید یعنی «غیرفعال»
        $data['is_active'] = $request->boolean('is_active');
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        unset($data['image']);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Advertisement $ad): array
    {
        return [
            'id' => $ad->id,
            'title' => $ad->title,
            'subtitle' => $ad->subtitle,
            'href' => $ad->href,
            'image' => $ad->displayImageUrl(),
            'isActive' => $ad->is_active,
            'isLive' => $ad->isLive(),
            'sortOrder' => $ad->sort_order,
            'startsAt' => $ad->starts_at?->toDateString(),
            'endsAt' => $ad->ends_at?->toDateString(),
            'startsAtLabel' => $ad->starts_at ? Jalali::date($ad->starts_at) : null,
            'endsAtLabel' => $ad->ends_at ? Jalali::date($ad->ends_at) : null,
            // بنرهای پیش‌فرضِ همراه پروژه فایل آپلودی ندارند
            'isBuiltIn' => ! $ad->image_path,
        ];
    }
}
