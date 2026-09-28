<?php

namespace App\Http\Requests;

/** «تا این پیام خواندم» (مرحلهٔ ۶ · فاز ۱۲). */
class ReadConversationRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['up_to' => ['required', 'integer', 'min:1']];
    }
}
