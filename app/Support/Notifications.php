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
 */
class Notifications
{
    /** اطلاعیه‌های قابل‌مشاهده که هنوز خوانده نشده‌اند. */
    public static function unreadCount(User $user): int
    {
        return Announcement::query()
            ->visibleTo($user)
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->id))
            ->count();
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
