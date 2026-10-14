<?php

namespace App\Http\Requests;

/**
 * تعیین یا برداشتنِ ناظرِ گروه (مرحلهٔ ۱۰ · فاز ۱۰؛ بندِ ۵۶) — فقط مدیر. ناظر خودش ناظر تعیین نمی‌کند (بندِ ۵۷).
 *
 * `user_id` فقط «کدام عضو» است؛ عضوِ فعالِ همان گروه بودنش را `GroupModeration` می‌سنجد.
 */
class UpdateModeratorRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->role->isAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'min:1'],
            'moderator' => ['required', 'boolean'],
        ];
    }
}
