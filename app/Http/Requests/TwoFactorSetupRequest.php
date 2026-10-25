<?php

namespace App\Http\Requests;

/**
 * کلیدِ تازه‌ی Authenticator. کدِ فعلی فقط برای **تعویض** لازم است (وقتی TOTP از قبل فعال است)؛ آن‌جا را کنترلر
 * می‌سنجد، چون الزامش به وضعیتِ حساب بسته است نه به شکلِ درخواست (۲۰۲۶-۱۰-۲۵).
 */
class TwoFactorSetupRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:16'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['code' => 'کدِ برنامه'];
    }
}
