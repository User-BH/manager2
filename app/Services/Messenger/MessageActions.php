<?php

namespace App\Services\Messenger;

use App\Exceptions\DomainException;
use App\Models\Complex;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\MessageReport;
use App\Models\User;
use App\Support\PrivateFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * کارهای روی پیامِ موجود (مرحلهٔ ۶ · فاز ۱۴): ویرایش، حذف، واکنش، سنجاق،
 * گزارش و رسیدگی.
 *
 * ─── قاعده‌ها یک‌جا ──────────────────────────────────────────────────────────
 * «چه کسی گفت‌وگو را می‌بیند» پیش از این در کنترلر سنجیده شده (نادیدنی ۴۰۴).
 * اینجا فقط «با پیامِ دیده‌شده چه کاری مجاز است»:
 *
 *   ویرایش   فقط پیامِ خود، تا ۱۵ دقیقه، و فقط کسی که هنوز می‌تواند بنویسد
 *   حذف      فقط پیامِ خود؛ متن و پیوست همان لحظه پاک می‌شوند
 *            (هیچ‌کدام روی پیامی که مدیریت پنهان کرده)
 *   واکنش    هر بیننده‌ای که پیام‌رسانش باز است — جز روی پیامِ پنهان یا حذف‌شده
 *   سنجاق    فقط مدیریت
 *   گزارش    ساکن، روی پیامِ دیگران
 *
 * ⚠️ مدیر پیامِ دیگران را حذف یا ویرایش نمی‌کند: ابزارِ او «پنهان کردن» است
 * که برگشت‌پذیر است و سابقه را نگه می‌دارد.
 */
class MessageActions
{
    public function edit(Complex $complex, Conversation $conversation, Message $message, User $user, string $body): Message
    {
        $this->ensureCanWrite($complex, $conversation, $user);

        if ($message->user_id !== $user->id) {
            throw new DomainException('فقط پیامِ خودتان را می‌توانید ویرایش کنید.', 'messenger.not_yours', 403);
        }

        $this->ensureNotModerated($message);

        if (! $message->isEditableBy($user)) {
            throw new DomainException('زمانِ ویرایشِ این پیام گذشته است.', 'messenger.edit_window', 403);
        }

        $body = trim($body);

        if ($body === '' && ! $message->hasAttachment() && $message->poll === null) {
            throw DomainException::invalid('متن پیام را وارد کنید.', 'messenger.empty', ['body' => ['متن پیام را وارد کنید.']]);
        }

        $message->update(['body' => $body, 'edited_at' => now()]);
        $this->touchReplies($message->id);

        return $message;
    }

    /**
     * حذفِ پیامِ خود: ردیف می‌ماند («این پیام حذف شد»)، محتوا نه.
     *
     * گزارشِ بازِ رویش مانعِ حذف نیست: مانع‌شدن به نویسنده می‌گفت گزارش شده.
     *
     * پیامِ نظرسنجی حذف نمی‌شود — رأیِ دیگران رویش است؛ نظرسنجی بسته می‌شود.
     */
    public function delete(Conversation $conversation, Message $message, User $user): Message
    {
        if ($message->user_id !== $user->id) {
            throw new DomainException('فقط پیامِ خودتان را می‌توانید حذف کنید.', 'messenger.not_yours', 403);
        }

        if ($message->isDeleted()) {
            return $message;
        }

        $this->ensureNotModerated($message);

        if ($message->poll !== null) {
            throw DomainException::conflict('پیامِ نظرسنجی حذف نمی‌شود؛ می‌توانید نظرسنجی را ببندید.', 'messenger.poll_message');
        }

        $path = $message->attachment_path;

        DB::transaction(function () use ($conversation, $message): void {
            $message->update([
                'body' => '',
                'attachment_path' => null,
                'attachment_name' => null,
                'attachment_kind' => null,
                'deleted_at' => now(),
            ]);

            $message->reactions()->delete();
            $this->touchReplies($message->id);

            Conversation::withoutGlobalScopes()
                ->whereKey($conversation->id)
                ->where('pinned_message_id', $message->id)
                ->update(['pinned_message_id' => null]);
        });

        // فایل پس از ثبتِ تراکنش: اگر تراکنش برمی‌گشت، پیوستِ پیامِ زنده گم نمی‌شد
        if ($path !== null) {
            Storage::disk(PrivateFiles::name())->delete($path);
        }

        return $message;
    }

    /** همان واکنشِ دوباره برمی‌داردش؛ واکنشِ دیگر جایش را می‌گیرد. */
    public function react(Complex $complex, Message $message, User $user, string $emoji): void
    {
        if (! $complex->messenger_enabled || MessagingAccess::blocked($user, $complex->id)) {
            abort(403, 'امکانِ واکنش برای شما فعال نیست.');
        }

        $this->ensureLive($message);

        $existing = MessageReaction::where('message_id', $message->id)->where('user_id', $user->id)->first();

        if ($existing?->emoji === $emoji) {
            $existing->delete();
        } else {
            MessageReaction::updateOrCreate(
                ['message_id' => $message->id, 'user_id' => $user->id],
                ['emoji' => $emoji],
            );
        }

        // واکنش در جدولِ جداست؛ لمسِ پیام آن را در `changed`ِ پرسشِ بعدیِ دیگران می‌آورد
        $message->touch();
    }

    public function pin(Conversation $conversation, ?Message $message, User $user): void
    {
        if (! $user->isAdmin()) {
            throw new DomainException('سنجاق کارِ مدیریت است.', 'messenger.pin_forbidden', 403);
        }

        if ($message !== null) {
            $this->ensureLive($message);
        }

        $conversation->update(['pinned_message_id' => $message?->id]);
    }

    /** @return array{0: MessageReport, 1: bool} گزارش، و اینکه تازه بود یا تکراری */
    public function report(Complex $complex, Message $message, User $user, ?string $reason): array
    {
        if ($user->isAdmin()) {
            throw new DomainException('مدیریت پیام را پنهان می‌کند، گزارش نمی‌دهد.', 'messenger.report_forbidden', 403);
        }

        if ($message->user_id === $user->id) {
            throw DomainException::invalid('پیامِ خودتان را نمی‌توانید گزارش کنید.', 'messenger.report_own');
        }

        $this->ensureLive($message);

        $report = MessageReport::firstOrCreate(
            ['message_id' => $message->id, 'user_id' => $user->id],
            ['complex_id' => $complex->id, 'reason' => $reason !== null ? trim($reason) : null],
        );

        return [$report, $report->wasRecentlyCreated];
    }

    /** پنهان‌کردن یا کنارگذاشتن — برای همه‌ی گزارش‌های بازِ همان پیام. */
    public function resolve(MessageReport $report, User $admin, string $resolution): void
    {
        DB::transaction(function () use ($report, $admin, $resolution): void {
            if ($resolution === MessageReport::HIDE) {
                Message::whereKey($report->message_id)->update(['is_hidden' => true, 'hidden_by' => $admin->id]);
                $this->touchReplies($report->message_id);
            }

            MessageReport::where('message_id', $report->message_id)
                ->whereNull('resolved_at')
                ->update(['resolution' => $resolution, 'resolved_by' => $admin->id, 'resolved_at' => now()]);
        });
    }

    /**
     * 🔴 پاسخ‌هایی که این پیام را نقل کرده‌اند هم «تغییر کرده»اند.
     *
     * تکه‌متنِ نقل‌شده سمتِ سرور ساخته می‌شود؛ بی‌این، پس از حذف یا پنهان‌شدنِ
     * هدف، کلاینت‌های باز همان متنِ پاک‌شده را در نقلِ پاسخ نگه می‌داشتند تا
     * بارگذاریِ دوباره (سفرِ مرورگرِ فاز ۱۴ پیدایش کرد).
     */
    public function touchReplies(int $messageId): void
    {
        Message::where('reply_to_id', $messageId)->update(['updated_at' => now()]);
    }

    private function ensureCanWrite(Complex $complex, Conversation $conversation, User $user): void
    {
        if (! $complex->messenger_enabled || ($conversation->type !== Conversation::SAVED && MessagingAccess::blocked($user, $complex->id))) {
            abort(403, 'امکان ارسال پیام برای شما فعال نیست.');
        }

        if (! $conversation->allowsPostingBy($user)) {
            throw new DomainException($conversation->readOnlyReason(), 'messenger.read_only', 403);
        }
    }

    /**
     * ⚠️ پیامی که مدیریت پنهان کرده، نزدِ نویسنده دست‌نخورده می‌ماند: ویرایش یا
     * حذفش سابقه‌ی همان چیزی را پاک می‌کرد که به‌خاطرش پنهان شد.
     */
    private function ensureNotModerated(Message $message): void
    {
        if ($message->is_hidden) {
            throw new DomainException('این پیام توسط مدیریت پنهان شده و تغییر نمی‌کند.', 'messenger.moderated', 403);
        }
    }

    /** پیامِ پنهان یا حذف‌شده جای واکنش، سنجاق یا گزارش نیست. */
    private function ensureLive(Message $message): void
    {
        if ($message->isDeleted() || $message->is_hidden) {
            throw DomainException::invalid('این پیام در دسترس نیست.', 'messenger.unavailable');
        }
    }
}
