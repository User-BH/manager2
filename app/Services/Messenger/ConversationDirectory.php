<?php

namespace App\Services\Messenger;

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Unit;
use App\Models\User;
use App\Services\Search\CommunityLookup;
use App\Support\Jalali;
use App\Support\SearchTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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

    /**
     * گروهِ اصلیِ مجتمع (بندِ ۳۲). از ۱۰/۹ اعضا در آن می‌نویسند — فایل ویرایش و حذفِ پیامِ خودِ «هر member» را در
     * گروه می‌خواهد (بندهای ۵۴ و ۵۵)؛ مدیر می‌تواند از «اطلاعاتِ گروه» نوشتن را فقط برای مدیریت بگذارد.
     */
    public function channel(Complex $complex): Conversation
    {
        return $this->ensure($complex, 'channel', ['type' => Conversation::CHANNEL, 'members_can_post' => true]);
    }

    public function unitThread(Complex $complex, Unit $unit): Conversation
    {
        return $this->ensure($complex, 'unit:'.$unit->id, ['type' => Conversation::UNIT, 'unit_id' => $unit->id]);
    }

    public function userThread(Complex $complex, User $user): Conversation
    {
        return $this->ensure($complex, 'user:'.$user->id, ['type' => Conversation::USER, 'user_id' => $user->id]);
    }

    /** «پیام‌های ذخیره‌شده»ِ این حساب در این مجتمع (بندِ ۳۹؛ تصمیمِ کارفرما: در هر مجتمع یکی). */
    public function savedThread(Complex $complex, User $user): Conversation
    {
        return $this->ensure($complex, 'saved:'.$user->id, ['type' => Conversation::SAVED, 'user_id' => $user->id]);
    }

    /**
     * «مالک ↔ مستأجر»ِ همین دو نفر در همین واحد (بندِ ۳۸). کلید هر سه را دارد: مستأجرِ تازه گفت‌وگوی تازه می‌گیرد
     * و گفت‌وگوی مستأجرِ قبلی جدا می‌ماند (بندهای ۷۲ و ۱۱۷).
     */
    public function pairThread(Complex $complex, int $unitId, int $ownerId, int $tenantId): Conversation
    {
        return $this->ensure($complex, "pair:{$unitId}:{$ownerId}:{$tenantId}", [
            'type' => Conversation::PAIR, 'unit_id' => $unitId, 'user_id' => $ownerId, 'peer_id' => $tenantId,
        ]);
    }

    /**
     * گفت‌وگوهایی که هر عضو از همان بارِ اول دارد (بندِ ۴۰) — «یافتن یا ساختن»، پس تکرارش چیزی نمی‌سازد:
     *
     *   همه      گروهِ مجتمع و «ذخیره‌شده»
     *   عضو      گفت‌وگوی خصوصیِ خودش با مدیریت (بندِ ۳۷)
     *   مالک     با هر مستأجرِ **فعلیِ** واحدش؛ مستأجر با هر مالکِ فعلی — واحدِ بی‌مستأجر گفت‌وگوی جعلی نمی‌گیرد
     *
     * ⚠️ همه از دوره‌های **جاریِ همین مجتمع** (`unit_user.complex_id`)؛ دوره‌ی بسته گفت‌وگوی تازه نمی‌سازد.
     */
    public function ensureFor(Complex $complex, User $user): void
    {
        $this->channel($complex);
        $this->savedThread($complex, $user);

        if ($user->role->isAdmin()) {
            return;
        }

        $this->userThread($complex, $user);

        $mine = DB::table('unit_user')->where('complex_id', $complex->id)->where('user_id', $user->id)
            ->where('is_current', true)->whereIn('relation', ['owner', 'tenant'])->get(['unit_id', 'relation']);

        foreach ($mine as $tenure) {
            $counterparts = DB::table('unit_user')->where('complex_id', $complex->id)->where('unit_id', $tenure->unit_id)
                ->where('is_current', true)->where('relation', $tenure->relation === 'owner' ? 'tenant' : 'owner')
                ->where('user_id', '!=', $user->id)->pluck('user_id');

            foreach ($counterparts as $other) {
                $tenure->relation === 'owner'
                    ? $this->pairThread($complex, (int) $tenure->unit_id, $user->id, (int) $other)
                    : $this->pairThread($complex, (int) $tenure->unit_id, (int) $other, $user->id);
            }
        }
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
     * با `$search` (مرحلهٔ ۱۰ · فاز ۸؛ بندِ ۴۱): همان فهرست، همان ترتیب و همان نشانگر، فقط باریک‌تر. جست‌وجو
     * **پس از** `visibleTo` و دامنه‌ی مستأجر می‌آید، پس هرگز گفت‌وگویی را که بیننده نمی‌بیند پیدا نمی‌کند — فقط
     * از میانِ دیده‌شده‌ها کم می‌کند. هیچ گفت‌وگویی هم نمی‌سازد.
     *
     * @return array{data: array<int, array<string, mixed>>, hasMore: bool, nextCursor: ?string}
     */
    public function page(User $viewer, ?string $cursor, ?SearchTerm $search = null, ?Complex $within = null): array
    {
        $query = Conversation::query()->visibleTo($viewer)
            ->with(['unit:id,unit_number', 'user:id,name', 'peer:id,name', 'complex:id,name']);

        /*
         * مدیر رشته‌ی بی‌پیامِ واحدها را در فهرست نمی‌بیند؛ گروه و کانال را چرا. ⚠️ در جست‌وجو می‌بیند: رشته‌ی
         * واحدی که ساکنش پیام‌رسان را باز کرده ولی هنوز ننوشته، همان جایی است که «مالک واحد ۱۰» باید برسد.
         */
        if ($viewer->role->isAdmin() && $search === null) {
            $query->where(fn (Builder $q) => $q->where('last_message_id', '>', 0)->orWhereIn('type', [Conversation::CHANNEL, Conversation::GROUP, Conversation::SAVED]));
        }

        // بایگانیِ بی‌پیامِ واحد (ساخته‌ی نسخه‌ی پیش از ۱۰/۹) چیزی برای خواندن ندارد
        if (! $viewer->role->isAdmin()) {
            $query->where(fn (Builder $q) => $q->where('type', '!=', Conversation::UNIT)->orWhere('last_message_id', '>', 0));
        }

        if ($search !== null) {
            // مجتمع صریح است، نه `users.complex_id`: ادمینِ کل مجتمعش را از نشست می‌گیرد
            $this->narrow($query, $viewer, $search, $within ?? throw new \LogicException('جست‌وجوی گفت‌وگو مجتمعِ صریح می‌خواهد.'));
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
     * گفت‌وگوهایی که عبارت به آن‌ها اشاره می‌کند — از دیدِ همین بیننده.
     *
     * مدیر (عنوان‌هایش «واحد ۱۰» و نامِ ساکن است):
     *   - «واحد ۱۰» یا «۱۰»: رشته‌ی آن واحد؛
     *   - «مالک واحد ۱۰»، «مستأجر واحد ۲۵»: رشته‌ی واحدی که آن شخص **الان** در آن است — رشته‌ی خصوصیِ
     *     ساختار امروز مالِ واحد است، نه فرد (ساختارِ فرد‌به‌فرد: ۱۰/۹)؛
     *   - «علی احمدی»: رشته‌ی خودِ او (بی‌واحد) و رشته‌ی واحدهای فعلی‌اش؛
     *   - عنوانِ گروه، و «همه‌ی اهالی» برای کانال.
     * ساکن (عنوان‌هایش «مدیریتِ ساختمان» است): فقط عنوانِ گروه و کانال — بندِ ۴۲ جست‌وجو را برای او الزامی
     * نمی‌داند، و نامِ دیگران را از این راه نباید بشنود.
     *
     * هر زیرپرسش `complex_id` ِ همین مجتمع را صریح دارد (`CommunityLookup`)؛ ساکنِ قبلیِ واحد و همنامِ
     * مجتمعِ دیگر پیدا نمی‌شوند.
     *
     * @param  Builder<Conversation>  $query
     */
    private function narrow(Builder $query, User $viewer, SearchTerm $search, Complex $complex): void
    {
        $complexId = $complex->id;
        $text = $search->text;

        $groupName = 'گروهِ '.$complex->name;

        $query->where(function (Builder $q) use ($viewer, $search, $complexId, $text, $groupName): void {
            $q->where(fn (Builder $g) => $g->where('type', Conversation::GROUP)->where('title', 'like', "%{$text}%"));

            // گروهِ مجتمع با نامِ خودش (و نامِ پیش از ۱۰/۹)؛ «ذخیره‌شده» با نامش — `visibleTo` فقط مالِ خودِ بیننده را دارد
            if (str_contains($groupName, $text) || str_contains(str_replace('گروهِ', 'گروه', $groupName), $text) || str_contains('همه‌ی اهالی', $text)) {
                $q->orWhere('type', Conversation::CHANNEL);
            }
            if (str_contains('پیام‌های ذخیره‌شده', $text) || str_contains('پیام های ذخیره شده', $text)) {
                $q->orWhere('type', Conversation::SAVED);
            }

            if (! $viewer->role->isAdmin()) {
                return;
            }

            /*
             * مدیر: گفت‌وگوی خصوصیِ **هر عضو** با مدیریت (۱۰/۹) و بایگانیِ واحد. «مالک واحد ۱۰» گفت‌وگوی مالکِ
             * فعلیِ واحدِ ۱۰ است — نه مستأجرش، که حالا گفت‌وگوی خودش را دارد.
             */
            if ($search->relation !== null && ($people = CommunityLookup::currentResidents($complexId, $search))) {
                $q->orWhere(fn (Builder $u) => $u->where('type', Conversation::USER)->whereIn('user_id', $people))
                    ->orWhere(fn (Builder $u) => $u->where('type', Conversation::UNIT)
                        ->whereIn('unit_id', CommunityLookup::occupiedUnits($complexId, $search)));

                return;
            }

            if ($units = CommunityLookup::units($complexId, $search)) {
                // «۱۰» یا «واحد ۱۰»: گفت‌وگوی ساکنانِ فعلیِ واحد و بایگانیِ خودِ واحد
                $q->orWhere(fn (Builder $u) => $u->where('type', Conversation::USER)
                    ->whereIn('user_id', CommunityLookup::currentResidents($complexId, $search)))
                    ->orWhere(fn (Builder $u) => $u->where('type', Conversation::UNIT)->whereIn('unit_id', $units));
            }

            if ($search->exactUnit) {
                return;
            }

            // «علی احمدی»: گفت‌وگوی خودش و بایگانیِ واحدِ فعلی‌اش
            $members = CommunityLookup::membersMatching($complexId, $search, [UserRole::Owner, UserRole::Tenant]);
            $q->orWhere(fn (Builder $u) => $u->where('type', Conversation::USER)->whereIn('user_id', $members))
                ->orWhere(fn (Builder $u) => $u->where('type', Conversation::UNIT)
                    ->whereIn('unit_id', CommunityLookup::unitsOf($complexId, $members)));
        });
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
            'canPost' => $canPost = $conversation->allowsPostingBy($viewer),
            // چرا نه — بایگانیِ واحد، «مالک ↔ مستأجر»ِ پس از رفتن، گروهِ «فقط مدیریت» (۱۰/۹)
            'readOnlyReason' => $canPost ? null : $conversation->readOnlyReason(),
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
