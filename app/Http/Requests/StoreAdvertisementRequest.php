<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * بنرِ تبلیغاتیِ صفحه‌ی فرود.
 *
 * از `Api/System/AdvertisementController.php::validated()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class StoreAdvertisementRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            /*
                 * فقط http/https پذیرفته می‌شود. بدون این شرط، مقداری مثل
                 * `javascript:...` در href می‌نشست و صفحه‌ی فرودِ عمومی به
                 * ناقل XSS تبدیل می‌شد.
                 */
            'href' => ['required', 'string', 'max:500', 'url', 'starts_with:http://,https://'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'image' => [
                $this->isCreating() ? 'required' : 'nullable',
                'image', Rule::file()->types(['jpg', 'jpeg', 'png', 'webp'])->max(3 * 1024),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'href.url' => 'لینک مقصد باید یک آدرس کامل و معتبر باشد.',
            'href.starts_with' => 'لینک مقصد باید با http:// یا https:// شروع شود.',
            'ends_at.after' => 'تاریخ پایان باید بعد از تاریخ شروع باشد.',
            'image.required' => 'انتخاب تصویر بنر الزامی است.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'عنوان',
            'subtitle' => 'توضیح کوتاه',
            'href' => 'لینک مقصد',
            'sort_order' => 'ترتیب نمایش',
            'starts_at' => 'تاریخ شروع',
            'ends_at' => 'تاریخ پایان',
            'image' => 'تصویر بنر',
        ];
    }

    /**
     * ساخت است یا ویرایش؟
     *
     * هنگامِ ساخت، تصویر اجباری است؛ هنگامِ ویرایش نه، چون ممکن است ادمین فقط
     * متن را عوض کند و تصویرِ قبلی سرِ جایش بماند. تشخیص از روی وجودِ
     * پارامترِ مسیر است، نه یک پرچمِ دستی که ممکن است اشتباه پاس داده شود.
     */
    private function isCreating(): bool
    {
        return $this->route('advertisement') === null;
    }
}
