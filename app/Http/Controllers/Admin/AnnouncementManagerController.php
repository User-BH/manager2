<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AnnouncementAudience;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AnnouncementManagerController extends Controller
{
    public function index()
    {
        $announcements = Announcement::orderByDesc('is_pinned')->orderByDesc('created_at')->get();

        return view('admin.announcements.index', compact('announcements'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'audience' => ['required', 'in:all,owners,tenants'],
            'is_pinned' => ['nullable', 'boolean'],
        ], [], ['title' => 'عنوان', 'body' => 'متن']);

        Announcement::create([
            'title' => $data['title'],
            'body' => $data['body'],
            'audience' => AnnouncementAudience::from($data['audience']),
            'is_pinned' => $request->boolean('is_pinned'),
            'is_active' => true,
            'published_at' => now(),
            'created_by' => Auth::id(),
        ]);

        return back()->with('success', 'اطلاعیه منتشر شد.');
    }

    public function destroy(Announcement $announcement)
    {
        $announcement->delete();

        return back()->with('success', 'اطلاعیه حذف شد.');
    }
}
