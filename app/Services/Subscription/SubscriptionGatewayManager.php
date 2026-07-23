<?php

namespace App\Services\Subscription;

use App\Services\Payment\FakeGateway;
use App\Services\Payment\MellatGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\SamanGateway;
use RuntimeException;

/**
 * درگاه پرداختِ اشتراک.
 *
 * برخلاف درگاه شارژ که برای هر مجتمع جدا تنظیم می‌شود، اینجا یک درگاه
 * سراسری داریم: پول اشتراک به حساب سرویس‌دهنده می‌رود، نه حساب مجتمع.
 * پیکربندی در `config/subscription.php` است.
 *
 * درایورها همان درایورهای بانکیِ شارژ هستند؛ چون هر دو با واسط
 * `GatewayOrder` کار می‌کنند، هیچ کد بانکیِ تکراری لازم نشد.
 */
class SubscriptionGatewayManager
{
    /** درگاه‌هایی که واقعاً درایور دارند. */
    private const SUPPORTED = ['sandbox', 'mellat', 'saman'];

    public function driver(): PaymentGateway
    {
        $config = (array) config('subscription.config', []);

        // مبلغ پلن‌ها به تومان تعریف شده؛ درایور خودش به ریال تبدیل می‌کند.
        return match (config('subscription.gateway')) {
            'sandbox' => new FakeGateway,
            'mellat' => new MellatGateway($config, 'toman'),
            'saman' => new SamanGateway($config, 'toman'),
            default => throw new RuntimeException(
                'درگاه پرداخت اشتراک هنوز فعال نشده است. می‌توانید با «واریز و آپلود رسید» '
                .'خرید کنید یا با پشتیبانی '.config('subscription.support_phone').' تماس بگیرید.'
            ),
        };
    }

    public function isEnabled(): bool
    {
        if (! in_array(config('subscription.gateway'), self::SUPPORTED, true)) {
            return false;
        }

        // درگاه واقعی بدون شماره ترمینال کار نمی‌کند؛ بهتر است دکمه‌ی پرداخت
        // اصلاً نیاید تا اینکه کاربر وسط راه به خطای بانک بخورد.
        if (config('subscription.gateway') !== 'sandbox') {
            return filled(config('subscription.config.terminal_id'));
        }

        return true;
    }
}
