<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * تصویرِ پروفایل (مرحلهٔ ۱۰ · فاز ۷). نوع و حجم اینجا؛ «واقعاً تصویر است» و پاک‌کردنِ EXIF در `ProfilePhoto`.
 */
class UploadAvatarRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'image', Rule::file()->types(['jpg', 'jpeg', 'png', 'webp'])->max(4 * 1024)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['avatar' => 'تصویرِ پروفایل'];
    }
}
