<?php

namespace App\Http\Resources;

use App\Enums\MessageAudience;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\ProfilePhoto;
use App\Services\Poll\PollService;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

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
    /** رابطه‌هایی که این خروجی می‌خواند — برای بارگذاریِ یک‌جا (بی N+1). */
    public const RELATIONS = [
        'recipientUnits:id,unit_number', 'readers:id', 'poll.options', 'poll.votes', 'replyTo', 'reactions',
        // تصویرِ canonical ِ نویسنده (۱۰/۷) — فقط دو ستون، یک کوئری برای کلِ صفحه
        'author:id,avatar_path',
    ];

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
        $isMine = $message->user_id === $this->viewer->id;
        // حذف‌شده برای همه، حتی مدیر، بی‌محتواست: متن و پیوستش واقعاً پاک شده‌اند
        $deleted = $message->isDeleted();

        return [
            'id' => $message->id,
            'body' => $deleted || ($hidden && ! $mayReadHidden) ? null : $message->body,
            'authorName' => $message->author_name,
            // تصویرِ فعلیِ نویسنده، نه نسخه‌ی کپی‌شده (بندِ ۵)؛ حسابِ حذف‌شده یا بی تصویر: null
            'authorAvatarUrl' => ProfilePhoto::url($message->author),
            'unitLabel' => $message->unit_label,
            'isMine' => $isMine,
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
            'attachment' => ! $deleted && $message->hasAttachment() ? [
                'name' => $message->attachment_name,
                'kind' => $message->attachment_kind,
                'url' => route('api.messenger.attachment', $message),
            ] : null,

            /*
             * رسیدِ خواندن (R23b). شمارش فقط برای مدیر: ساکن نباید از روی یک
             * پیامِ گروهی بفهمد چند همسایه‌ی دیگر آن را باز کرده‌اند.
             */
            'readCount' => $this->viewer->isAdmin() ? $message->readers->count() : null,

            /*
             * گفت‌وگو و وضعیتِ تحویل (مرحلهٔ ۶ · فاز ۱۲) — افزوده، پس کلاینتِ قدیم
             * نمی‌شکند.
             *
             * `clientId` فقط برای پیامِ خودِ بیننده: کلاینت با آن نسخه‌ی
             * خوش‌بینانه‌اش را با پاسخِ سرور یکی می‌کند. `status` هم فقط برای
             * پیامِ خود: «خوانده شد» یعنی **کسِ دیگری** رسیدِ خواندن زده — بی‌آنکه
             * بگوید چه کسی یا چند نفر (آن فقط `readCount`ِ مدیر است).
             */
            'conversationId' => $message->conversation_id,
            'clientId' => $isMine ? $message->client_id : null,
            'status' => $isMine
                ? ($message->readers->contains(fn ($reader) => $reader->id !== $message->user_id) ? 'read' : 'sent')
                : null,

            'poll' => $this->poll($message),

            /*
             * غنی‌سازی (مرحلهٔ ۶ · فاز ۱۴) — همه افزوده؛ کلاینتِ قدیم نمی‌شکند.
             *
             * `canEdit`/`canDelete` را سرور می‌گوید تا منوی پیام همان قاعده‌ای را
             * نشان دهد که سرور اجرا می‌کند؛ کلاینت پنجره‌ی ۱۵ دقیقه را حساب نمی‌کند.
             */
            'isDeleted' => $deleted,
            'isEdited' => ! $deleted && $message->edited_at !== null,
            'canEdit' => ! $hidden && $message->isEditableBy($this->viewer),
            'canDelete' => $isMine && ! $deleted && ! $hidden && $message->poll === null,
            'replyTo' => $this->replyTo($message),
            'reactions' => $deleted ? [] : $this->reactions($message),
        ];
    }

    /**
     * پیامی که این پیام پاسخِ آن است.
     *
     * ⚠️ تکه‌متنِ هدف همان قاعده‌ی خودِ هدف را دارد: پنهان‌شده برای ساکن و
     * حذف‌شده برای همه بی‌متن است — وگرنه پاسخ راهِ دورزدنِ پنهان‌کردن می‌شد.
     *
     * @return array<string, mixed>|null
     */
    private function replyTo(Message $message): ?array
    {
        $target = $message->reply_to_id !== null ? $message->replyTo : null;

        if ($target === null) {
            return null;
        }

        $unavailable = $target->isDeleted() || ($target->is_hidden && ! $this->viewer->isAdmin());
        $summary = $target->body ?: match ($target->attachment_kind) {
            'audio' => 'پیامِ صوتی',
            null => 'نظرسنجی',
            default => (string) $target->attachment_name,
        };

        return [
            'id' => $target->id,
            'authorName' => $target->author_name,
            'excerpt' => $unavailable ? null : Str::limit($summary, 90),
            'isDeleted' => $target->isDeleted(),
        ];
    }

    /**
     * واکنش‌ها به ترتیبِ فهرستِ ثابت، فقط شمار — نه اینکه چه کسی.
     *
     * @return list<array{emoji: string, count: int, mine: bool}>
     */
    private function reactions(Message $message): array
    {
        $summary = [];

        foreach (MessageReaction::ALLOWED as $emoji) {
            $matching = $message->reactions->where('emoji', $emoji);

            if ($matching->isNotEmpty()) {
                $summary[] = [
                    'emoji' => $emoji,
                    'count' => $matching->count(),
                    'mine' => $matching->contains('user_id', $this->viewer->id),
                ];
            }
        }

        return $summary;
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
