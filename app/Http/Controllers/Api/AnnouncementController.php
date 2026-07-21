<?php

namespace App\Http\Controllers\Api;

use App\Enums\AnnouncementAudience;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Support\Jalali;
use App\Support\Notifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AnnouncementController extends Controller
{
    public function index(): JsonResponse
    {
        $user = Auth::user();

        $announcements = Announcement::query()
            ->visibleTo($user)
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->paginate(15);

        // کدام‌یک از همین صفحه را کاربر خوانده است (یک کوئری، نه N کوئری)
        $readIds = Notifications::readIds($user, collect($announcements->items())->pluck('id'));

        return response()->json([
            'data' => collect($announcements->items())
                ->map(fn (Announcement $a) => $this->present($a, in_array($a->id, $readIds, true)))->values(),
            'unreadCount' => Notifications::unreadCount($user),
            'meta' => [
                'currentPage' => $announcements->currentPage(),
                'lastPage' => $announcements->lastPage(),
                'total' => $announcements->total(),
            ],
            'canManage' => $user->isAdmin(),
            'audienceOptions' => collect(AnnouncementAudience::cases())
                ->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->isAdmin(), 403);
        $this->requireComplex();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'audience' => ['required', 'in:all,owners,tenants'],
            'is_pinned' => ['nullable', 'boolean'],
        ], [], ['title' => 'عنوان', 'body' => 'متن', 'audience' => 'مخاطب']);

        $announcement = Announcement::create([
            'title' => $data['title'],
            'body' => $data['body'],
            'audience' => AnnouncementAudience::from($data['audience']),
            'is_pinned' => $request->boolean('is_pinned'),
            'is_active' => true,
            'published_at' => now(),
            'created_by' => Auth::id(),
        ]);

        // نویسنده نباید بابت اطلاعیه‌ی خودش اعلان نخوانده بگیرد
        Notifications::markRead($announcement, Auth::user());

        return response()->json(['announcement' => $this->present($announcement)], 201);
    }

    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        abort_unless(Auth::user()->isAdmin(), 403);
        // اطلاعیه‌ی مجتمع دیگری نباید از راه دستکاری شناسه ویرایش شود؛
        // ComplexScope فهرست را محدود می‌کند ولی route-model-binding آن را دور می‌زند.
        abort_unless($announcement->complex_id === $this->requireComplex()->id, 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'audience' => ['required', 'in:all,owners,tenants'],
            'is_pinned' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['title' => 'عنوان', 'body' => 'متن', 'audience' => 'مخاطب']);

        $announcement->update([
            'title' => $data['title'],
            'body' => $data['body'],
            'audience' => AnnouncementAudience::from($data['audience']),
            'is_pinned' => $request->boolean('is_pinned'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json(['announcement' => $this->present($announcement->fresh())]);
    }

    public function destroy(Announcement $announcement): JsonResponse
    {
        abort_unless(Auth::user()->isAdmin(), 403);

        $announcement->delete();

        return response()->json(['message' => 'اطلاعیه حذف شد.']);
    }

    /** اطلاعیه‌ی تازه‌ساخته‌شده برای خودِ نویسنده خوانده حساب می‌شود. */
    private function present(Announcement $a, bool $isRead = true): array
    {
        return [
            'id' => $a->id,
            'title' => $a->title,
            'body' => $a->body,
            'audience' => $a->audience->value,
            'audienceLabel' => $a->audience->label(),
            'isPinned' => (bool) $a->is_pinned,
            'isActive' => (bool) $a->is_active,
            'isRead' => $isRead,
            'publishedAt' => $a->published_at ? Jalali::date($a->published_at) : null,
        ];
    }
}
