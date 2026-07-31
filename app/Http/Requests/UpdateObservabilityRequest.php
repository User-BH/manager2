<?php

namespace App\Http\Requests;

/**
 * شناسه‌های پایش و تحلیل.
 *
 * از `Api/System/ObservabilityController.php::update()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class UpdateObservabilityRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sentry_dsn' => ['nullable', 'string', 'max:500'],
            'sentry_client_dsn' => ['nullable', 'string', 'max:500'],
            'sentry_environment' => ['nullable', 'string', 'max:50'],
            'sentry_traces_sample_rate' => ['nullable', 'numeric', 'between:0,1'],
            'sentry_auth_token' => ['nullable', 'string', 'max:500'],
            'ga4_measurement_id' => ['nullable', 'string', 'max:50', 'regex:/^G-[A-Z0-9]+$/i'],
            'ga4_api_secret' => ['nullable', 'string', 'max:200'],
            'gtm_container_id' => ['nullable', 'string', 'max:50', 'regex:/^GTM-[A-Z0-9]+$/i'],
            'clarity_project_id' => ['nullable', 'string', 'max:50', 'alpha_num'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ga4_measurement_id.regex' => 'شناسه‌ی GA4 باید با G- شروع شود، مثل G-XXXXXXXXXX.',
            'gtm_container_id.regex' => 'شناسه‌ی GTM باید با GTM- شروع شود، مثل GTM-XXXXXXX.',
            'clarity_project_id.alpha_num' => 'شناسه‌ی Clarity فقط حرف و عدد است.',
        ];
    }
}
