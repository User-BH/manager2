<?php

namespace App\Http\Requests;

use App\Services\Assistant\AssistantAccess;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * پرسشِ دستیار (۱۳/۸). فقط متن؛ هیچ شناسه‌ی مجتمع، واحد یا کاربری از بدنه خوانده نمی‌شود — زمینه از نشستِ سرور است
 * (ایرادات ۱۱، بندِ ۴.۱۱ و آزمونِ ۱۶).
 *
 * دروازه **پیش از** اعتبارسنجی (جاروی BOLA ِ ۱۳/۸): حسابِ بی دستیار برای هر بدنه‌ای همان ۴۰۳ ِ `AssistantAccess` را
 * می‌گیرد، نه گاهی ۴۲۲.
 */
class AskAssistantRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return app(AssistantAccess::class)->allows($this->user());
    }

    protected function failedAuthorization(): never
    {
        $code = app(AssistantAccess::class)->denialFor($this->user()) ?? AssistantAccess::PLAN;

        throw new HttpResponseException(response()->json([
            'success' => false, 'message' => AssistantAccess::message($code), 'code' => $code,
        ], 403));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'min:2', 'max:'.(int) config('assistant.max_question_length', 300)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['question' => 'پرسش'];
    }
}
