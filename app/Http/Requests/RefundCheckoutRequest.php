<?php

namespace App\Http\Requests;

/**
 * ثبتِ بازپرداختِ «پرداخت شد ولی مجتمع ساخته نشد» (مرحلهٔ ۱۲ · فاز ۲) — فقط ادمینِ کل، با مرجعِ بانکیِ بازپرداخت.
 */
class RefundCheckoutRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'min:4', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return ['reference' => 'مرجعِ بازپرداخت'];
    }
}
