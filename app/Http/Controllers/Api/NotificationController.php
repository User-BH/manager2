<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationChannelKey;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Support\Jalali;
use App\Support\NotificationPreferences;
use App\Support\Notifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * زنگوله‌ی هدر.
 *
 * دو منبع دارد و **یک** فهرست: اطلاعیه‌های همگانی، و اعلان‌های شخصیِ لاراول
 * (مثل نتیجه‌ی بررسیِ رسید، از R12). نگرانیِ اولیه «دو فهرستِ موازی که از هم
 * جدا می‌افتند» بود؛ به‌جای ساختنِ فهرستِ دوم، هر دو در `Notifications` ادغام
 * می‌شوند و کاربر یک زنگوله و یک شمارنده می‌بیند.
 *
 * هر آیتم `kind` دارد (`announcement` یا `personal`) تا فرانت بداند کلیک روی
 * آن کجا باید ببرد.
 */
class NotificationController extends Controller
{
    /** سقفِ ادغامِ در-حافظه‌ی تاریخچه، از هر منبع. */
    private const HISTORY_CAP = 200;

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $limit = min(max((int) $request->integer('limit', 3), 1), 10);

        $recent = Notifications::recent($user, $limit);
        $readIds = Notifications::readIds($user, $recent->pluck('id'));

        $announcements = $recent->map(fn (Announcement $a) => [
            'id' => 'a:'.$a->id,
            'kind' => 'announcement',
            'title' => $a->title,
            // متن کامل در دراپ‌داون جا نمی‌شود؛ خلاصه‌ی کوتاه کافی است
            'excerpt' => Str::limit(preg_replace('/\s+/u', ' ', $a->body), 90),
            'isPinned' => (bool) $a->is_pinned,
            'isRead' => in_array($a->id, $readIds, true),
            'publishedAt' => $a->published_at ? Jalali::date($a->published_at) : null,
            'announcementId' => $a->id,
        ])->all();

        /*
         * نخوانده‌ها اول، بعد بقیه — همان قاعده‌ای که پیش‌تر فقط برای
         * اطلاعیه‌ها بود، حالا روی فهرستِ ادغام‌شده اعمال می‌شود تا کاربر
         * چیزی را که شمارنده می‌شمارد بالای فهرست ببیند.
         */
        /*
         * پیام‌رسان یک سطرِ تجمیعی می‌گیرد، نه یک سطر به ازای هر پیام (R23b).
         * وضعیتِ خوانده‌شدنش در خودِ پیام‌رسان عوض می‌شود، پس اینجا فقط
         * نمایش داده می‌شود.
         */
        $messenger = Notifications::messengerUnread($user);

        $items = collect([
            ...($messenger > 0 ? [[
                'id' => 'm:unread',
                'kind' => 'messenger',
                'title' => 'پیام‌رسان',
                'excerpt' => $messenger.' پیام خوانده‌نشده دارید.',
                'isPinned' => false,
                'isRead' => false,
                'publishedAt' => null,
                'link' => '/messenger',
            ]] : []),
            ...$announcements,
            ...Notifications::personal($user, $limit),
        ])
            ->sortBy(fn (array $item) => $item['isRead'] ? 1 : 0)
            ->take($limit)
            ->values()
            ->all();

        return response()->json([
            'unreadCount' => Notifications::unreadCount($user),
            'items' => $items,
        ]);
    }

    /**
     * تاریخچه‌ی کاملِ اعلان‌ها (R27).
     *
     * ─── چرا جدا از `index()` ───────────────────────────────────────────────
     * `index()` دراپ‌داونِ زنگوله را می‌سازد و عمداً سه تا پنج آیتم
     * برمی‌گرداند. تا امروز راهی برای دیدنِ بقیه نبود: اعلانی که کاربر
     * یک بار از دستش می‌داد، برای همیشه از دسترس خارج می‌شد. اینجا همان
     * دو منبع با صفحه‌بندیِ واقعی می‌آیند.
     */
    public function history(Request $request): JsonResponse
    {
        $user = Auth::user();
        $perPage = 20;
        $page = max(1, (int) $request->integer('page', 1));

        /*
         * دو منبعِ متفاوت با ساختارِ متفاوت را نمی‌شود با یک کوئریِ SQL
         * صفحه‌بندی کرد. تعدادشان در عمل کوچک است (اطلاعیه‌های یک مجتمع و
         * اعلان‌های یک کاربر)، پس ادغام در حافظه انجام می‌شود — با سقفِ
         * صریح تا اگر روزی بزرگ شد، بی‌سروصدا کند نشود.
         */
        $announcements = Announcement::query()
            ->visibleTo($user)
            ->orderByDesc('published_at')
            ->limit(self::HISTORY_CAP)
            ->get();

        $readIds = Notifications::readIds($user, $announcements->pluck('id'));

        $items = collect([
            ...$announcements->map(fn (Announcement $a) => [
                'id' => 'a:'.$a->id,
                'kind' => 'announcement',
                'title' => $a->title,
                'excerpt' => Str::limit(preg_replace('/\s+/u', ' ', $a->body), 160),
                'isRead' => in_array($a->id, $readIds, true),
                'publishedAt' => $a->published_at ? Jalali::dateTime($a->published_at) : null,
                // اطلاعیه‌ی منتشرنشده ته فهرست می‌نشیند، نه بالای آن
                'sortAt' => $a->published_at === null ? 0 : $a->published_at->timestamp,
                'link' => '/announcements',
            ]),
            ...Notifications::personalHistory($user, self::HISTORY_CAP),
        ])
            ->sortByDesc('sortAt')
            ->values();

        return response()->json([
            'items' => $items->forPage($page, $perPage)->values()->all(),
            'total' => $items->count(),
            'currentPage' => $page,
            'lastPage' => max(1, (int) ceil($items->count() / $perPage)),
            'unreadCount' => Notifications::unreadCount($user),
        ]);
    }

    /** تنظیماتِ اعلانِ کاربر (R27). */
    public function settings(NotificationPreferences $preferences): JsonResponse
    {
        return response()->json(['channels' => $preferences->all(Auth::user())]);
    }

    /** روشن/خاموش کردنِ یک کانال. */
    public function updateSettings(Request $request, NotificationPreferences $preferences): JsonResponse
    {
        $key = NotificationChannelKey::tryFrom((string) $request->input('key'));
        abort_if($key === null, 422);

        $preferences->set(Auth::user(), $key, $request->boolean('enabled'));

        return response()->json(['channels' => $preferences->all(Auth::user())]);
    }

    /** خواندنِ یک اطلاعیه (کلیک روی آن در دراپ‌داون یا در فهرست). */
    public function read(Announcement $announcement): JsonResponse
    {
        $user = Auth::user();

        // اگر کاربر اجازه‌ی دیدن این اطلاعیه را ندارد، نباید بتواند علامتش بزند
        $this->authorize('view', $announcement);

        Notifications::markRead($announcement, $user);

        return response()->json(['unreadCount' => Notifications::unreadCount($user)]);
    }

    /**
     * خواندنِ یک اعلانِ شخصی.
     *
     * جست‌وجو روی رابطه‌ی خودِ کاربر انجام می‌شود و نه روی کلِ جدول؛ این‌طور
     * کسی نمی‌تواند با حدسِ شناسه، اعلانِ کاربرِ دیگری را خوانده علامت بزند.
     */
    public function readPersonal(string $id): JsonResponse
    {
        $user = Auth::user();

        $notification = $user->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        return response()->json(['unreadCount' => Notifications::unreadCount($user)]);
    }

    public function readAll(): JsonResponse
    {
        $user = Auth::user();

        // هر دو منبع با هم صفر می‌شوند، وگرنه شمارنده روی عددی گیر می‌کرد
        // که کاربر راهی برای پاک‌کردنش نداشت.
        $marked = Notifications::markAllRead($user) + Notifications::markAllPersonalRead($user);

        return response()->json([
            'marked' => $marked,
            'unreadCount' => Notifications::unreadCount($user),
        ]);
    }
}
