<?php

namespace App\Http\Requests;

use App\Enums\PollVoterScope;
use App\Enums\PollWeightMode;
use App\Services\Messenger\AttachmentPolicy;
use Illuminate\Http\UploadedFile;
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
        $instead = $this->user()?->role->isAdmin() ? 'attachment,poll_question,voice,video_message' : 'attachment,voice,video_message';

        return [
            /*
             * نظرسنجی فقط کارِ مدیر است و فیلدهایش از دیگران نادیده‌اند؛ پس پرسشِ نظرسنجیِ غیرِمدیر جای متن را نمی‌گیرد —
             * وگرنه پیامِ خالی ثبت می‌شد (یافته‌ی «چند نکته»، بندِ ۴: آزمونِ خطای فارسی ۲۰۱ گرفت، نه ۴۲۲).
             */
            'body' => ['required_without_all:'.$instead, 'nullable', 'string', 'max:1000'],
            'client_id' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
            'reply_to_id' => ['nullable', 'integer', 'min:1'],
            /*
             * ۱۰/۱۲ (بندهای ۴۳، ۴۶ و ۴۷): تصویر، ویدیو، صدا و سند — پسوند و محتوا با هم (`AttachmentPolicy`)، و سقفِ
             * هر نوع از `config/messenger.php`. `max` ِ بیرونی بزرگ‌ترین سقف است تا فایلِ بی‌ربط پیش از خواندنِ محتوا
             * رد شود.
             */
            'attachment' => ['nullable', 'file', 'max:'.AttachmentPolicy::largestKb(), function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $value instanceof UploadedFile) {
                    return;
                }
                $kind = AttachmentPolicy::kindOf($value);
                if ($kind === null) {
                    $fail('این نوع فایل پذیرفته نیست، یا پسوندش با محتوایش نمی‌خواند.');
                } elseif ($value->getSize() > AttachmentPolicy::limitKb($kind) * 1024) {
                    $fail('حجمِ فایل از سقفِ '.intdiv(AttachmentPolicy::limitKb($kind), 1024).' مگابایت بیشتر است.');
                }
            }],
            /*
             * پیامِ صوتی (فاز ۱۴). نوع از **محتوای** فایل (`mimetypes`)، نه از
             * پسوند. ضبطِ MediaRecorder در کرومیوم و فایرفاکس WebM/Ogg و در
             * Safari MP4 است؛ `finfo` WebMِ صوتی را گاهی `video/webm` می‌خواند.
             * ⚠️ سقفِ ۱ مگابایت (≈ دو دقیقه Opus): جای ویدیو نیست.
             */
            'voice' => ['nullable', 'file', 'mimetypes:audio/webm,video/webm,audio/ogg,application/ogg,audio/mp4,video/mp4,audio/x-m4a', 'max:'.AttachmentPolicy::limitKb('voice'), 'prohibits:attachment,poll_question,video_message'],
            // پیامِ ویدیویی (بندِ ۴۵): ضبطِ خودِ پیام‌رسان، نوع از محتوا، سقفِ جدا
            'video_message' => ['nullable', 'file', 'mimetypes:video/webm,video/mp4,video/quicktime', 'max:'.AttachmentPolicy::limitKb('video_message'), 'prohibits:attachment,poll_question,voice'],
            'poll_question' => ['nullable', 'string', 'max:255'],
            'poll_options' => ['nullable', 'array', 'min:2', 'max:10', 'required_with:poll_question'],
            'poll_options.*' => ['required', 'string', 'max:120'],
            'poll_voter_scope' => ['nullable', Rule::enum(PollVoterScope::class)],
            'poll_weight_mode' => ['nullable', Rule::enum(PollWeightMode::class)],
            'poll_quorum_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'poll_allow_change' => ['nullable', 'boolean'],
            // چندگزینه‌ای (۱۰/۱۱): ۱ تک‌گزینه‌ای؛ سقف از شمارِ گزینه‌ها نمی‌گذرد
            'poll_max_selections' => ['nullable', 'integer', 'min:1', 'max:10', function (string $attribute, mixed $value, \Closure $fail): void {
                if ((int) $value > count((array) $this->input('poll_options', []))) {
                    $fail('سقفِ انتخاب از شمارِ گزینه‌ها بیشتر است.');
                }
            }],
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
            // فهرستِ MIME به کاربر نمی‌رسد («چند نکته»، بندِ ۴)
            'video_message.mimetypes' => 'قالبِ پیامِ ویدیویی پشتیبانی نمی‌شود.',
            'video_message.max' => 'پیامِ ویدیویی بیش از حد طولانی است.',
            'body.max' => 'پیام بیش از حد طولانی است.',
        ];
    }
}
