<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Support\Jalali;
use App\Support\Notifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * زنگوله‌ی هدر.
 *
 * منبع اعلان‌ها همان اطلاعیه‌هاست؛ جدول جداگانه‌ای ساخته نشده تا «همه‌ی
 * اعلان‌ها» دقیقاً به همان صفحه‌ی اطلاعیه‌ها برسد و دو فهرست موازی که از هم
 * جدا می‌افتند به وجود نیاید.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $limit = min(max((int) $request->integer('limit', 3), 1), 10);

        $recent = Notifications::recent($user, $limit);
        $readIds = Notifications::readIds($user, $recent->pluck('id'));

        return response()->json([
            'unreadCount' => Notifications::unreadCount($user),
            'items' => $recent->map(fn (Announcement $a) => [
                'id' => $a->id,
                'title' => $a->title,
                // متن کامل در دراپ‌داون جا نمی‌شود؛ خلاصه‌ی کوتاه کافی است
                'excerpt' => Str::limit(preg_replace('/\s+/u', ' ', $a->body), 90),
                'isPinned' => (bool) $a->is_pinned,
                'isRead' => in_array($a->id, $readIds, true),
                'publishedAt' => $a->published_at ? Jalali::date($a->published_at) : null,
            ])->values(),
        ]);
    }

    /** خواندنِ یک اطلاعیه (کلیک روی آن در دراپ‌داون یا در فهرست). */
    public function read(Announcement $announcement): JsonResponse
    {
        $user = Auth::user();

        // اگر کاربر اجازه‌ی دیدن این اطلاعیه را ندارد، نباید بتواند علامتش بزند
        abort_unless(
            Announcement::query()->visibleTo($user)->whereKey($announcement->id)->exists(),
            403,
        );

        Notifications::markRead($announcement, $user);

        return response()->json(['unreadCount' => Notifications::unreadCount($user)]);
    }

    public function readAll(): JsonResponse
    {
        $user = Auth::user();
        $marked = Notifications::markAllRead($user);

        return response()->json([
            'marked' => $marked,
            'unreadCount' => Notifications::unreadCount($user),
        ]);
    }
}
