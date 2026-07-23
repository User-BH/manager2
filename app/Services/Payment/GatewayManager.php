<?php

namespace App\Services\Payment;

use App\Models\Complex;
use RuntimeException;

/**
 * Resolves the active gateway driver for a complex. Real PSP drivers
 * (MellatGateway, SamanGateway) plug in here once credentials exist in
 * the complex's gateway_config; until then only the sandbox driver runs.
 */
class GatewayManager
{
    public function for(Complex $complex): PaymentGateway
    {
        return match ($complex->payment_gateway) {
            // سندباکس فقط بیرون از production؛ وگرنه تسویه‌ی قبض بدون پول
            'fake' => Sandbox::gateway(),
            'mellat' => new MellatGateway($complex->gateway_config ?? [], $complex->currency),
            'saman' => new SamanGateway($complex->gateway_config ?? [], $complex->currency),
            default => throw new RuntimeException('درگاه پرداخت برای این مجتمع فعال نیست. از آپلود رسید استفاده کنید.'),
        };
    }

    /**
     * آیا دکمه‌ی «پرداخت آنلاین» باید به کاربر نشان داده شود؟
     *
     * مجتمعی که پیش از این روی سندباکس تنظیم شده و حالا روی سرور واقعی
     * بالا آمده، اینجا false می‌گیرد و فقط مسیر رسید را می‌بیند — بهتر از
     * اینکه دکمه بیاید و وسط راه به خطا بخورد.
     */
    public function isOnlineEnabled(Complex $complex): bool
    {
        if ($complex->payment_gateway === 'fake') {
            return Sandbox::isAllowed();
        }

        return in_array($complex->payment_gateway, ['mellat', 'saman'], true);
    }
}
