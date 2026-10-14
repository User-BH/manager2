<?php

namespace App\Http\Requests;

use App\Http\Controllers\Api\ComplexInformationController;
use App\Support\Digits;
use Illuminate\Validation\Rule;

/**
 * «اطلاعاتِ مجتمع» (مرحلهٔ ۱۰ · فاز ۷؛ بندِ ۷).
 *
 * ⚠️ نوعِ مجتمع این‌جا نیست: به تعرفه گره خورده و فقط ادمینِ کل عوضش می‌کند (R67). شمارش‌ها هم نیستند — از داده
 * حساب می‌شوند، نه از فرم (بندِ ۲). هیچ فیلدی اجباری نیست جز آنچه برای «تکمیل‌شده» لازم است، تا مدیر به پرکردنِ
 * فیلدِ نامرتبط مجبور نشود.
 */
class UpdateComplexInformationRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('postal_code'))) {
            $this->merge(['postal_code' => Digits::toLatin($this->input('postal_code'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'brand_name' => ['nullable', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:255'],
            'province' => ['required', 'string', 'max:60'],
            'city' => ['required', 'string', 'max:60'],
            'postal_code' => ['nullable', 'regex:/^\d{10}$/'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'description' => ['nullable', 'string', 'max:2000'],
            'structure' => ['nullable', 'string', 'max:500'],
            'common_areas' => ['nullable', 'string', 'max:500'],
            'facilities' => ['nullable', 'array'],
            'facilities.*' => ['string', 'distinct', Rule::in(array_keys(ComplexInformationController::FACILITIES))],
            'facilities_other' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'board' => ['nullable', 'string', 'max:500'],
            'response_hours' => ['nullable', 'string', 'max:150'],
            'emergency_info' => ['nullable', 'string', 'max:1000'],
            // تعرفه و شمارش‌ها از جای دیگر
            'type' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'نامِ مجتمع', 'brand_name' => 'نامِ تجاری', 'address' => 'نشانی', 'province' => 'استان',
            'city' => 'شهر', 'postal_code' => 'کدِ پستی', 'latitude' => 'عرضِ جغرافیایی', 'longitude' => 'طولِ جغرافیایی',
            'description' => 'توضیحات', 'structure' => 'ساختارِ ساختمان', 'common_areas' => 'بخش‌های مشترک',
            'facilities' => 'امکانات', 'facilities_other' => 'سایرِ امکانات', 'phone' => 'تلفنِ مدیریت',
            'board' => 'هیئت‌مدیره', 'response_hours' => 'ساعاتِ پاسخ‌گویی', 'emergency_info' => 'اطلاعاتِ اضطراری',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'postal_code.regex' => 'کدِ پستی ده رقم است.',
            'type.prohibited' => 'نوعِ مجتمع به تعرفه بسته است و فقط ادمینِ کل آن را عوض می‌کند.',
        ];
    }
}
