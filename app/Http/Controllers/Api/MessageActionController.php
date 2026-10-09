<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PinMessageRequest;
use App\Http\Requests\ReactMessageRequest;
use App\Http\Requests\ReportMessageRequest;
use App\Http\Requests\UpdateMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Messenger\MessageActions;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * کار روی پیامِ موجود در یک گفت‌وگو (مرحلهٔ ۶ · فاز ۱۴).
 *
 * ─── قاعده‌ی دسترسی ─────────────────────────────────────────────────────────
 * پیام با بایندینگِ **محدود به گفت‌وگو** خوانده می‌شود (`scopeBindings`):
 * شناسه‌ی پیامِ گفت‌وگوی دیگر زیرِ این گفت‌وگو ۴۰۴ است. پیش از هر کار،
 * همان `Conversation::isVisibleTo` — نادیدنی ۴۰۴، نه ۴۰۳. قاعده‌های خودِ
 * کارها در `MessageActions` است.
 */
class MessageActionController extends Controller
{
    /** سقفِ نتیجه‌ی جست‌وجو در یک گفت‌وگو. */
    private const SEARCH_LIMIT = 30;

    public function __construct(private readonly MessageActions $actions) {}

    public function update(UpdateMessageRequest $request, Conversation $conversation, Message $message): JsonResponse
    {
        $user = $this->viewer($conversation, $message);

        $this->actions->edit($this->requireComplex(), $conversation, $message, $user, (string) $request->input('body', ''));

        return $this->respond($message, $user);
    }

    public function destroy(Conversation $conversation, Message $message): JsonResponse
    {
        $user = $this->viewer($conversation, $message);

        $this->actions->delete($conversation, $message, $user);

        return $this->respond($message, $user);
    }

    public function react(ReactMessageRequest $request, Conversation $conversation, Message $message): JsonResponse
    {
        $user = $this->viewer($conversation, $message);

        $this->actions->react($this->requireComplex(), $message, $user, (string) $request->validated('emoji'));

        return $this->respond($message, $user);
    }

    public function report(ReportMessageRequest $request, Conversation $conversation, Message $message): JsonResponse
    {
        $user = $this->viewer($conversation, $message);

        [, $created] = $this->actions->report($this->requireComplex(), $message, $user, $request->validated('reason'));

        // گزارشِ دوباره ۲۰۰ است نه خطا: کاربر همان را می‌خواست
        return response()->json(['message' => 'گزارش برای مدیریت فرستاده شد.'], $created ? 201 : 200);
    }

    public function pin(PinMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $user = $this->viewer($conversation);
        $id = $request->validated('message_id');

        // ⚠️ فقط پیامِ همین گفت‌وگو؛ شناسه‌ی گفت‌وگوی دیگر «پیدا نشد» است
        $message = $id === null
            ? null
            : Message::query()->where('conversation_id', $conversation->id)->whereKey((int) $id)->firstOrFail();

        $this->actions->pin($conversation, $message, $user);

        return response()->json([
            'pinned' => $message ? $this->present($message->fresh(MessageResource::RELATIONS), $user) : null,
        ]);
    }

    /**
     * جست‌وجو در همین گفت‌وگو.
     *
     * همان قاعده‌ی دید: پیامِ پنهان برای ساکن و پیامِ حذف‌شده برای همه
     * پیدا نمی‌شوند. نتیجه فقط تکه‌متن است، نه کلِ پیام.
     */
    public function search(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->viewer($conversation);
        // `%` و `_` در LIKE معنا دارند؛ همان پاک‌سازیِ جست‌وجوی سراسری
        $term = str_replace(['%', '_', '\\'], '', trim((string) $request->string('q')));

        if (mb_strlen($term) < 2) {
            return response()->json(['query' => $term, 'results' => [], 'message' => 'برای جستجو حداقل دو نویسه وارد کنید.']);
        }

        $results = Message::query()
            ->where('conversation_id', $conversation->id)
            // مرزِ دوره‌ی سکونت: جست‌وجو پیامِ ساکنِ قبلیِ واحد را بیرون نمی‌کشد (مرحلهٔ ۹ · فاز ۶، F4)
            ->visibleTo($user)
            ->whereNull('deleted_at')
            ->when(! $user->isAdmin(), fn ($q) => $q->where('is_hidden', false))
            ->where('body', 'like', "%{$term}%")
            ->orderByDesc('id')
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->map(fn (Message $m) => [
                'id' => $m->id,
                'excerpt' => Str::limit(preg_replace('/\s+/u', ' ', (string) $m->body) ?? '', 120),
                'authorName' => $m->author_name,
                'sentAt' => Jalali::dateTime($m->created_at),
            ])
            ->values();

        return response()->json(['query' => $term, 'results' => $results]);
    }

    private function viewer(Conversation $conversation, ?Message $message = null): User
    {
        $user = Auth::user();
        abort_unless($conversation->isVisibleTo($user), 404);

        /*
         * خودِ پیام هم باید برای او دیدنی باشد (مرحلهٔ ۹ · فاز ۶، F4): در رشته‌ی
         * واحد، واکنش یا گزارش روی شناسه‌ی پیامِ ساکنِ قبلی متنش را برمی‌گرداند.
         */
        abort_if(
            $message !== null && ! Message::query()->visibleTo($user)->whereKey($message->id)->exists(),
            404,
        );

        return $user;
    }

    private function respond(Message $message, User $user): JsonResponse
    {
        return response()->json(['message' => $this->present($message->fresh(MessageResource::RELATIONS), $user)]);
    }

    /** @return array<string, mixed> */
    private function present(Message $message, User $viewer): array
    {
        return (new MessageResource($message, $viewer))->toArray(request());
    }
}
