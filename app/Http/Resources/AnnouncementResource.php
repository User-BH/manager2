<?php

namespace App\Http\Resources;

use App\Models\Announcement;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * شکلِ اطلاعیه در پاسخِ API.
 *
 * ─── چرا Resource و نه آرایه‌ی دستی در کنترلر؟ ─────────────────────────────
 * پیش از این هر کنترلر متدِ `present()` خودش را داشت. مشکلش این بود که وقتی
 * یک فیلد اضافه می‌شد، باید همه‌ی جاهایی که همان مدل را برمی‌گرداندند دستی
 * پیدا و به‌روز می‌شدند — و یادرفتنش خطایی بود که فقط در زمانِ اجرا دیده
 * می‌شد.
 *
 * ─── قاعده‌ی نام‌گذاری ──────────────────────────────────────────────────────
 * کلیدها camelCase‌اند چون مصرف‌کننده‌شان TypeScript است. تبدیل در همین لایه
 * انجام می‌شود، نه در فرانت؛ این‌طور نامِ فیلد در قرارداد API ثابت است و
 * تغییرِ نامِ ستونِ دیتابیس فرانت را نمی‌شکند.
 *
 * @property-read Announcement $resource
 */
class AnnouncementResource extends JsonResource
{
    /**
     * وضعیتِ خواندن از بیرون تزریق می‌شود.
     *
     * دلیلش: «خوانده‌شده» ویژگیِ خودِ اطلاعیه نیست، رابطه‌ی اطلاعیه با
     * *کاربرِ درخواست‌دهنده* است. اگر خودِ Resource آن را می‌خواند، برای هر
     * ردیف یک کوئریِ اضافه می‌رفت (مسئله‌ی N+1).
     */
    public function __construct(Announcement $resource, private readonly bool $isRead = true)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'title' => $this->resource->title,
            'body' => $this->resource->body,
            'audience' => $this->resource->audience->value,
            'audienceLabel' => $this->resource->audience->label(),
            'isPinned' => (bool) $this->resource->is_pinned,
            'isActive' => (bool) $this->resource->is_active,
            'isRead' => $this->isRead,
            // تاریخ همیشه شمسی و آماده‌ی نمایش بیرون می‌رود؛ فرانت تقویم ندارد
            'publishedAt' => $this->resource->published_at
                ? Jalali::date($this->resource->published_at)
                : null,
        ];
    }
}
