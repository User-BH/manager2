<?php

namespace App\Http\Requests;

/** سنجاقِ یک پیام در گفت‌وگو؛ `null` برمی‌داردش (مرحلهٔ ۶ · فاز ۱۴). */
class PinMessageRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['message_id' => ['present', 'nullable', 'integer', 'min:1']];
    }
}
