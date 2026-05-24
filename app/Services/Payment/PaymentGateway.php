<?php

namespace App\Services\Payment;

use App\Models\Payment;

/**
 * Contract every payment gateway driver implements. Real drivers
 * (Mellat/be-pardakht, Saman/SEP) follow the same two-step flow:
 *  1. request(): get a redirect URL / token to send the user to the bank.
 *  2. verify(): confirm the transaction on the bank callback.
 */
interface PaymentGateway
{
    /** @return array{redirect_url:string,ref_id:string} */
    public function request(Payment $payment): array;

    /** Verify a bank callback; returns the tracking code on success or null. */
    public function verify(Payment $payment, array $callback): ?string;
}
