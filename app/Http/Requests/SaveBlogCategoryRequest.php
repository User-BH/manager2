<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** دسته‌ی بلاگ (مرحلهٔ ۶ · فاز ۱۱). فقط سوپرادمین. */
class SaveBlogCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'نامِ دسته', 'description' => 'توضیح'];
    }
}
