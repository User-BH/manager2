<?php

namespace App\Services\Assistant\Tools;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolDescriptor;
use App\Services\Assistant\ToolResult;
use App\Services\Features\FeatureFlags;
use App\Support\Jalali;

/**
 * پیام‌رسان (بندِ ۴.۵-و؛ آزمونِ ۱۸).
 *
 * 🔴 دستیار **متنِ پیام** نمی‌خواند — نه برای ساکن، نه برای مدیر. فقط وضعیتِ گفت‌وگوهایی که خودِ کاربر در پیام‌رسان
 * می‌بیند (`Message::visibleTo`): شمارِ خوانده‌نشده و آخرین فعالیت. «پیام‌های خصوصیِ واحدِ دیگر» برای ساکن اجازه
 * نیست؛ وجودِ پیام در پایگاه‌داده مجوزِ خواندنش نیست.
 */
final class MessengerTool extends Tool
{
    protected function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            id: 'messenger',
            title: 'پیام‌رسان',
            dashboards: ['unit', 'manager'],
            source: 'messages / conversations — Message::visibleTo، Notifications::messengerUnread',
            fields: ['conversation' => 'string', 'unread' => 'int', 'last_activity' => 'datetime'],
            relations: ['conversation', 'readers'],
            filters: ['خوانده‌نشده'],
            operations: ['aggregate'],
            derivation: 'خوانده‌نشده = پیام‌های قابلِ دیدِ دیگران که کاربر نخوانده (همان نشانِ پیام‌رسان)',
            access: 'فقط گفت‌وگوهای قابلِ دیدِ خودِ کاربر؛ وقتی پیام‌رسان روشن است',
            privacy: 'متن، پیوست و نامِ فرستنده‌ی هیچ پیامی برنمی‌گردد',
            time: 'زمانِ آخرین پیام',
            limit: 10,
            presentation: 'شمارِ خوانده‌نشده به تفکیکِ گفت‌وگو',
            keywords: ['پیام' => 3, 'پیامها' => 3, 'پیامرسان' => 5, 'گفتگو' => 4, 'گفتوگو' => 4, 'چت' => 3],
        );
    }

    public function authorize(AssistantContext $context): bool
    {
        return parent::authorize($context)
            && app(FeatureFlags::class)->enabled('messenger')
            && (bool) ($context->complex->messenger_enabled ?? false);
    }

    public function answer(AssistantContext $context, Question $question): ToolResult
    {
        $user = $context->user;
        if ($context->isResident() && $question->unit !== null
            && ! $user->currentUnits()->where('units.complex_id', $context->complex?->id)->where('units.unit_number', $question->unit)->exists()) {
            return ToolResult::forbidden('گفت‌وگوی واحدِ دیگر در داشبوردِ واحد دیده نمی‌شود.');
        }

        $unread = Message::where('complex_id', $context->complex?->id)->visibleTo($user)
            ->where('user_id', '!=', $user->id)->where('is_hidden', false)->whereNull('deleted_at')
            ->whereDoesntHave('readers', fn ($q) => $q->where('users.id', $user->id))
            ->whereNotNull('conversation_id')
            ->selectRaw('conversation_id, COUNT(*) as n, MAX(created_at) as last_at')
            ->groupBy('conversation_id')->orderByDesc('last_at')->get();

        $note = $question->mentions('متن', 'محتوا', 'بخوان', 'چه نوشته', 'نشان بده')
            ? ' دستیار متنِ پیام‌ها را نمی‌خواند؛ گفت‌وگو را در پیام‌رسان باز کنید.'
            : '';

        $total = (int) $unread->sum('n');
        if ($total === 0) {
            return ToolResult::notFound('پیامِ خوانده‌نشده‌ای ندارید.'.$note);
        }

        $conversations = Conversation::whereIn('id', $unread->pluck('conversation_id'))->with(['complex', 'unit', 'user', 'peer'])->get()->keyBy('id');

        return ToolResult::found(
            'پیام‌های خوانده‌نشده: '.$this->count($total).' در '.$this->count($unread->count()).' گفت‌وگو.'.$note,
            [ToolResult::fact('خوانده‌نشده', $this->count($total))],
            basis: 'گفت‌وگوهایی که خودتان در پیام‌رسان می‌بینید — بی خواندنِ متنِ پیام‌ها',
            columns: ['گفت‌وگو', 'خوانده‌نشده', 'آخرین پیام'],
            rows: $unread->take(10)->map(fn ($row) => [
                $conversations->get($row->conversation_id)?->titleFor($user) ?? 'گفت‌وگو',
                $this->count((int) $row->getAttribute('n')),
                Jalali::dateTime((string) $row->getAttribute('last_at')),
            ])->values()->all(),
            more: $unread->count() > 10,
        );
    }
}
