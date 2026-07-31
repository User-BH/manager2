<?php

namespace App\Http\Requests;

/**
 * گزارشِ خطای رندرِ مرورگر.
 *
 * از `Api/ClientErrorController.php::store()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class StoreClientErrorRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', 'max:191'],
            'message' => ['required', 'string', 'max:2000'],
            'stack' => ['nullable', 'string', 'max:8000'],
            'url' => ['nullable', 'string', 'max:500'],
        ];
    }
}
