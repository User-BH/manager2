<?php

namespace App\Http\Requests;

use App\Services\Payment\Sandbox;
use Illuminate\Validation\Rule;

/**
 * تنظیماتِ مجتمع.
 *
 * از `Api/SettingController.php::update()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class UpdateComplexSettingsRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'currency' => ['required', 'in:toman,rial'],
            'charge_due_day' => ['required', 'integer', 'min:1', 'max:31'],
            'payment_gateway' => [
                'required',
                Rule::in(array_filter(['none', $this->sandboxAcceptable() ? 'fake' : null, 'mellat', 'saman'])),
            ],
            'gw_terminal_id' => ['nullable', 'string', 'max:50'],
            'gw_username' => ['nullable', 'string', 'max:100'],
            'gw_password' => ['nullable', 'string', 'max:100'],
            'messenger_enabled' => ['nullable', 'boolean'],
            'good_payer_enabled' => ['nullable', 'boolean'],
            'penalty_enabled' => ['nullable', 'boolean'],
            'penalty_type' => ['required', 'in:fixed,percent,percent_per_day'],
            'penalty_value' => ['required', 'numeric', 'min:0'],
            'penalty_grace_days' => ['required', 'integer', 'min:0', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_gateway.in' => 'درگاه آزمایشی روی سرور واقعی مجاز نیست؛ درگاه بانکی واقعی را انتخاب کنید یا «بدون درگاه» را بگذارید.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'نام مجتمع',
            'currency' => 'واحد پول',
            'charge_due_day' => 'روز سررسید',
            'payment_gateway' => 'درگاه پرداخت',
            'penalty_type' => 'نوع جریمه',
            'penalty_value' => 'مقدار جریمه',
            'penalty_grace_days' => 'روزهای مهلت',
        ];
    }

    /**
     * سندباکس روی سرورِ واقعی پذیرفته نمی‌شود.
     *
     * بررسی اینجاست و نه فقط در فهرستِ گزینه‌ها، چون فهرست فقط رابطِ کاربری را
     * می‌سازد و درخواستِ مستقیم به سرور آن را دور می‌زد.
     *
     * تنها استثنا: مجتمعی که از قبل روی سندباکس مانده می‌تواند بقیه‌ی
     * تنظیماتش را ذخیره کند بی‌آنکه فرم قفل شود. خودِ درگاه به‌هرحال در
     * `GatewayManager` مسدود است، پس این استثنا پرداختی را باز نمی‌کند.
     */
    private function sandboxAcceptable(): bool
    {
        return Sandbox::isAllowed() || $this->currentComplex()?->payment_gateway === 'fake';
    }
}
