<?php

namespace App\Http\Requests;

use App\Enums\OccupancyStatus;
use Illuminate\Validation\Rule;

/**
 * واحد — همان قواعد برای ساخت و ویرایش.
 *
 * از `Api/UnitController.php::validateData()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class StoreUnitRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unit_number' => ['required', 'string', 'max:20'],
            // exists خام به مجتمع محدود نیست؛ بدون این قید می‌شد واحد را به
            // ساختمانِ مجتمع دیگری چسباند.
            'building_id' => [
                'nullable',
                Rule::exists('buildings', 'id')->where('complex_id', $this->currentComplexId()),
            ],
            'floor' => ['required', 'integer', 'min:-5', 'max:200'],
            'area' => ['required', 'numeric', 'min:0'],
            'residents_count' => ['required', 'integer', 'min:0'],
            'parking_count' => ['nullable', 'integer', 'min:0'],
            'occupancy_status' => ['required', 'in:'.implode(',', array_column(OccupancyStatus::cases(), 'value'))],
            'coefficient' => ['required', 'numeric', 'min:0'],
            'uses_elevator' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'unit_number' => 'شماره واحد',
            'floor' => 'طبقه',
            'area' => 'متراژ',
            'residents_count' => 'تعداد ساکنین',
            'occupancy_status' => 'وضعیت سکونت',
            'coefficient' => 'ضریب',
        ];
    }
}
