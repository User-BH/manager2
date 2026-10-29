<?php

namespace App\Http\Controllers\Api\System;

use App\Enums\FeedbackStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListFeedbackRequest;
use App\Http\Requests\ReplyFeedbackRequest;
use App\Http\Requests\UpdateFeedbackStatusRequest;
use App\Models\Feedback;
use App\Models\FeedbackReply;
use App\Models\User;
use App\Notifications\FeedbackReplyNotification;
use App\Services\Feedback\FeedbackMembership;
use App\Support\Audit;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * «بازخورد کاربران» برای ادمینِ کل (مرحلهٔ ۷ · فاز ۴؛ مرحلهٔ ۸ · فاز ۶).
 *
 * فقط زیرِ `role:super_admin`. بازخورد سطحِ پلتفرم است و به هیچ مجتمعی تعلق
 * ندارد، پس مدیرِ مجتمع راهی به آن ندارد.
 *
 * مرحلهٔ ۸ · فاز ۶: هر سطر می‌گوید فرستنده عضوِ سایت هست یا نه (فقط سمتِ سرور،
 * `FeedbackMembership`)، و به عضو می‌شود پاسخ داد — پاسخ در `feedback_replies`
 * ذخیره و به‌صورتِ اعلانِ شخصی فقط به همان کاربر می‌رسد.
 */
class FeedbackController extends Controller
{
    public function __construct(private readonly FeedbackMembership $membership) {}

    public function index(ListFeedbackRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $page = Feedback::query()
            ->with('replies.author:id,name')
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate(20);

        $members = $this->membership->resolve(collect($page->items()));

        return response()->json([
            'data' => collect($page->items())->map(fn (Feedback $item) => $this->present($item, $members[$item->id] ?? null)),
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
        $before = $feedback->status;
        $feedback->update(['status' => $request->validated('status')]);

        if ($feedback->status !== $before) {
            Audit::log('feedback.status_changed', 'وضعیتِ بازخوردِ «'.$this->sender($feedback).'» شد «'.$feedback->status->label().'»', null, [
                'feedback' => $feedback->id,
                'from' => $before->value,
                'to' => $feedback->status->value,
            ]);
        }

        return response()->json(['feedback' => $this->present($feedback->load('replies.author:id,name'), $this->membership->recipientFor($feedback))]);
    }

    /**
     * پاسخ به بازخورد — فقط اگر فرستنده عضوِ سایت است.
     *
     * گیرنده را **سرور** از خودِ بازخورد پیدا می‌کند؛ درخواست فقط متن دارد. پس
     * ادمین هم نمی‌تواند پاسخ را به کاربرِ دیگری بفرستد.
     */
    public function reply(ReplyFeedbackRequest $request, Feedback $feedback): JsonResponse
    {
        $member = $this->membership->recipientFor($feedback);

        if ($member === null) {
            return response()->json([
                'success' => false,
                'message' => 'فرستنده‌ی این پیام عضوِ سایت نیست؛ پاسخ داخلِ سایت ممکن نیست. با شماره‌اش تماس بگیرید.',
                'code' => 'feedback.not_a_member',
            ], 422);
        }

        $reply = DB::transaction(function () use ($request, $feedback, $member): FeedbackReply {
            $reply = $feedback->replies()->create([
                'author_id' => $request->user()->id,
                'recipient_id' => $member['user']->id,
                'body' => $request->validated('body'),
            ]);

            /*
             * ⚠️ وضعیت عوض نمی‌شود: صف با زبانه‌ی «تازه» باز می‌شود و پاسخ آن را
             * «خوانده‌شده» می‌کرد — پیام درست پس از پاسخ از جلوی چشمِ ادمین
             * می‌رفت (E2E گرفت). «پاسخ داده شد» از خودِ پاسخ‌ها خوانده می‌شود.
             */

            Audit::log('feedback.replied', 'پاسخ به بازخوردِ «'.$this->sender($feedback).'»', null, [
                'feedback' => $feedback->id,
                'reply' => $reply->id,
                'recipient' => $member['user']->id,
            ]);

            return $reply;
        });

        // پس از تراکنش: اعلانِ پاسخی که ذخیره نشده هرگز نمی‌رسد
        $member['user']->notify(new FeedbackReplyNotification($reply->setRelation('feedback', $feedback)));

        return response()->json([
            'message' => 'پاسخ فرستاده شد و در اعلان‌های کاربر می‌نشیند.',
            'feedback' => $this->present($feedback->fresh(['replies.author:id,name']) ?? $feedback, $member),
        ], 201);
    }

    /** نامِ فرستنده برای متنِ لاگ — بی‌نام، شماره‌ی ثبت‌شده‌ی خودِ بازخورد */
    private function sender(Feedback $feedback): string
    {
        return filled($feedback->name) ? (string) $feedback->name : 'بازخوردِ #'.$feedback->id;
    }

    /**
     * @param  array{user: User, via: string}|null  $member
     * @return array<string, mixed>
     */
    private function present(Feedback $item, ?array $member): array
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
            // تاریخ و ساعت جدا، به وقتِ تهران (بندِ ۱۳)
            'date' => $item->created_at ? Jalali::date($item->created_at) : null,
            'time' => $item->created_at ? Jalali::date($item->created_at, 'H:i') : null,
            'member' => $member === null ? null : [
                'name' => $member['user']->name,
                'via' => $member['via'],
            ],
            'replies' => $item->replies->map(fn (FeedbackReply $reply) => [
                'id' => $reply->id,
                'body' => $reply->body,
                'author' => $reply->author?->name,
                'at' => $reply->created_at ? Jalali::dateTime($reply->created_at) : null,
            ])->all(),
        ];
    }
}
