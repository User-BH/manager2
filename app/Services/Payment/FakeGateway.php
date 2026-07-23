<?php

namespace App\Services\Payment;

use Illuminate\Support\Str;

/**
 * Local/sandbox gateway used when no real PSP is configured. It simulates the
 * bank round-trip so the whole online-payment flow is exercisable end to end
 * without external network. Swap for Mellat/Saman drivers in production.
 */
class FakeGateway implements PaymentGateway
{
    public function request(GatewayOrder $order): array
    {
        $refId = 'FAKE-'.Str::upper(Str::random(12));
        $order->markGatewayRequested('fake', $refId);

        return [
            'redirect_url' => $order->gatewayCallbackUrl().'?ref='.$refId,
            'ref_id' => $refId,
        ];
    }

    public function verify(GatewayOrder $order, array $callback): ?string
    {
        // The sandbox approves any callback that echoes the issued ref id.
        if (($callback['ref'] ?? null) === $order->gatewayRefId()) {
            return 'TRK-'.Str::upper(Str::random(10));
        }

        return null;
    }
}
