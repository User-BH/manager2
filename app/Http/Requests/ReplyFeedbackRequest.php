<?php

namespace App\Http\Requests;

/**
 * پاسخِ ادمینِ کل به بازخورد (مرحلهٔ ۸ · فاز ۶). فقط متن؛ گیرنده را سرور
 * از خودِ بازخورد پیدا می‌کند و از درخواست نمی‌پذیرد.
 */
class ReplyFeedbackRequest extends BaseFormRequest
{
    public const BODY_MAX = 2000;

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:2', 'max:'.self::BODY_MAX],
        ];
    }

    protected function prepareForValidation(): void
    {
        // همان پاک‌سازیِ فرمِ بازخورد: بی نویسه‌ی کنترلی و بازنویسیِ جهت، با نیم‌فاصله
        $body = $this->input('body');
        if (is_string($body)) {
            $this->merge(['body' => trim((string) preg_replace('/(?![\n\t])\p{Cc}|[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $body))]);
        }
    }

    public function attributes(): array
    {
        return ['body' => 'متنِ پاسخ'];
    }
}
