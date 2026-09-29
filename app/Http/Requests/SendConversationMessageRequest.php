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
            'body' => ['required_without_all:attachment,poll_question,voice', 'nullable', 'string', 'max:1000'],
            'client_id' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
            'reply_to_id' => ['nullable', 'integer', 'min:1'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:4096'],
            /*
             * پیامِ صوتی (فاز ۱۴). نوع از **محتوای** فایل (`mimetypes`)، نه از
             * پسوند. ضبطِ MediaRecorder در کرومیوم و فایرفاکس WebM/Ogg و در
             * Safari MP4 است؛ `finfo` WebMِ صوتی را گاهی `video/webm` می‌خواند.
             * ⚠️ سقفِ ۱ مگابایت (≈ دو دقیقه Opus): جای ویدیو نیست.
             */
            'voice' => ['nullable', 'file', 'mimetypes:audio/webm,video/webm,audio/ogg,application/ogg,audio/mp4,video/mp4,audio/x-m4a', 'max:1024', 'prohibits:attachment,poll_question'],
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
            'voice.mimetypes' => 'قالبِ پیامِ صوتی پشتیبانی نمی‌شود.',
            'voice.max' => 'پیامِ صوتی بیش از حد طولانی است.',
            'body.max' => 'پیام بیش از حد طولانی است.',
        ];
    }
}
