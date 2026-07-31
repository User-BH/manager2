<?php

namespace App\Http\Requests;

/**
 * بازیابیِ بکاپ — مخرب‌ترین عملیاتِ سامانه.
 *
 * از `Api/System/BackupController.php::restore()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class RestoreBackupRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'backup' => ['required', 'file', 'mimes:json,txt', 'max:20480'],
            'dry_run' => ['nullable', 'boolean'],
            'confirm' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'backup.max' => 'حجم فایل بکاپ نباید از ۲۰ مگابایت بیشتر باشد.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['backup' => 'فایل بکاپ'];
    }
}
