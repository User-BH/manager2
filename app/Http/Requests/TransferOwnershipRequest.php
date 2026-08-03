<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * انتقالِ مالکیتِ یک واحد (R26).
 *
 * جمعِ سهم‌ها اینجا سنجیده **نمی‌شود** و کارِ `TenureService` است: همان
 * قاعده باید در دستورهای کنسولی و واردکردنِ دسته‌ای هم برقرار بماند، و
 * قاعده‌ای که در دو جا نوشته شود دیر یا زود از هم واگرا می‌شود.
 */
class TransferOwnershipRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'owners' => ['required', 'array', 'min:1', 'max:10'],

            /*
             * `exists` خام به مجتمع محدود نیست و `ComplexScope` روی کوئریِ
             * اعتبارسنجی اعمال نمی‌شود؛ بدونِ این قید می‌شد کاربرِ مجتمعِ
             * دیگری را مالکِ این واحد کرد.
             */
            'owners.*.user_id' => [
                'required',
                Rule::exists('users', 'id')->where('complex_id', $this->currentComplexId()),
            ],
            'owners.*.share_percent' => ['required', 'numeric', 'min:0.01', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'owners' => 'مالکان',
            'owners.*.user_id' => 'مالک',
            'owners.*.share_percent' => 'سهم مالکیت',
        ];
    }
}
