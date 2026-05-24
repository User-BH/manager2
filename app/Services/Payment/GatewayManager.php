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
            'fake' => new FakeGateway,
            // 'mellat' => new MellatGateway($complex->gateway_config ?? []),
            // 'saman'  => new SamanGateway($complex->gateway_config ?? []),
            default => throw new RuntimeException('درگاه پرداخت برای این مجتمع فعال نیست. از آپلود رسید استفاده کنید.'),
        };
    }

    public function isOnlineEnabled(Complex $complex): bool
    {
        return in_array($complex->payment_gateway, ['fake', 'mellat', 'saman'], true);
    }
}
