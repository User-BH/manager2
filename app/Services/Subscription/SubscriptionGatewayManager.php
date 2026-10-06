<?php

namespace App\Services\Subscription;

use App\Services\Payment\MellatGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\SamanGateway;
use App\Services\Payment\Sandbox;
use App\Services\Payment\ZarinpalGateway;
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
    private const SUPPORTED = ['sandbox', 'mellat', 'saman', 'zarinpal'];

    public function driver(): PaymentGateway
    {
        $config = (array) config('subscription.config', []);

        // مبلغ پلن‌ها به تومان تعریف شده؛ درایور خودش به ریال تبدیل می‌کند.
        return match (config('subscription.gateway')) {
            'sandbox' => Sandbox::gateway(),
            'mellat' => new MellatGateway($config, 'toman'),
            'saman' => new SamanGateway($config, 'toman'),
            // درگاهِ سکو به انتخابِ کارفرما (مرحلهٔ ۹ · فاز ۴)
            'zarinpal' => new ZarinpalGateway($config, 'toman'),
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
        // زرین‌پال با شناسه‌ی پذیرنده کار می‌کند، نه شماره‌ی ترمینال
        if (config('subscription.gateway') === 'zarinpal') {
            return filled(config('subscription.config.merchant_id'));
        }

        if (config('subscription.gateway') !== 'sandbox') {
            return filled(config('subscription.config.terminal_id'));
        }

        // سندباکس اشتراک هم روی سرور واقعی یعنی اشتراک پرو مجانی
        return Sandbox::isAllowed();
    }
}
