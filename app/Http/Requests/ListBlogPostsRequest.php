<?php

namespace App\Http\Requests;

use App\Services\Blog\PublicBlog;

/**
 * فهرست و جست‌وجوی عمومیِ بلاگ از راهِ API (مرحلهٔ ۷ · فاز ۷).
 *
 * ─── ورودی پیش از قاعده‌ها تمیز می‌شود ─────────────────────────────────────
 * فاصله‌ی دو سر برداشته و فاصله‌های پیاپی یکی می‌شوند؛ عبارتی که پس از آن
 * خالی بماند یعنی «همه‌ی مقاله‌ها»، نه جست‌وجوی تهی. حداقلِ طول **پس از**
 * تمیزکاری سنجیده می‌شود، وگرنه «  ش  » از سدِ دو حرف رد می‌شد.
 *
 * نویسه‌های LIKE (`%`، `_`) این‌جا رد نمی‌شوند: `PublicBlog::term` آن‌ها را
 * برمی‌دارد و پرس‌وجو همیشه پارامتری است — پس نه تزریق ممکن است، نه عبارتِ
 * «%» همه‌چیز را برمی‌گرداند.
 */
class ListBlogPostsRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if (! is_string($this->input('q'))) {
            return;
        }

        $q = trim((string) preg_replace('/\s+/u', ' ', $this->input('q')));

        $this->merge(['q' => $q === '' ? null : $q]);
    }

    public function rules(): array
    {
        // نامکِ دسته و برچسب همان شکلی است که `BlogWriter::slugify` می‌سازد
        $slug = ['nullable', 'string', 'max:190', 'regex:/^[\p{L}\p{N}-]+$/u'];

        return [
            'q' => ['nullable', 'string', 'min:'.PublicBlog::MIN_SEARCH, 'max:80'],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'category' => $slug,
            'tag' => $slug,
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'q.min' => 'عبارتِ جست‌وجو دست‌کم '.PublicBlog::MIN_SEARCH.' حرف باشد.',
            'q.max' => 'عبارتِ جست‌وجو حداکثر ۸۰ حرف باشد.',
        ];
    }
}
