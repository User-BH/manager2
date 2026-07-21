<?php

namespace App\Http\Controllers\Api;

use App\Enums\AnnouncementAudience;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AnnouncementController extends Controller
{
    public function index(): JsonResponse
    {
        $user = Auth::user();

        $query = Announcement::query();

        if (! $user->isAdmin()) {
            // ساکن فقط اطلاعیه‌های فعالِ مربوط به نقش خودش را می‌بیند
            $query->where('is_active', true)->whereIn('audience', [
                AnnouncementAudience::All->value,
                $user->role === UserRole::Owner
                    ? AnnouncementAudience::Owners->value
                    : AnnouncementAudience::Tenants->value,
            ]);
        }

        $announcements = $query
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->paginate(15);

        return response()->json([
            'data' => collect($announcements->items())
                ->map(fn (Announcement $a) => $this->present($a))->values(),
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

        return response()->json(['announcement' => $this->present($announcement)], 201);
    }

    public function destroy(Announcement $announcement): JsonResponse
    {
        abort_unless(Auth::user()->isAdmin(), 403);

        $announcement->delete();

        return response()->json(['message' => 'اطلاعیه حذف شد.']);
    }

    private function present(Announcement $a): array
    {
        return [
            'id' => $a->id,
            'title' => $a->title,
            'body' => $a->body,
            'audience' => $a->audience->value,
            'audienceLabel' => $a->audience->label(),
            'isPinned' => (bool) $a->is_pinned,
            'isActive' => (bool) $a->is_active,
            'publishedAt' => $a->published_at ? Jalali::date($a->published_at) : null,
        ];
    }
}
