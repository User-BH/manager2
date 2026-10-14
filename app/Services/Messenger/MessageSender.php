<?php

namespace App\Services\Messenger;

use App\Enums\MessageAudience;
use App\Enums\PollVoterScope;
use App\Enums\PollWeightMode;
use App\Exceptions\DomainException;
use App\Models\Complex;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Unit;
use App\Models\User;
use App\Support\Uploads;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * فرستادنِ یک پیام در یک گفت‌وگو (مرحلهٔ ۶ · فاز ۱۲) — **تنها** راهِ نوشتنِ
 * پیام، چه از API تازه چه از API قدیم.
 *
 * ─── سه تضمین ───────────────────────────────────────────────────────────────
 * ۱. **یکپارچگی:** پیام، پیوست، نظرسنجی و «آخرین پیامِ گفت‌وگو» در یک تراکنش؛
 *    شکستِ هرکدام هیچ‌کدام را باقی نمی‌گذارد (پیوست با `Uploads::keepIf`).
 * ۲. **بی‌تکرار:** `client_id` که کلاینت پیش از ارسال می‌سازد. ارسالِ دوباره
 *    پس از قطعی همان پیام را برمی‌گرداند؛ حتی دو درخواستِ هم‌زمان، چون
 *    یکتاییِ `(user_id, client_id)` در دیتابیس است.
 * ۳. **سازگاری در دوره‌ی گذار:** ستون‌های قدیم (`audience`، `unit_id`،
 *    `message_units`) هم نوشته می‌شوند — نسخه‌ی قبل و `PollService` همان‌ها را
 *    می‌خوانند و برگشت بی‌خطر می‌ماند.
 *
 * ترتیب: شناسه‌ی پیام. `last_message_id` فقط رو به جلو می‌رود
 * (`where last_message_id < id`)، پس دو ارسالِ هم‌زمان ترتیب را وارونه نمی‌کنند.
 */
class MessageSender
{
    /**
     * @param  array<string, mixed>  $data  داده‌ی اعتبارسنجی‌شده (`body`، `client_id`، `poll_*`)
     * @return array{0: Message, 1: bool} پیام، و اینکه تازه ساخته شد یا تکراری بود
     */
    public function send(Complex $complex, Conversation $conversation, User $user, array $data, ?UploadedFile $attachment = null): array
    {
        // بستنِ پیام‌رسان روی عضویتِ همین مجتمع است (۱۰/۹)؛ «ذخیره‌شده» یادداشتِ خودِ اوست و بسته نمی‌شود
        if (! $complex->messenger_enabled || ($conversation->type !== Conversation::SAVED && MessagingAccess::blocked($user, $complex->id))) {
            abort(403, 'امکان ارسال پیام برای شما فعال نیست.');
        }

        if (! $conversation->allowsPostingBy($user)) {
            throw new DomainException($conversation->readOnlyReason(), 'messenger.read_only', 403);
        }

        $clientId = $data['client_id'] ?? null;

        if ($clientId !== null && ($existing = $this->existing($user, $clientId, $conversation))) {
            return [$existing, false];
        }

        // پاسخ فقط به پیامی از **همین** گفت‌وگو که هنوز دیده می‌شود (فاز ۱۴)
        $data['reply_to_id'] = $this->replyTarget($conversation, $user, $data['reply_to_id'] ?? null);

        try {
            return [$this->write($complex, $conversation, $user, $data, $attachment), true];
        } catch (UniqueConstraintViolationException $e) {
            // ارسالِ هم‌زمانِ همان `client_id` — دیگری زودتر نوشت
            $existing = $clientId !== null ? $this->existing($user, $clientId, $conversation) : null;

            if ($existing === null) {
                throw $e;
            }

            return [$existing, false];
        }
    }

    /**
     * پیامِ پیشینِ همین کاربر با همین شناسه‌ی کلاینت.
     *
     * ⚠️ شناسه در گفت‌وگوی دیگری مصرف شده باشد، ۴۰۹ — نه برگرداندنِ آن پیام،
     * که از گفت‌وگوی دیگر خبر می‌داد.
     */
    private function existing(User $user, string $clientId, Conversation $conversation): ?Message
    {
        $message = Message::withoutGlobalScopes()->where('user_id', $user->id)->where('client_id', $clientId)->first();

        if ($message !== null && $message->conversation_id !== $conversation->id) {
            throw DomainException::conflict('این شناسه‌ی ارسال پیش‌تر به کار رفته است.', 'messenger.client_id_reused');
        }

        return $message;
    }

    /** @param  array<string, mixed>  $data */
    private function write(Complex $complex, Conversation $conversation, User $user, array $data, ?UploadedFile $attachment): Message
    {
        [$audience, $unitId] = $this->legacyAudience($conversation);

        $create = function (?string $path) use ($complex, $conversation, $user, $data, $attachment, $audience, $unitId): Message {
            $message = Message::create([
                'complex_id' => $complex->id,
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'client_id' => $data['client_id'] ?? null,
                'reply_to_id' => $data['reply_to_id'],
                'body' => $data['body'] ?? '',
                'audience' => $audience,
                'unit_id' => $unitId,
                'attachment_path' => $path,
                'attachment_name' => $attachment ? Uploads::safeOriginalName($attachment) : null,
                'attachment_kind' => $attachment ? $this->attachmentKind($attachment, (bool) ($data['is_voice'] ?? false)) : null,
                'author_name' => $user->name,
                'author_role' => $user->role->value,
                'unit_label' => $this->unitLabel($user),
            ]);

            if ($audience === MessageAudience::Units) {
                $message->recipientUnits()->sync($conversation->units()->pluck('units.id'));
            }

            // نظرسنجی تصمیمِ ساختمان است؛ فقط مدیریت
            if (! empty($data['poll_question']) && $user->role->isAdmin()) {
                $this->createPoll($message, $data);
            }

            Conversation::withoutGlobalScopes()
                ->whereKey($conversation->id)
                ->where('last_message_id', '<', $message->id)
                ->update(['last_message_id' => $message->id, 'last_message_at' => $message->created_at]);

            return $message;
        };

        return DB::transaction(
            fn () => $attachment
                ? Uploads::keepIf($attachment, 'messages/'.$complex->id, $create)
                : $create(null),
            attempts: 3,
        );
    }

    /**
     * ستون‌های قدیم برای این گفت‌وگو — همان معنای دیدِ پیش از فاز ۱۲.
     *
     * @return array{0: MessageAudience, 1: int|null}
     */
    private function legacyAudience(Conversation $conversation): array
    {
        return match ($conversation->type) {
            Conversation::CHANNEL => [MessageAudience::All, null],
            Conversation::UNIT => [MessageAudience::Management, $conversation->unit_id],
            Conversation::GROUP => [MessageAudience::Units, null],
            default => [MessageAudience::Management, null],
        };
    }

    /** @param  array<string, mixed>  $data */
    private function createPoll(Message $message, array $data): void
    {
        $poll = $message->poll()->create([
            'question' => trim((string) $data['poll_question']),
            'voter_scope' => $data['poll_voter_scope'] ?? PollVoterScope::Residents->value,
            'weight_mode' => $data['poll_weight_mode'] ?? PollWeightMode::PerPerson->value,
            'quorum_percent' => $data['poll_quorum_percent'] ?? null,
            'allow_change' => (bool) ($data['poll_allow_change'] ?? true),
            'closes_at' => $data['poll_closes_at'] ?? null,
        ]);

        foreach (array_values($data['poll_options'] ?? []) as $index => $label) {
            $poll->options()->create(['label' => (string) $label, 'sort_order' => $index]);
        }
    }

    /**
     * `image` درون‌خطی نشان داده می‌شود و `audio` با پخش‌کننده (پیامِ صوتی، فاز ۱۴)؛
     * بقیه دانلود می‌شوند. نوعِ صوتی فقط از فیلدِ `voice` می‌آید که قاعده‌ی خودش را
     * دارد — نه از حدسِ نوعِ فایلی که به‌عنوانِ پیوستِ عادی آمده.
     */
    private function attachmentKind(UploadedFile $file, bool $isVoice): string
    {
        return match (true) {
            $isVoice => 'audio',
            str_starts_with((string) $file->getMimeType(), 'image/') => 'image',
            default => 'file',
        };
    }

    /**
     * ⚠️ شناسه‌ی پیامِ گفت‌وگوی دیگر یا پیامِ پنهان‌شده (برای ساکن) به‌عنوانِ
     * «پاسخ به» پذیرفته نمی‌شود: پاسخ تکه‌ای از متنِ هدف را نشان می‌دهد، پس
     * راهی می‌شد برای دیدنِ پیامی که کاربر حقِ دیدنش را ندارد.
     */
    private function replyTarget(Conversation $conversation, User $user, mixed $replyToId): ?int
    {
        if ($replyToId === null || $replyToId === '') {
            return null;
        }

        // پاسخ به پیامی که برایش دیدنی نیست (ساکنِ قبلیِ واحد) پیش‌نمایشش را لو می‌داد (F4)
        $target = Message::query()->where('conversation_id', $conversation->id)->visibleTo($user)->whereKey((int) $replyToId)->first();

        if ($target === null || $target->isDeleted() || ($target->is_hidden && ! $user->isAdmin())) {
            throw DomainException::invalid('پیامی که به آن پاسخ می‌دهید در دسترس نیست.', 'messenger.reply_unavailable');
        }

        return $target->id;
    }

    private function unitLabel(User $user): string
    {
        if ($user->isAdmin()) {
            return 'مدیریت';
        }

        /** @var Unit|null $unit */
        $unit = $user->currentUnits()->first();

        return $unit?->label() ?? '-';
    }
}
