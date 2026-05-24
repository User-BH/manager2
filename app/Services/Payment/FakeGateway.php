<?php

namespace App\Services\Payment;

use App\Models\Payment;
use Illuminate\Support\Str;

/**
 * Local/sandbox gateway used when no real PSP is configured. It simulates the
 * bank round-trip so the whole online-payment flow is exercisable end to end
 * without external network. Swap for Mellat/Saman drivers in production.
 */
class FakeGateway implements PaymentGateway
{
    public function request(Payment $payment): array
    {
        $refId = 'FAKE-'.Str::upper(Str::random(12));
        $payment->update(['gateway' => 'fake', 'ref_id' => $refId]);

        return [
            'redirect_url' => route('payments.callback', ['payment' => $payment->id, 'ref' => $refId]),
            'ref_id' => $refId,
        ];
    }

    public function verify(Payment $payment, array $callback): ?string
    {
        // The sandbox approves any callback that echoes the issued ref id.
        if (($callback['ref'] ?? null) === $payment->ref_id) {
            return 'TRK-'.Str::upper(Str::random(10));
        }

        return null;
    }
}
