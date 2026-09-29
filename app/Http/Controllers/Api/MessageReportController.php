<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResolveReportRequest;
use App\Models\MessageReport;
use App\Services\Messenger\MessageActions;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * صفِ گزارش‌های پیام برای مدیرِ مجتمع (مرحلهٔ ۶ · فاز ۱۴).
 *
 * `MessageReport` دامنه‌ی مستأجر دارد؛ گزارشِ مجتمعِ دیگر اصلاً پیدا نمی‌شود.
 * هر پیام یک سطر است، با شمارِ گزارش‌دهنده‌ها — نه نامشان: مدیر باید پیام را
 * بسنجد، نه اینکه چه کسی از چه کسی شکایت کرده.
 */
class MessageReportController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', MessageReport::class);

        $open = MessageReport::query()
            ->whereNull('resolved_at')
            ->with('message')
            ->orderByDesc('id')
            ->get()
            ->groupBy('message_id');

        $items = $open->map(function ($reports) {
            /** @var MessageReport $first */
            $first = $reports->first();
            $message = $first->message;

            return [
                'id' => $first->id,
                'messageId' => $first->message_id,
                'conversationId' => $message?->conversation_id,
                'excerpt' => $message ? Str::limit((string) $message->body, 160) : null,
                'authorName' => $message?->author_name,
                'isHidden' => (bool) $message?->is_hidden,
                'reportCount' => $reports->count(),
                'reasons' => $reports->pluck('reason')->filter()->map(fn ($r) => Str::limit((string) $r, 120))->values(),
                'reportedAt' => Jalali::dateTime($first->created_at),
            ];
        })->values();

        return response()->json(['data' => $items, 'openCount' => $items->count()]);
    }

    public function resolve(ResolveReportRequest $request, MessageReport $report, MessageActions $actions): JsonResponse
    {
        $this->authorize('resolve', $report);

        $actions->resolve($report, Auth::user(), (string) $request->validated('resolution'));

        return response()->json(['message' => $request->validated('resolution') === MessageReport::HIDE
            ? 'پیام برای ساکنین پنهان شد.'
            : 'گزارش کنار گذاشته شد.']);
    }
}
