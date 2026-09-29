<?php

namespace App\Http\Requests;

/** ویرایشِ متنِ پیامِ خود (مرحلهٔ ۶ · فاز ۱۴). */
class UpdateMessageRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['body' => ['present', 'nullable', 'string', 'max:1000']];
    }
}
