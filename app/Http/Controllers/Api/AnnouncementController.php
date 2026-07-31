<?php

namespace App\Http\Controllers\Api;

use App\Enums\AnnouncementAudience;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Support\Notifications;
use Illuminate\Http\JsonResponse;
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

    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $this->authorize('create', Announcement::class);
        $this->requireComplex();

        // قواعد در StoreAnnouncementRequest است؛ اینجا فقط داده‌ی معتبر می‌آید
        $data = $request->validated();

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

    public function update(StoreAnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        // اطلاعیه‌ی مجتمع دیگری نباید از راه دستکاری شناسه ویرایش شود؛
        // ComplexScope فهرست را محدود می‌کند ولی route-model-binding آن را دور می‌زند.
        $this->authorize('update', $announcement);

        $data = $request->validated();

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
        // پیش از این فقط نقش بررسی می‌شد و نه مالکیتِ مجتمع — Policy هر دو را دارد
        $this->authorize('delete', $announcement);

        $announcement->delete();

        return response()->json(['message' => 'اطلاعیه حذف شد.']);
    }

    /** اطلاعیه‌ی تازه‌ساخته‌شده برای خودِ نویسنده خوانده حساب می‌شود. */
    /**
     * شکلِ خروجی حالا در `AnnouncementResource` است.
     *
     * این متد فقط یک پلِ کوتاه مانده تا فراخوانی‌های موجود دست‌نخورده بمانند؛
     * نقطه‌ی حقیقتِ ساختار یکی است و افزودنِ فیلدِ تازه فقط همان‌جا انجام
     * می‌شود.
     */
    private function present(Announcement $a, bool $isRead = true): array
    {
        return (new AnnouncementResource($a, $isRead))->toArray(request());
    }
}
