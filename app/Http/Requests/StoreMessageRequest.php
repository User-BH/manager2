<?php

namespace App\Http\Requests;

use App\Enums\MessageAudience;
use Illuminate\Validation\Rule;

/**
 * پیامِ تازه در پیام‌رسانِ داخلی.
 *
 * از `Api/MessengerController.php::store()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class StoreMessageRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:1000'],

            /*
             * مخاطب (R23) — فقط مدیر می‌تواند تعیینش کند و خودِ کنترلر این را
             * اعمال می‌کند. اینجا صرفاً شکلِ ورودی سنجیده می‌شود.
             */
            'audience' => ['nullable', Rule::enum(MessageAudience::class)],

            /*
             * `exists` خام به مجتمع محدود نیست و `ComplexScope` روی کوئریِ
             * اعتبارسنجی اعمال نمی‌شود — همان درسِ `StoreResidentRequest`.
             * بدونِ این قید، شناسه‌ی واحدِ مجتمعِ دیگری هم پذیرفته می‌شد.
             */
            'unit_ids' => ['nullable', 'array', 'max:200'],
            'unit_ids.*' => [
                Rule::exists('units', 'id')->where('complex_id', $this->currentComplexId()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['body.required' => 'متن پیام را وارد کنید.', 'body.max' => 'پیام بیش از حد طولانی است.'];
    }
}
