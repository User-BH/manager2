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
            /*
             * DSN باید **آدرس** باشد، نه هر رشته‌ای.
             *
             * پیش از R17 فقط `string|max:500` بود، و برخلاف GA4/GTM/Clarity که
             * الگوی سخت‌گیرانه داشتند، اینجا هر چیزی پذیرفته می‌شد. چون این
             * مقدار در `<head>` هر صفحه‌ی عمومی چاپ می‌شود، یک رشته‌ی حاوی
             * `</script>` به XSSِ ماندگار روی کلِ سایت تبدیل می‌شد.
             *
             * حالا دو سد وجود دارد: همین‌جا (مبدأ) و `Json::forScript` (مقصد).
             */
            'sentry_dsn' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'sentry_client_dsn' => ['nullable', 'string', 'max:500', 'url:http,https'],

            // در نامِ محیط فقط حرف، عدد، خط تیره و زیرخط معنا دارد
            'sentry_environment' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
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
            'sentry_dsn.url' => 'DSN باید یک آدرس معتبر باشد، مثل https://key@o0.ingest.sentry.io/0',
            'sentry_client_dsn.url' => 'DSN باید یک آدرس معتبر باشد، مثل https://key@o0.ingest.sentry.io/0',
            'sentry_environment.regex' => 'نام محیط فقط حرف، عدد، خط تیره و زیرخط می‌پذیرد.',
            'ga4_measurement_id.regex' => 'شناسه‌ی GA4 باید با G- شروع شود، مثل G-XXXXXXXXXX.',
            'gtm_container_id.regex' => 'شناسه‌ی GTM باید با GTM- شروع شود، مثل GTM-XXXXXXX.',
            'clarity_project_id.alpha_num' => 'شناسه‌ی Clarity فقط حرف و عدد است.',
        ];
    }
}
