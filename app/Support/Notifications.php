<?php

namespace App\Support;

use App\Models\Announcement;
use App\Models\AnnouncementRead;
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
    /** مجموعِ نخوانده‌ها از هر دو منبع — همان عددی که روی زنگوله می‌نشیند. */
    public static function unreadCount(User $user): int
    {
        return self::unreadAnnouncementCount($user) + $user->unreadNotifications()->count();
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
                'link' => isset($notification->data['billId'])
                    ? '/my-bills/'.$notification->data['billId']
                    : null,
            ])
            ->all();
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

        return Announcement::query()
            ->visibleTo($user)
            ->orderByRaw('CASE WHEN id IN ('.($unreadIds->isEmpty() ? '0' : $unreadIds->implode(',')).') THEN 0 ELSE 1 END')
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
