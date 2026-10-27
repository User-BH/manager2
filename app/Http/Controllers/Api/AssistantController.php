<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AskAssistantRequest;
use App\Models\AssistantMessage;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\AssistantAccess;
use App\Services\Assistant\AssistantCache;
use App\Services\Assistant\AssistantContext;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * دستیارِ داخلیِ داشبورد (مرحلهٔ ۱۳ · فاز ۸؛ ایرادات ۱۱، بخشِ ۴) — شرح در docs/ASSISTANT.md.
 *
 * 🔴 هر سه مسیر از همان دروازه می‌گذرند (`AssistantAccess`): داشبوردِ اولیه و مجتمعِ بی پلنِ پیشرفته ۴۰۳ می‌گیرند —
 * پنهان‌کردنِ دکمه کافی نیست (بندِ ۴.۲). زمینه از نشستِ سرور است؛ بدنه فقط متنِ پرسش دارد.
 */
class AssistantController extends Controller
{
    public function __construct(private readonly AssistantAccess $access) {}

    public function ask(AskAssistantRequest $request, Assistant $assistant): JsonResponse
    {
        $context = $this->context();
        $answer = $assistant->ask($context, (string) $request->validated('question'));

        $message = null;
        try {
            // پاسخِ حساس (تلفن، نامِ ساکنان) نگه داشته نمی‌شود — فقط پرسش و اینکه پاسخی بود
            $message = AssistantMessage::create([
                'user_id' => $context->user->id,
                'complex_id' => $context->complex?->id,
                'context_key' => $context->key(),
                'question' => mb_substr((string) $request->validated('question'), 0, 300),
                'answer' => $answer->sensitive ? null : $answer->text,
                'status' => $answer->status,
                'tool' => $answer->tool,
                'redacted' => $answer->sensitive,
            ]);
        } catch (Throwable $e) {
            // پایگاه‌داده در دسترس نیست — پاسخِ خطا همان است که برمی‌گردد؛ تاریخچه فدای پاسخ نمی‌شود
            report($e);
        }

        return response()->json([
            'id' => $message?->id,
            'question' => $request->validated('question'),
            'createdAt' => Jalali::dateTime($message->created_at ?? now()),
            'answer' => $answer->toArray(),
        ]);
    }

    /** گفت‌وگوی همین حساب در **همین** زمینه — پس از تعویضِ مجتمع گفت‌وگوی قبلی دیده نمی‌شود (بندِ ۴.۱۴) */
    public function history(): JsonResponse
    {
        $context = $this->context();
        $messages = AssistantMessage::where('user_id', $context->user->id)->where('context_key', $context->key())
            ->latest('id')->limit((int) config('assistant.history.shown', 40))->get()->reverse()->values();

        return response()->json([
            'context' => $context->complex->name ?? 'سکو',
            'suggestions' => Assistant::suggestions($context),
            'messages' => $messages->map(fn (AssistantMessage $m) => [
                'id' => $m->id,
                'question' => $m->question,
                'status' => $m->status,
                'text' => $m->redacted ? 'پاسخ شاملِ نام یا اطلاعاتِ تماسِ اشخاص بود و ذخیره نشد؛ برای دیدنِ دوباره، همان را بپرسید.' : (string) $m->answer,
                'redacted' => $m->redacted,
                'createdAt' => Jalali::dateTime($m->created_at),
            ])->all(),
        ]);
    }

    public function clear(): JsonResponse
    {
        $context = $this->context();
        $deleted = AssistantMessage::where('user_id', $context->user->id)->where('context_key', $context->key())->delete();
        AssistantCache::forget($context->user);

        return response()->json(['message' => 'گفت‌وگو پاک شد.', 'deleted' => $deleted]);
    }

    private function context(): AssistantContext
    {
        $user = Auth::user();
        $denial = $this->access->denialFor($user);
        if ($denial !== null) {
            abort(response()->json(['success' => false, 'message' => AssistantAccess::message($denial), 'code' => $denial], 403));
        }

        return $this->access->contextFor($user);
    }
}
