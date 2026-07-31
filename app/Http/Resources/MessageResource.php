<?php

namespace App\Http\Resources;

use App\Models\Message;
use App\Models\User;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * پیامِ پیام‌رسانِ داخلی.
 *
 * از `present()` در `Api/MessengerController.php` بیرون کشیده شد (R9b). شکلِ خروجی کلمه‌به‌کلمه
 * همان است؛ فقط حالا یک نقطه‌ی حقیقت دارد و افزودنِ فیلدِ تازه فقط همین‌جا
 * انجام می‌شود، نه در هر کنترلری که همان مدل را برمی‌گرداند.
 *
 * کلیدها camelCase‌اند چون مصرف‌کننده TypeScript است؛ تبدیل در همین لایه است
 * تا تغییرِ نامِ ستونِ دیتابیس قراردادِ API را نشکند.
 *
 * @property-read Message $resource
 */
class MessageResource extends JsonResource
{
    /**
     * بیننده از بیرون تزریق می‌شود.
     *
     * «این پیام مالِ خودم است؟» ویژگیِ خودِ پیام نیست، رابطه‌ی پیام با
     * *کاربرِ درخواست‌دهنده* است. اگر Resource خودش کاربر را می‌خواند، این
     * وابستگیِ پنهان در هر جای دیگری که استفاده می‌شد هم می‌آمد.
     */
    public function __construct(Message $resource, private readonly User $viewer)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $message = $this->resource;

        $hidden = (bool) $message->is_hidden;
        $mayReadHidden = $this->viewer->isAdmin();

        return [
            'id' => $message->id,
            'body' => $hidden && ! $mayReadHidden ? null : $message->body,
            'authorName' => $message->author_name,
            'unitLabel' => $message->unit_label,
            'isMine' => $message->user_id === $this->viewer->id,
            'isHidden' => $hidden,
            'sentAt' => Jalali::dateTime($message->created_at),
        ];
    }
}
