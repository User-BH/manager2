<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * تصویرِ مقاله (جلد یا درونِ متن) — مرحلهٔ ۶ · فاز ۱۱.
 *
 * همان سه قالبی که `Uploads::serve` با نوعِ صریح برمی‌گرداند؛ SVG عمداً نیست
 * چون می‌تواند اسکریپت داشته باشد و این تصویرها روی صفحه‌ی عمومی‌اند.
 */
class UploadBlogImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'image' => ['required', 'image', Rule::file()->types(['jpg', 'jpeg', 'png', 'webp'])->max(3 * 1024)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['image' => 'تصویر'];
    }
}
