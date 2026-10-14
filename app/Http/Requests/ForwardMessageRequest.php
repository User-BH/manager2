<?php

namespace App\Http\Requests;

/**
 * هدایتِ یک پیام به گفت‌وگوی مسیر (مرحلهٔ ۱۰ · فاز ۱۰؛ بندِ ۵۲).
 *
 * فقط شناسه‌ی پیامِ مبدأ؛ متن، پیوست و نامِ نویسنده از خودِ پیام خوانده می‌شوند، نه از درخواست. `client_id` همان
 * تکرارناپذیریِ ارسال است.
 */
class ForwardMessageRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message_id' => ['required', 'integer', 'min:1'],
            'client_id' => ['nullable', 'string', 'max:40'],
        ];
    }
}
