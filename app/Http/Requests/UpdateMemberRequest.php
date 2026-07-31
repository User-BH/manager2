<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Validation\Rule;

/**
 * تغییرِ نقش یا وضعیتِ فعال‌بودنِ یک عضو.
 *
 * از `Api/System/MemberController.php::update()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class UpdateMemberRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(array_keys(UserRole::options()))],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['role' => 'نقش'];
    }
}
