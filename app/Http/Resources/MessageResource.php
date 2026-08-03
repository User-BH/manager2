<?php

namespace App\Http\Resources;

use App\Enums\MessageAudience;
use App\Models\Message;
use App\Models\Unit;
use App\Models\User;
use App\Services\Poll\PollService;
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

            /*
             * مخاطب (R23) تا فرستنده ببیند پیامش کجا رفته.
             *
             * برچسبِ گیرنده‌ها فقط برای مدیر ساخته می‌شود: ساکن نباید از روی
             * یک پیامِ گروهی بفهمد کدام واحدهای دیگر هم گیرنده بوده‌اند.
             */
            'audience' => $message->audience->value,
            'audienceLabel' => $this->audienceLabel($message),

            // پیوست (R23b) — مسیرِ سرو کنترل‌شده است، نه لینکِ مستقیمِ دیسک
            'attachment' => $message->hasAttachment() ? [
                'name' => $message->attachment_name,
                'kind' => $message->attachment_kind,
                'url' => route('api.messenger.attachment', $message),
            ] : null,

            /*
             * رسیدِ خواندن (R23b). شمارش فقط برای مدیر: ساکن نباید از روی یک
             * پیامِ گروهی بفهمد چند همسایه‌ی دیگر آن را باز کرده‌اند.
             */
            'readCount' => $this->viewer->isAdmin() ? $message->readers->count() : null,

            'poll' => $this->poll($message),
        ];
    }

    /**
     * برچسبِ خواناى مخاطب.
     *
     * برای پیامِ چندواحدی، نامِ واحدها فقط به مدیر نشان داده می‌شود. ساکنی
     * که یکی از گیرنده‌هاست نباید بفهمد مدیر همین پیام را به چه کسانی دیگر
     * فرستاده.
     */
    /**
     * نظرسنجی با نتیجه‌ی کامل (R23b + R24).
     *
     * نتیجه همیشه نشان داده می‌شود — نه فقط پس از رأی‌دادن. در یک ساختمان،
     * پنهان‌کردنِ نتیجه چیزی را منصفانه‌تر نمی‌کند و فقط باعث می‌شود کسی که
     * رأی نداده نداند اصلاً موضوع چیست.
     *
     * شکلِ خروجی از `PollService` می‌آید تا با کارتِ داشبورد و پاسخِ رأی
     * یکی بماند؛ سه نسخه‌ی جدا دیر یا زود از هم واگرا می‌شدند.
     *
     * @return array<string, mixed>|null
     */
    private function poll(Message $message): ?array
    {
        return $message->poll
            ? app(PollService::class)->results($message->poll, $this->viewer)
            : null;
    }

    private function audienceLabel(Message $message): string
    {
        if ($message->audience !== MessageAudience::Units) {
            return $message->audience->label();
        }

        if (! $this->viewer->isAdmin()) {
            return 'به شما';
        }

        $labels = $message->recipientUnits
            ->map(fn (Unit $unit) => 'واحد '.$unit->unit_number)
            ->all();

        return $labels === [] ? 'به واحدهای انتخابی' : 'به '.implode('، ', $labels);
    }
}
