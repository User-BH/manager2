<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * تأییدِ درخواستِ پیوستن — با نقش **و واحد** (مرحلهٔ ۹ · فاز ۶، F6).
 *
 * پیش از این تأیید فقط نقش می‌گرفت و عضوِ مجتمع **بی هیچ واحدی** ساخته می‌شد:
 * داشبوردِ واحد خالی، بی قبض و بی رشته‌ی پیام. حالا واحدِ همین مجتمع (حذف‌نشده)
 * اجباری است و دوره‌اش از `TenureService` باز می‌شود.
 */
class ApproveJoinRequestRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'in:owner,tenant'],
            'unit_id' => [
                'required',
                Rule::exists('units', 'id')
                    ->where('complex_id', $this->currentComplexId())
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['unit_id.required' => 'برای تأیید، واحدِ این عضو را انتخاب کنید.'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['role' => 'نقش', 'unit_id' => 'واحد'];
    }
}
