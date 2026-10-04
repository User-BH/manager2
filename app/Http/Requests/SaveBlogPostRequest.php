<?php

namespace App\Http\Requests;

use App\Models\BlogPost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ساخت و ویرایشِ مقاله‌ی بلاگ (مرحلهٔ ۶ · فاز ۱۱). فقط سوپرادمین.
 *
 * ⚠️ `body` مارک‌داون است و هر چیزی می‌تواند باشد — امنیتش را `BlogRenderer`
 * می‌سازد، نه اعتبارسنجی. این‌جا فقط اندازه‌ها و ارجاع‌ها سنجیده می‌شوند.
 */
class SaveBlogPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:180', 'regex:/^[\p{L}\p{N}-]+$/u'],
            'excerpt' => ['nullable', 'string', 'max:400'],
            'body' => [
                'required', 'string', 'max:200000',
                /*
                 * هر تصویرِ متن متنِ جایگزین می‌خواهد (مرحلهٔ ۸ · فاز ۳): صفحه‌خوان
                 * و موتورِ جست‌وجو تصویر را با همین می‌شناسند، و اگر تصویر بار
                 * نشود همین به‌جایش نشان داده می‌شود.
                 */
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && preg_match('/!\[\s*\]\(/u', $value)) {
                        $fail('هر تصویرِ متن باید توضیح داشته باشد: ![توضیحِ تصویر](نشانی)');
                    }
                },
            ],
            'category_id' => ['nullable', 'integer', Rule::exists('blog_categories', 'id')],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:50'],
            'status' => ['required', Rule::in([BlogPost::DRAFT, BlogPost::PUBLISHED])],
            'published_at' => ['nullable', 'date'],
            // فقط نامِ فایلی که بارگذاریِ بلاگ ساخته؛ نه مسیرِ دلخواه
            'cover' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{40}\.(jpg|jpeg|png|webp)$/'],
            'meta_title' => ['nullable', 'string', 'max:200'],
            'meta_description' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'title' => 'عنوان', 'slug' => 'نامک', 'excerpt' => 'خلاصه', 'body' => 'متن',
            'category_id' => 'دسته', 'tags' => 'برچسب‌ها', 'status' => 'وضعیت',
            'published_at' => 'زمانِ انتشار', 'cover' => 'تصویرِ جلد',
            'meta_title' => 'عنوانِ سئو', 'meta_description' => 'توضیحِ سئو',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['slug.regex' => 'نامک فقط حرف، عدد و خط‌تیره می‌پذیرد.'];
    }
}
