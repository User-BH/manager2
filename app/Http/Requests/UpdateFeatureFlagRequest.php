<?php

namespace App\Http\Requests;

/**
 * روشن یا خاموش‌کردنِ یک پرچمِ قابلیت (R44).
 */
class UpdateFeatureFlagRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * ⚠️ `boolean` است و نه `required|in:0,1`.
             *
             * `false`، `0` و `'0'` هر سه مقدارِ معتبرِ «خاموش» هستند و با
             * `required` تنها، مقدارِ `false` رد می‌شد — یعنی خاموش‌کردنِ
             * پرچم از رابطِ کاربری کار نمی‌کرد. `boolean` هر شش شکلِ
             * پذیرفته را می‌گیرد و `present` جای `required` می‌نشیند تا
             * نبودِ کلید همچنان خطا باشد.
             */
            'enabled' => ['present', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'enabled.present' => 'وضعیتِ روشن یا خاموش مشخص نشده است.',
            'enabled.boolean' => 'وضعیت باید روشن یا خاموش باشد.',
        ];
    }
}
