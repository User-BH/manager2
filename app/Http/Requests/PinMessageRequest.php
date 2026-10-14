<?php

namespace App\Http\Requests;

/**
 * سنجاق یا برداشتنِ سنجاقِ یک پیام (مرحلهٔ ۶ · فاز ۱۴؛ چند سنجاق و اختیارِ هر گفت‌وگو: ۱۰/۱۰). `message_id` ِ تهی همه را
 * برمی‌دارد. ⚠️ اجازه در `ConversationRights` است، نه این‌جا: در گفت‌وگوی خصوصی عضو هم سنجاق می‌کند (بندِ ۵۹).
 */
class PinMessageRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message_id' => ['present', 'nullable', 'integer', 'min:1'],
            'pinned' => ['nullable', 'boolean'],
        ];
    }
}
