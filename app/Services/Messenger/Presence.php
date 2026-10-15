<?php

namespace App\Services\Messenger;

use App\Models\ComplexMembership;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Account\ComplexMembers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * حضورِ آنلاین در پیام‌رسان («چند نکته»، بندِ ۳).
 *
 * **تعریف:** حساب «آنلاین» است اگر در `WINDOW` ثانیه‌ی اخیر از داشبوردِ باز درخواستِ واردشده فرستاده باشد.
 *
 * - **ضربان:** همان pollingهای موجود — اعلان‌ها ۶۰ ثانیه، فهرستِ گفت‌وگوها ۱۵، رشته‌ی باز ۸ — که در تبِ پنهان
 *   می‌خوابند. هیچ درخواست و هیچ انتقالِ تازه‌ای (WebSocket، SSE) اضافه نشد. بیشترین فاصله‌ی دو درخواست از تبِ
 *   دیده‌شده ۶۰ ثانیه است و `WINDOW` ۳۰ ثانیه برای تأخیرِ شبکه جا می‌گذارد.
 * - **نوشتن:** `TouchPresence` روی گروهِ `auth` ِ API؛ اگر `last_seen_at` ِ همین حساب کمتر از `THROTTLE` ثانیه
 *   پیش است، هیچ کوئری‌ای زده نمی‌شود (حساب همین درخواست از پایگاه‌داده خوانده شده)، وگرنه یک UPDATE ِ شرطی.
 *   پس هر حسابِ آنلاین حداکثر دو نوشتن در دقیقه دارد.
 * - **پایان:** خروج و بستنِ صفحه (`pagehide`) `forget` را صدا می‌زنند؛ قطعِ اینترنت، خوابِ دستگاه و پایانِ نشست
 *   بی‌درخواست‌اند و با گذشتنِ `WINDOW` خودبه‌خود آفلاین می‌شوند. پس «آنلاینِ کهنه» حداکثر `WINDOW` ثانیه است.
 * - **مرز:** حضور روی حساب است، ولی فقط به کسی می‌رسد که با او در گفت‌وگویی دیدنی شریک است، و فقط برای
 *   **عضوِ فعالِ همان مجتمع** (`ComplexMembers::query`) — عضوِ معلق، رفته و مجتمعِ دیگر هرگز آنلاین دیده نمی‌شوند.
 *   حضور به هیچ‌کس اجازه‌ای نمی‌دهد؛ هیچ policy و میدل‌وری آن را نمی‌خواند.
 */
final class Presence
{
    /** پنجره‌ی «آنلاین» به ثانیه */
    public const WINDOW = 90;

    /** کم‌ترین فاصله‌ی دو نوشتن برای یک حساب، به ثانیه */
    public const THROTTLE = 30;

    public static function touch(User $user): void
    {
        $now = now();

        // حسابِ همین درخواست تازه از پایگاه‌داده خوانده شده؛ ضربانِ تازه کوئری نمی‌خواهد
        if ($user->last_seen_at !== null && $user->last_seen_at->gt($now->copy()->subSeconds(self::THROTTLE))) {
            return;
        }

        // شرط در خودِ UPDATE: دو درخواستِ هم‌زمان یک نوشتن‌اند. query builder: نه رویداد، نه updated_at، نه ممیزی
        DB::table('users')->where('id', $user->id)
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $now->copy()->subSeconds(self::THROTTLE)))
            ->update(['last_seen_at' => $now]);
        $user->last_seen_at = $now;
        $user->syncOriginalAttribute('last_seen_at');
    }

    /** خروج یا بستنِ صفحه: همین حالا آفلاین */
    public static function forget(User $user): void
    {
        DB::table('users')->where('id', $user->id)->update(['last_seen_at' => null]);
        $user->last_seen_at = null;
        $user->syncOriginalAttribute('last_seen_at');
    }

    public static function cutoff(): Carbon
    {
        return now()->subSeconds(self::WINDOW);
    }

    /** تا کی آنلاین (ثانیه‌ی یونیکس)، یا null — کلاینت با `serverTime` می‌سنجد و بی‌درخواستِ تازه خاموشش می‌کند */
    public static function until(?Carbon $lastSeen): ?int
    {
        return $lastSeen !== null && $lastSeen->gte(self::cutoff()) ? $lastSeen->getTimestamp() + self::WINDOW : null;
    }

    /** شمارِ اعضای فعالِ آنلاینِ گروه — همان اعضای `ConversationParticipants`، با همین بیننده */
    public static function onlineCount(Conversation $conversation): int
    {
        return ConversationParticipants::query($conversation)->where('users.last_seen_at', '>=', self::cutoff())->count();
    }

    /**
     * کسی که حضورش در این گفت‌وگو کنارِ عنوان دیده می‌شود.
     *
     * فقط گفت‌وگوی یک‌به‌یک: «مالک ↔ مستأجر» طرفِ دیگر را؛ گفت‌وگوی خصوصیِ عضو با مدیریت، فقط از دیدِ مدیر، همان
     * عضو را. ساکن در همان گفت‌وگو «مدیریتِ ساختمان» می‌بیند — یک نقش، نه یک نفر — پس حضوری ندارد. گروه شمار دارد
     * (`onlineCount`)؛ «ذخیره‌شده» و بایگانیِ واحد هیچ.
     */
    public static function peerOf(Conversation $conversation, User $viewer): ?int
    {
        $peer = match ($conversation->type) {
            Conversation::PAIR => $conversation->user_id === $viewer->id ? $conversation->peer_id : $conversation->user_id,
            Conversation::USER => $viewer->role->isAdmin() ? $conversation->user_id : null,
            default => null,
        };

        return $peer === $viewer->id ? null : $peer;
    }

    /**
     * «آنلاین تا» ِ طرفِ گفت‌وگو برای یک صفحه از گفت‌وگوها — یک کوئری برای هر مجتمع (فهرست همیشه یک مجتمع است).
     *
     * @param  iterable<Conversation>  $conversations
     * @return array<int, int|null> شناسه‌ی گفت‌وگو ← «آنلاین تا»
     */
    public static function peers(iterable $conversations, User $viewer): array
    {
        $peers = [];
        $complexes = [];
        foreach ($conversations as $conversation) {
            if ($peer = self::peerOf($conversation, $viewer)) {
                // کلید با مجتمع: عضوِ فعالِ مجتمعِ دیگر در گفت‌وگوی این مجتمع آنلاین دیده نمی‌شود
                $peers[$conversation->id] = $conversation->complex_id.':'.$peer;
                $complexes[$conversation->complex_id][] = $peer;
            }
        }

        $until = [];
        foreach ($complexes as $complexId => $userIds) {
            ComplexMembers::query((int) $complexId, ComplexMembership::ROLES)
                ->whereIn('users.id', array_values(array_unique($userIds)))
                ->where('users.last_seen_at', '>=', self::cutoff())
                ->get()
                ->each(function (User $user) use (&$until, $complexId): void {
                    $until[$complexId.':'.$user->id] = self::until($user->last_seen_at);
                });
        }

        return array_map(fn (string $key) => $until[$key] ?? null, $peers);
    }
}
