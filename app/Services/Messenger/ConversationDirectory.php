<?php

namespace App\Services\Messenger;

use App\Models\Complex;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Unit;
use App\Models\User;
use App\Support\Jalali;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * یافتن، ساختن و فهرست‌کردنِ گفت‌وگوها (مرحلهٔ ۶ · فاز ۱۲).
 *
 * ⚠️ ساختن همیشه «یافتن یا ساختن» است و به یکتاییِ `(complex_id, key)`ِ
 * دیتابیس تکیه می‌کند: دو درخواستِ هم‌زمان برای رشته‌ی یک واحد دو رشته
 * نمی‌سازند — دومی به خطای یکتایی می‌خورد و همان اولی را برمی‌دارد.
 */
class ConversationDirectory
{
    public const PAGE = 30;

    public function channel(Complex $complex): Conversation
    {
        return $this->ensure($complex, 'channel', ['type' => Conversation::CHANNEL, 'members_can_post' => false]);
    }

    public function unitThread(Complex $complex, Unit $unit): Conversation
    {
        return $this->ensure($complex, 'unit:'.$unit->id, ['type' => Conversation::UNIT, 'unit_id' => $unit->id]);
    }

    public function userThread(Complex $complex, User $user): Conversation
    {
        return $this->ensure($complex, 'user:'.$user->id, ['type' => Conversation::USER, 'user_id' => $user->id]);
    }

    /**
     * رشته‌های خصوصیِ خودِ ساکن با مدیریت: یکی برای هر واحدِ فعلی، یا — اگر
     * هنوز واحدی ندارد — یکی برای خودش. بدونِ این، ساکنِ تازه‌واردی که بیش از
     * همه به مدیر نیاز دارد جایی برای نوشتن نداشت.
     *
     * @return Collection<int, Conversation>
     */
    public function ownThreads(Complex $complex, User $user): Collection
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, Unit> $units */
        $units = $user->currentUnits()->get();

        return $units->isEmpty()
            ? collect([$this->userThread($complex, $user)])
            : $units->map(fn (Unit $unit) => $this->unitThread($complex, $unit))->values();
    }

    /**
     * گروهِ مجموعه‌ای از واحدها — همان گروهی که پیامِ «به واحدهای انتخابی»ِ
     * API قدیم (و نگاشتِ داده‌ی قبلی) در آن می‌نشیند.
     *
     * @param  array<int, int>  $unitIds
     */
    public function groupForUnits(Complex $complex, array $unitIds, User $creator): Conversation
    {
        sort($unitIds);
        $key = 'units:'.md5(implode(',', $unitIds));

        $conversation = $this->ensure($complex, $key, [
            'type' => Conversation::GROUP,
            'title' => $this->unitsTitle($unitIds),
            'members_can_post' => false,
            'created_by' => $creator->id,
        ]);

        $conversation->units()->syncWithoutDetaching($unitIds);

        return $conversation;
    }

    /**
     * گروهِ نام‌دار که مدیر می‌سازد. ⚠️ اعضایش پس از ساخت عوض نمی‌شوند:
     * پیام‌های قبلی با همان مجموعه دیده شده‌اند و ویرایشِ عضویت دیدِ گذشته را
     * جابه‌جا می‌کرد. گروهِ دیگر، گروهِ تازه است.
     *
     * @param  array<int, int>  $unitIds
     */
    public function createGroup(Complex $complex, string $title, array $unitIds, bool $membersCanPost, User $creator): Conversation
    {
        $conversation = Conversation::create([
            'complex_id' => $complex->id,
            'type' => Conversation::GROUP,
            'key' => 'group:'.Str::uuid(),
            'title' => $title,
            'members_can_post' => $membersCanPost,
            'created_by' => $creator->id,
        ]);

        $conversation->units()->sync($unitIds);

        return $conversation;
    }

    /**
     * فهرستِ گفت‌وگوهای بیننده، تازه‌ترین اول، با شمارِ نخوانده و پیش‌نمایشِ
     * آخرین پیام.
     *
     * نشانگر `last_message_id:id` است نه شماره‌ی صفحه: گفت‌وگویی که پیامِ تازه
     * می‌گیرد به بالا می‌پرد و `OFFSET` همان را دو بار یا هیچ بار نشان می‌داد.
     *
     * @return array{data: array<int, array<string, mixed>>, hasMore: bool, nextCursor: ?string}
     */
    public function page(User $viewer, ?string $cursor): array
    {
        $query = Conversation::query()->visibleTo($viewer)->with(['unit:id,unit_number', 'user:id,name']);

        // مدیر رشته‌ی بی‌پیامِ واحدها را در فهرست نمی‌بیند؛ گروه و کانال را چرا
        if ($viewer->role->isAdmin()) {
            $query->where(fn (Builder $q) => $q->where('last_message_id', '>', 0)->orWhereIn('type', [Conversation::CHANNEL, Conversation::GROUP]));
        }

        if ($cursor !== null && preg_match('/^(\d+):(\d+)$/', $cursor, $m)) {
            [$lastMessage, $id] = [(int) $m[1], (int) $m[2]];
            $query->where(fn (Builder $q) => $q->where('last_message_id', '<', $lastMessage)
                ->orWhere(fn (Builder $t) => $t->where('last_message_id', $lastMessage)->where('id', '<', $id)));
        }

        $rows = $query->orderByDesc('last_message_id')->orderByDesc('id')->limit(self::PAGE + 1)->get();
        $hasMore = $rows->count() > self::PAGE;
        $page = $rows->take(self::PAGE);

        $unread = $this->unreadCounts($viewer, $page->pluck('id')->all());
        // پیش‌نمایش هم همان قاعده‌ی دید: آخرین پیامِ ساکنِ قبلی پیش‌نمایشِ ساکنِ تازه نیست (F4)
        $last = Message::query()->visibleTo($viewer)->whereIn('id', $page->pluck('last_message_id')->filter()->all())->get()->keyBy('id');

        return [
            'data' => $page->map(fn (Conversation $c) => $this->present($c, $viewer, $unread[$c->id] ?? 0, $last->get($c->last_message_id)))->values()->all(),
            'hasMore' => $hasMore,
            'nextCursor' => $hasMore ? $page->last()->last_message_id.':'.$page->last()->id : null,
        ];
    }

    /**
     * شمارِ نخوانده به ازای هر گفت‌وگو: پیامِ دیگران، پنهان‌نشده، بی‌رسیدِ
     * خواندنِ همین کاربر.
     *
     * @param  array<int, int>  $conversationIds
     * @return array<int, int>
     */
    public function unreadCounts(User $viewer, array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        return Message::query()
            ->whereIn('conversation_id', $conversationIds)
            ->visibleTo($viewer)
            ->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $viewer->id))
            ->where('is_hidden', false)
            ->whereNull('deleted_at')
            ->whereDoesntHave('readers', fn ($q) => $q->where('users.id', $viewer->id))
            ->groupBy('conversation_id')
            ->selectRaw('conversation_id, count(*) as unread')
            ->pluck('unread', 'conversation_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @return array<string, mixed> */
    public function present(Conversation $conversation, User $viewer, int $unread = 0, ?Message $last = null): array
    {
        $hiddenForViewer = $last && $last->is_hidden && ! $viewer->isAdmin();

        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'title' => $conversation->titleFor($viewer),
            'canPost' => $conversation->allowsPostingBy($viewer),
            'membersCanPost' => $conversation->members_can_post,
            'unreadCount' => $unread,
            'lastMessage' => $last ? [
                'preview' => match (true) {
                    $hiddenForViewer => null,
                    $last->isDeleted() => 'این پیام حذف شد',
                    $last->attachment_kind === 'audio' && ! $last->body => 'پیامِ صوتی',
                    default => Str::limit((string) ($last->body ?: $last->attachment_name ?: 'نظرسنجی'), 80),
                },
                'authorName' => $last->author_name,
                'isMine' => $last->user_id === $viewer->id,
                'sentAt' => Jalali::dateTime($last->created_at),
            ] : null,
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    private function ensure(Complex $complex, string $key, array $attributes): Conversation
    {
        $find = fn () => Conversation::withoutGlobalScopes()->where('complex_id', $complex->id)->where('key', $key)->first();

        if ($existing = $find()) {
            return $existing;
        }

        try {
            return Conversation::create(['complex_id' => $complex->id, 'key' => $key, ...$attributes]);
        } catch (UniqueConstraintViolationException) {
            // درخواستِ هم‌زمانِ دیگری همین لحظه ساختش
            return $find();
        }
    }

    /** @param  array<int, int>  $unitIds */
    private function unitsTitle(array $unitIds): string
    {
        $labels = Unit::whereIn('id', $unitIds)->orderBy('unit_number')->pluck('unit_number')
            ->map(fn ($n) => 'واحد '.$n)->implode('، ');

        return Str::limit($labels !== '' ? $labels : 'واحدهای انتخابی', 120, '…');
    }
}
