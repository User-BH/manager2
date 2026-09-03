<?php

namespace App\Http\Requests;

use App\Enums\ComplexType;
use Illuminate\Validation\Rule;

/**
 * تغییرِ نوعِ مجتمع — فقط ادمینِ کل (R67).
 *
 * ⚠️ مسیرش پشتِ `role:super_admin` است، پس مجوز اینجا دوباره سنجیده
 * نمی‌شود؛ این کلاس فقط شکلِ ورودی را تضمین می‌کند.
 */
class UpdateComplexTypeRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(ComplexType::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['type' => 'نوع مجتمع'];
    }
}
