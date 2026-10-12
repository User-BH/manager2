<?php

namespace App\Http\Requests;

/**
 * گامِ دومِ ثبت‌نام — تنها جایی که حساب واقعاً ساخته می‌شود.
 *
 * از `Api/AuthController.php::registerVerify()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class RegisterVerifyRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string'],
            /*
             * توکنِ پیوندِ دعوت، وقتی ثبت‌نام از همان پیوند آمده (مرحلهٔ ۱۰ · فاز ۵؛ بندِ ۹۰). اختیاری و
             * بی‌اثر بر خودِ ثبت‌نام: سرور خودش می‌سنجد که دعوت زنده و برای همین شماره است.
             */
            'invitation_token' => ['nullable', 'string', 'max:128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['code' => 'کد'];
    }
}
