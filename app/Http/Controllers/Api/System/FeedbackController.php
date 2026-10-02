<?php

namespace App\Http\Controllers\Api\System;

use App\Enums\FeedbackStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListFeedbackRequest;
use App\Http\Requests\UpdateFeedbackStatusRequest;
use App\Models\Feedback;
use Illuminate\Http\JsonResponse;

/**
 * صفِ بازخوردها برای ادمینِ کل (مرحلهٔ ۷ · فاز ۴).
 *
 * فقط زیرِ `role:super_admin`. بازخورد سطحِ پلتفرم است و به هیچ مجتمعی تعلق
 * ندارد، پس مدیرِ مجتمع راهی به آن ندارد.
 */
class FeedbackController extends Controller
{
    public function index(ListFeedbackRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $page = Feedback::query()
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (Feedback $item) => $this->present($item)),
            'meta' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'counts' => [
                'new' => Feedback::where('status', FeedbackStatus::New)->count(),
            ],
        ]);
    }

    public function update(UpdateFeedbackStatusRequest $request, Feedback $feedback): JsonResponse
    {
        $feedback->update(['status' => $request->validated('status')]);

        return response()->json(['feedback' => $this->present($feedback)]);
    }

    /** @return array<string, mixed> */
    private function present(Feedback $item): array
    {
        return [
            'id' => $item->id,
            'category' => $item->category->value,
            'categoryLabel' => $item->category->label(),
            'message' => $item->message,
            'name' => $item->name,
            'phone' => $item->phone,
            'status' => $item->status->value,
            'statusLabel' => $item->status->label(),
            'createdAt' => $item->created_at?->toIso8601String(),
        ];
    }
}
