<?php

namespace App\Support;

use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * منطق مشترک اعلان‌ها.
 *
 * زنگوله‌ی هدر، دراپ‌داون آن و صفحه‌ی اطلاعیه‌ها هر سه از اینجا می‌خوانند تا
 * تعریف «نخوانده» در هر سه یکی بماند؛ اگر هر کدام قید خودش را داشت،
 * شمارنده می‌توانست عددی نشان بدهد که کاربر راهی برای صفر کردنش نداشت.
 *
 * ─── دو منبع، یک فهرست (R12) ───────────────────────────────────────────────
 * از R12 علاوه بر اطلاعیه‌ها، اعلان‌های شخصیِ لاراول هم داریم (مثل «رسید شما
 * تایید شد»). تصمیمِ اولیه این بود که جدولِ جدا ساخته نشود «تا دو فهرستِ
 * موازی که از هم جدا می‌افتند به وجود نیاید» — و آن نگرانی درست بود.
 *
 * پس دو فهرست نساختیم: همین کلاس هر دو منبع را در **یک** خروجی ادغام
 * می‌کند. کاربر یک زنگوله و یک شمارنده می‌بیند، و «همه را خواندم» هر دو را
 * با هم صفر می‌کند.
 *
 * تفاوتِ ذاتی‌شان همچنان محفوظ است: اطلاعیه همگانی است و وضعیتِ خواندنش در
 * `announcement_reads` است؛ اعلانِ شخصی مالِ یک کاربر است و در ستونِ
 * `read_at` خودش.
 */
class Notifications
{
    /** مجموعِ نخوانده‌ها از هر سه منبع — همان عددی که روی زنگوله می‌نشیند. */
    public static function unreadCount(User $user): int
    {
        return self::unreadAnnouncementCount($user)
            + $user->unreadNotifications()->count()
            + self::messengerUnread($user);
    }

    /**
     * پیام‌های خوانده‌نشده‌ی پیام‌رسان (R23b).
     *
     * عمداً **مشتق** است و نه ردیفِ اعلان به ازای هر پیام: در یک گفت‌وگو،
     * ساختنِ یک اعلان برای هر گیرنده هم جدول را به اندازه‌ی پیام×کاربر بزرگ
     * می‌کند و هم زنگوله را چنان پر می‌کند که دیگر چیزی در آن دیده نشود.
     * این‌طور کاربر یک سطر می‌بیند: «۳ پیامِ خوانده‌نشده».
     *
     * پیامِ خودِ فرستنده نخوانده حساب نمی‌شود.
     */
    public static function messengerUnread(User $user): int
    {
        if ($user->complex_id === null) {
            return 0;
        }

        return Message::where('complex_id', $user->complex_id)
            ->visibleTo($user)
            ->where('user_id', '!=', $user->id)
            ->where('is_hidden', false)
            ->whereDoesntHave('readers', fn ($q) => $q->where('users.id', $user->id))
            ->count();
    }

    /** فقط اطلاعیه‌های همگانیِ نخوانده. */
    public static function unreadAnnouncementCount(User $user): int
    {
        return Announcement::query()
            ->visibleTo($user)
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->id))
            ->count();
    }

    /**
     * اعلان‌های شخصیِ اخیر، به شکلِ یکسان با اطلاعیه‌ها.
     *
     * خروجی عمداً همان کلیدهای اطلاعیه را دارد تا فرانت یک نوعِ داده ببیند و
     * لازم نباشد دو مسیرِ رندرِ جدا داشته باشد.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function personal(User $user, int $limit = 5): array
    {
        return $user->notifications()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($notification) => [
                'id' => 'n:'.$notification->id,
                'kind' => 'personal',
                'title' => $notification->data['title'] ?? 'اعلان',
                'excerpt' => $notification->data['body'] ?? '',
                'isPinned' => false,
                'isRead' => $notification->read_at !== null,
                'publishedAt' => Jalali::date($notification->created_at),
                'link' => self::linkFor($notification->data ?? []),
            ])
            ->all();
    }

    /**
     * اعلان‌های شخصی برای **تاریخچه** (R27).
     *
     * جدا از `personal()` است چون مصرفش فرق دارد: دراپ‌داون خلاصه می‌خواهد
     * و تاریخچه متنِ کامل و کلیدِ مرتب‌سازی. با یک متدِ مشترک، یکی از دو
     * مصرف‌کننده همیشه چیزی اضافه یا کم داشت.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function personalHistory(User $user, int $limit): array
    {
        return $user->notifications()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($notification) => [
                'id' => 'n:'.$notification->id,
                'kind' => 'personal',
                'title' => $notification->data['title'] ?? 'اعلان',
                'excerpt' => $notification->data['body'] ?? '',
                'isRead' => $notification->read_at !== null,
                'publishedAt' => Jalali::dateTime($notification->created_at),
                'sortAt' => $notification->created_at->timestamp,
                'link' => self::linkFor($notification->data ?? []),
            ])
            ->all();
    }

    /**
     * مقصدِ کلیک روی یک اعلانِ شخصی.
     *
     * یک‌جا جمع شده تا افزودنِ نوعِ تازه فقط همین‌جا باشد؛ پیش از این در
     * `personal()` درجا نوشته شده بود و نوعِ درخواست‌ها (R25) از قلم افتاده
     * بود — اعلان می‌آمد ولی کلیکش هیچ‌جا نمی‌برد.
     *
     * @param  array<string, mixed>  $data
     */
    private static function linkFor(array $data): ?string
    {
        return match (true) {
            isset($data['billId']) => '/my-bills/'.$data['billId'],
            isset($data['serviceRequestId']) => '/requests',
            default => null,
        };
    }

    /** خواندنِ همه‌ی اعلان‌های شخصی. */
    public static function markAllPersonalRead(User $user): int
    {
        $count = $user->unreadNotifications()->count();
        $user->unreadNotifications->markAsRead();

        return $count;
    }

    /**
     * آخرین اطلاعیه‌ها برای دراپ‌داون: نخوانده‌ها اول، بعد بقیه به ترتیب
     * تاریخ، تا کاربر با هاور کردن دقیقاً همان‌هایی را ببیند که شمارنده
     * می‌شمارد.
     *
     * @return Collection<int,Announcement>
     */
    public static function recent(User $user, int $limit = 3): Collection
    {
        $unreadIds = Announcement::query()
            ->visibleTo($user)
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->pluck('id');

        if ($unreadIds->count() >= $limit) {
            return Announcement::whereIn('id', $unreadIds)
                ->orderByDesc('is_pinned')
                ->orderByDesc('published_at')
                ->get();
        }

        /*
         * شناسه‌ها با بایندینگ می‌روند، نه با چسباندن به رشته‌ی SQL.
         *
         * مقدارها اینجا کلیدهای اصلیِ خودِ دیتابیس‌اند و ورودیِ کاربر نیستند،
         * پس این تزریقِ فعال **نبود**. ولی الگوی «رشته را به SQL بچسبان» همان
         * چیزی است که روزی کسی منبعش را عوض می‌کند و آن‌وقت تزریق می‌شود.
         */
        $placeholders = $unreadIds->isEmpty() ? '0' : $unreadIds->map(fn () => '?')->implode(',');

        return Announcement::query()
            ->visibleTo($user)
            ->orderByRaw(
                "CASE WHEN id IN ({$placeholders}) THEN 0 ELSE 1 END",
                $unreadIds->isEmpty() ? [] : $unreadIds->all(),
            )
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    /** شناسه‌ی اطلاعیه‌هایی که کاربر از میان این مجموعه خوانده است. */
    public static function readIds(User $user, iterable $announcementIds): array
    {
        return AnnouncementRead::where('user_id', $user->id)
            ->whereIn('announcement_id', collect($announcementIds)->all())
            ->pluck('announcement_id')
            ->all();
    }

    public static function markRead(Announcement $announcement, User $user): void
    {
        AnnouncementRead::firstOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $user->id],
            ['read_at' => now()],
        );
    }

    /** همه‌ی اطلاعیه‌های قابل‌مشاهده را خوانده علامت می‌زند و تعداد را برمی‌گرداند. */
    public static function markAllRead(User $user): int
    {
        $unread = Announcement::query()
            ->visibleTo($user)
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->id))
            ->pluck('id');

        if ($unread->isEmpty()) {
            return 0;
        }

        AnnouncementRead::insert(
            $unread->map(fn ($id) => [
                'announcement_id' => $id,
                'user_id' => $user->id,
                'read_at' => now(),
            ])->all(),
        );

        return $unread->count();
    }
}
