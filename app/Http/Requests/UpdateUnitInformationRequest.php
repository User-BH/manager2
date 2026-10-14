<?php

namespace App\Http\Requests;

use App\Http\Controllers\Api\UnitInformationController;
use App\Models\Unit;
use App\Support\Digits;
use Illuminate\Validation\Rule;

/**
 * «اطلاعاتِ واحد» (مرحلهٔ ۱۰ · فاز ۷؛ بندِ ۹) — فقط مشخصاتِ توصیفی.
 *
 * ⚠️ مساحت، طبقه، شماره، ساختمان، تعدادِ ساکنان، ضریب، آسانسور، پارکینگ و انباری (تعداد)، وضعیتِ سکونت و مانده
 * ورودیِ شارژ و وزنِ نظرسنجی‌اند (`ChargeCalculator`، `PollService`). این مسیر — که مالک و مستأجر هم صدایش
 * می‌زنند — هیچ‌کدام را نمی‌پذیرد، حتی از مدیر: آن‌ها فقط از فرمِ واحدِ مدیر (`UnitController::update`) عوض می‌شوند.
 */
class UpdateUnitInformationRequest extends BaseFormRequest
{
    /** ستون‌هایی که این مسیر هرگز نمی‌نویسد */
    public const BILLING_FIELDS = [
        'unit_number', 'floor', 'building_id', 'area', 'residents_count', 'coefficient', 'uses_elevator',
        'parking_count', 'storage_count', 'occupancy_status', 'balance', 'is_active', 'complex_id', 'notes',
    ];

    /**
     * مجوز **پیش از** اعتبارسنجی: ساکنِ واحدِ دیگر باید همان ۴۰۴ ِ «نیست» را بگیرد، نه ۴۲۲ ِ «فیلدها غلط‌اند»
     * (جاروی BOLA ِ ۱۰/۷ همین ترتیب را نشان داد).
     */
    public function authorize(): bool
    {
        $unit = $this->route('unit');

        return $unit instanceof Unit && UnitInformationController::mayAccess($this->user(), $unit);
    }

    protected function failedAuthorization(): never
    {
        abort(404);
    }

    protected function prepareForValidation(): void
    {
        $meters = $this->input('meters');
        if (is_array($meters)) {
            $this->merge(['meters' => array_map(fn ($v) => Digits::toLatin($v), $meters)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unit_type' => ['required', Rule::in(array_keys(UnitInformationController::UNIT_TYPES))],
            'block' => ['nullable', 'string', 'max:20'],
            'rooms_count' => ['nullable', 'integer', 'min:0', 'max:50'],
            'bedrooms_count' => ['nullable', 'integer', 'min:0', 'max:50'],
            'bathrooms_count' => ['nullable', 'integer', 'min:0', 'max:50'],
            'parking_numbers' => ['nullable', 'string', 'max:100'],
            'storage_numbers' => ['nullable', 'string', 'max:100'],
            'has_terrace' => ['nullable', 'boolean'],
            'has_balcony' => ['nullable', 'boolean'],
            'orientation' => ['nullable', Rule::in(array_keys(UnitInformationController::ORIENTATIONS))],
            'meters' => ['nullable', 'array:water,electricity,gas'],
            'meters.*' => ['nullable', 'string', 'max:30', 'regex:/^[0-9A-Za-z\-\/]*$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            ...array_fill_keys(self::BILLING_FIELDS, ['prohibited']),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'unit_type' => 'نوعِ واحد', 'block' => 'بلوک', 'rooms_count' => 'تعدادِ اتاق', 'bedrooms_count' => 'تعدادِ خواب',
            'bathrooms_count' => 'تعدادِ سرویس', 'parking_numbers' => 'شماره‌ی پارکینگ', 'storage_numbers' => 'شماره‌ی انباری',
            'has_terrace' => 'تراس', 'has_balcony' => 'بالکن', 'orientation' => 'جهت', 'meters' => 'کنتورها',
            'description' => 'توضیحات',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_fill_keys(
            array_map(fn (string $f) => $f.'.prohibited', self::BILLING_FIELDS),
            'این مشخصه ورودیِ شارژ است و فقط مدیر از فرمِ واحد عوضش می‌کند.',
        );
    }
}
