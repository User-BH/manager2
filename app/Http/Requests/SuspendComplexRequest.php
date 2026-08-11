<?php

namespace App\Http\Requests;

/**
 * تعلیقِ یک مجتمع توسط ادمینِ کل (R29).
 *
 * دلیل **اجباری** است و نه اختیاری: ساکنی که فردا با پشتیبانی تماس می‌گیرد
 * باید جوابی بگیرد، و همان متن مستقیم روی صفحه‌ی تعلیق به کاربر نشان داده
 * می‌شود. دلیلِ خالی یعنی پشتیبانی هم نمی‌داند چه شده.
 */
class SuspendComplexRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'دلیل تعلیق'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.required' => 'دلیل تعلیق را بنویسید؛ همین متن به اعضای مجتمع نشان داده می‌شود.'];
    }
}
