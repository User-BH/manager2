<?php

namespace App\Http\Requests;

use App\Enums\ServiceRequestCategory;
use App\Enums\ServiceRequestPriority;
use Illuminate\Validation\Rule;

/**
 * ثبتِ درخواستِ تازه (R25).
 */
class StoreServiceRequestRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:3000'],
            'category' => ['required', Rule::enum(ServiceRequestCategory::class)],

            /*
             * `Critical` عمداً اینجا نیست: اگر ساکن بتواند «بحرانی» بزند،
             * همه همیشه همان را می‌زنند و درجه‌بندی بی‌اثر می‌شود. فقط مدیر
             * می‌تواند بعداً بالا ببردش.
             */
            'priority' => ['nullable', Rule::in([
                ServiceRequestPriority::Normal->value,
                ServiceRequestPriority::Urgent->value,
            ])],

            /*
             * واحد اختیاری است ولی اگر آمد باید مالِ همین مجتمع باشد —
             * `exists` خام به مجتمع محدود نیست و `ComplexScope` روی کوئریِ
             * اعتبارسنجی اعمال نمی‌شود (همان درسِ `StoreResidentRequest`).
             */
            'unit_id' => [
                'nullable',
                Rule::exists('units', 'id')->where('complex_id', $this->currentComplexId()),
            ],

            // همان فهرستِ مجازِ پیوستِ پیام (R23b)؛ SVG عمداً نیست
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'عنوان',
            'description' => 'شرح درخواست',
            'category' => 'دسته‌بندی',
            'priority' => 'فوریت',
            'unit_id' => 'واحد',
            'attachment' => 'پیوست',
        ];
    }
}
