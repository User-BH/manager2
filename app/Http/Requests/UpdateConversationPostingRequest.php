<?php

namespace App\Http\Requests;

/**
 * «فقط مدیریت بنویسد» برای گروهِ مجتمع یا گروهِ مدیر (مرحلهٔ ۱۰ · فاز ۹).
 *
 * گروهِ مجتمع از ۱۰/۹ جای گفت‌وگوی اعضاست (بندهای ۳۲، ۵۴ و ۵۵)؛ این همان تصمیمِ پیشینِ «فقط مدیریت» را برای مدیر
 * نگه می‌دارد، بی آنکه گروهِ دوم یا کانالِ جدا لازم شود. ناظرِ گروه (۱۰/۱۰) این اختیار را ندارد.
 */
class UpdateConversationPostingRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->role->isAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['members_can_post' => ['required', 'boolean']];
    }
}
