<?php

namespace App\Http\Controllers;

use App\Enums\AnnouncementAudience;
use App\Enums\UserRole;
use App\Models\Announcement;
use Illuminate\Support\Facades\Auth;

class AnnouncementController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $query = Announcement::where('is_active', true);

        if (! $user->isAdmin()) {
            $audiences = [AnnouncementAudience::All->value];
            $audiences[] = $user->role === UserRole::Owner
                ? AnnouncementAudience::Owners->value
                : AnnouncementAudience::Tenants->value;
            $query->whereIn('audience', $audiences);
        }

        $announcements = $query->orderByDesc('is_pinned')->orderByDesc('published_at')->paginate(10);

        return view('announcements.index', compact('announcements'));
    }
}
