<?php

namespace App\Http\Requests;

use App\Enums\PollVoterScope;
use App\Enums\PollWeightMode;
use Illuminate\Validation\Rule;

/**
 * پیام در یک گفت‌وگو (مرحلهٔ ۶ · فاز ۱۲).
 *
 * همان قیدهای `StoreMessageRequest`، بی‌مخاطب — مخاطب خودِ گفت‌وگوست — و با
 * `client_id`: شناسه‌ای که کلاینت پیش از ارسال می‌سازد تا ارسالِ دوباره پس از
 * قطعی، پیامِ دوم نسازد.
 */
class SendConversationMessageRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required_without_all:attachment,poll_question', 'nullable', 'string', 'max:1000'],
            'client_id' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:4096'],
            'poll_question' => ['nullable', 'string', 'max:255'],
            'poll_options' => ['nullable', 'array', 'min:2', 'max:10', 'required_with:poll_question'],
            'poll_options.*' => ['required', 'string', 'max:120'],
            'poll_voter_scope' => ['nullable', Rule::enum(PollVoterScope::class)],
            'poll_weight_mode' => ['nullable', Rule::enum(PollWeightMode::class)],
            'poll_quorum_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'poll_allow_change' => ['nullable', 'boolean'],
            'poll_closes_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'body.required_without_all' => 'متن پیام را وارد کنید.',
            'body.max' => 'پیام بیش از حد طولانی است.',
        ];
    }
}
