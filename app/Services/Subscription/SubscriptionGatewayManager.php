<?php

namespace App\Services\Subscription;

use RuntimeException;

class SubscriptionGatewayManager
{
    public function driver(): SubscriptionGateway
    {
        return match (config('subscription.gateway')) {
            'sandbox' => new SandboxSubscriptionGateway,
            default => throw new RuntimeException(
                'درگاه پرداخت اشتراک هنوز فعال نشده است. برای خرید با پشتیبانی '
                .config('subscription.support_phone').' تماس بگیرید.'
            ),
        };
    }

    public function isEnabled(): bool
    {
        return in_array(config('subscription.gateway'), ['sandbox'], true);
    }
}
